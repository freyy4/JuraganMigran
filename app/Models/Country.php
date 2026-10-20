<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'is_active', 'region', 'flag'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function jobOrders(): HasMany
    {
        return $this->hasMany(JobOrder::class);
    }

    public function scopeAvailableForSelection(Builder $query, ?int $currentId = null): void
    {
        $query->where(function (Builder $query) use ($currentId): void {
            $query->where('is_active', true);
            if ($currentId !== null) {
                $query->orWhereKey($currentId);
            }
        });
    }

    public function partnerCompanies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_countries');
    }

    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class);
    }
}
