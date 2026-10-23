<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_has_no_registration_and_guests_cannot_access_crm(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Kata sandi')->assertDontSee('Register')->assertDontSee('Registrasi');
        $this->assertFalse(Route::has('register'));
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
        foreach (['candidates.index', 'companies.index', 'job-orders.index', 'vacancies.index', 'applications.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $application = Application::factory()->create();
        $this->patch(route('applications.update-status', $application), ['status' => 'closed'])->assertRedirect(route('login'));
        $this->assertSame('active', $application->fresh()->status);
    }

    public function test_user_can_login_and_return_to_intended_page(): void
    {
        $user = User::factory()->create(['password' => Hash::make('kata-sandi-rahasia')]);
        $this->get(route('applications.index'))->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'kata-sandi-rahasia', 'remember' => 1])->assertSessionHasNoErrors()->assertRedirect(route('auth.challenge'));
        $this->assertGuest();
        $this->completeStaffMfa($user)->assertRedirect(route('auth.recovery-codes'));
        $this->assertSame(route('applications.index'), session('auth.after_recovery'));
        $this->assertAuthenticatedAs($user);
        $this->get(route('login'))->assertRedirect(route('dashboard'));
        $this->get(route('applications.index'))->assertOk()->assertSee('Keluar');
    }

    public function test_invalid_credentials_do_not_authenticate_and_password_is_not_flashed(): void
    {
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'salah'])->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => 'bukan email', 'password' => ''])->assertSessionHasErrors(['email', 'password']);
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'salah'])->assertSessionHasErrors('email');
        }
        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Terlalu banyak percobaan', session('errors')->first('email'));
        $this->assertGuest();
        $this->travel(config('security.throttle.decay') + 1)->seconds();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->completeStaffMfa($user);
        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_invalidates_session_and_requires_login_again(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['private_data' => 'rahasia'])->post(route('logout'))->assertRedirect(route('login'))->assertSessionMissing('private_data');
        $this->assertGuest();
        $this->get(route('candidates.index'))->assertRedirect(route('login'));
        $this->get(route('logout'))->assertStatus(405);
    }
}
