<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessPolicy;
use Database\Seeders\LoginUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_login_account_without_resetting_existing_password(): void
    {
        config(['login_seed.email' => 'admin@example.test', 'login_seed.password' => 'kata-sandi-pengujian']);
        $this->seed(LoginUserSeeder::class);
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('kata-sandi-pengujian', $user->password));
        config(['login_seed.password' => 'kata-sandi-baru']);
        $this->seed(LoginUserSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue(Hash::check('kata-sandi-pengujian', $user->fresh()->password));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'kata-sandi-pengujian'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->get(route('candidates.index'))->assertOk();
        $response = $this->get(route('dashboard'))->assertOk()->assertSee('Dashboard Superadmin')->assertViewIs('cockpit.menu')->assertViewHas('groups', fn ($groups) => collect($groups)->flatMap(fn ($group) => $group['items'])->contains('route', 'health-templates.index') && ! collect($groups)->flatMap(fn ($group) => $group['items'])->contains('route', 'health-screenings.index'))->assertDontSee('Pengguna aktif')->assertDontSee('Tindakan konfigurasi');
        foreach (config('navigation') as $group) {
            foreach ($group['items'] as $item) {
                $permission = app(AccessPolicy::class)->routePermission($item['route']);
                if ($permission && ! $user->hasPermission($permission)) {
                    continue;
                }
                $response->assertSee(route($item['route'], array_merge($item['parameters'] ?? [], $group['label'] === 'Dasbor' ? [] : ['menu' => $item['key']])));
            }
        }
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
