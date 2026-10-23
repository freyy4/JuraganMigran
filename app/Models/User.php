<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\AccessPolicy;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password', 'crm_role_id', 'is_active', 'whatsapp', 'team_id'])]
#[Hidden(['password', 'remember_token', 'auth_version'])]
class User extends Authenticatable
{
    protected $attributes = ['is_active' => true];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'auth_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if ($user->isDirty(['password', 'email', 'crm_role_id', 'is_active'])) {
                $user->auth_version = ($user->getOriginal('auth_version') ?? 0) + 1;
            }
        });
    }

    public function hasPermission(string $permission): bool
    {
        return app(AccessPolicy::class)->has($this, $permission);
    }

    public function isCrmAdmin(): bool
    {
        return $this->hasPermission('admin.user.manage');
    }

    public function crmRole(): ?object
    {
        return $this->crm_role_id ? DB::table('crm_roles')->find($this->crm_role_id) : null;
    }

    public function canAccessCrmGroup(string $group): bool
    {
        foreach (config('navigation') as $navigationGroup) {
            if ($navigationGroup['label'] !== $group) {
                continue;
            }
            foreach ($navigationGroup['items'] as $item) {
                $permission = app(AccessPolicy::class)->routePermission($item['route']);
                if ($permission && $this->hasPermission($permission)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function crmHomeUrl(): string
    {
        if (DB::table('portal_accounts')->where('user_id', $this->id)->exists()) {
            return route('portal.home');
        }
        if ($this->hasPermission('dashboard.read')) {
            return route('dashboard');
        }
        foreach (config('navigation') as $group) {
            foreach ($group['items'] as $item) {
                $permission = app(AccessPolicy::class)->routePermission($item['route']);
                if ($permission && $this->hasPermission($permission)) {
                    return route($item['route'], $item['parameters'] ?? []);
                }
            }
        }

        return route('access.unavailable');
    }

    public function assignedApplications(): HasMany
    {
        return $this->hasMany(Application::class, 'assigned_cs_id');
    }
}
