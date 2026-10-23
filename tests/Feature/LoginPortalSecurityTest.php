<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AuthenticationSecurity;
use App\Services\AuthenticationThrottle;
use App\Services\TotpAuthenticator;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LoginPortalSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function portalAccount(): User
    {
        $application = Application::factory()->create();
        $user = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cpmi')->value('id')]);
        DB::table('portal_accounts')->insert(['candidate_id' => $application->candidate_id, 'user_id' => $user->id, 'normalized_phone' => '+6281234567890', 'status' => 'active', 'phone_verified_at' => now(), 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    public function test_login_shells_are_separate_lightweight_and_have_secure_headers(): void
    {
        foreach (['login' => 'Selamat Datang', 'portal.login' => 'Selamat Datang CPMI'] as $route => $heading) {
            $response = $this->get(route($route))->assertOk()->assertSee($heading)->assertSee('authentication.css')->assertDontSee('portal-react.js')->assertDontSee('theme.js')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
            $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        }
        $this->get(route('password.request'))->assertOk();
        if ($directory = getenv('AUTH_QA_FIXTURES')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/staff.html', $this->get(route('login'))->getContent());
            file_put_contents($directory.'/cpmi.html', $this->get(route('portal.login'))->getContent());
            file_put_contents($directory.'/forgot.html', $this->get(route('password.request'))->getContent());
        }
    }

    public function test_staff_password_does_not_authenticate_until_totp_enrollment_is_confirmed(): void
    {
        $user = User::factory()->create();
        $oldSession = session()->getId();
        $this->postJson(route('auth.staff.login'), ['email' => $user->email, 'password' => 'password'])->assertOk()->assertJsonPath('mfaRequired', true)->assertJsonPath('enrollmentRequired', true);
        $this->assertGuest();
        $this->get(route('candidates.index'))->assertRedirect(route('login'));
        if ($directory = getenv('AUTH_QA_FIXTURES')) {
            file_put_contents($directory.'/mfa.html', $this->get(route('auth.challenge'))->assertOk()->getContent());
        }
        $this->completeStaffMfa($user)->assertRedirect(route('auth.recovery-codes'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        $factor = DB::table('auth_factors')->where('user_id', $user->id)->first();
        $this->assertNotNull($factor->confirmed_at);
        $this->assertCount(10, json_decode($factor->recovery_hashes, true));
        $this->get(route('auth.recovery-codes'))->assertOk()->assertSee('Simpan kode pemulihan');
        $this->get(route('auth.recovery-codes'))->assertNotFound();
        $this->get(route('candidates.index'))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.mfa.added']);
    }

    public function test_routine_mfa_never_discloses_secret_and_codes_cannot_be_replayed(): void
    {
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->completeStaffMfa($user);
        $factor = DB::table('auth_factors')->where('user_id', $user->id)->first();
        $secret = Crypt::decryptString($factor->secret);
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('auth.challenge'));
        $this->get(route('auth.challenge'))->assertOk()->assertDontSee($secret)->assertDontSee('Kunci pengaturan');
        $code = app(TotpAuthenticator::class)->code($secret, now()->timestamp);
        $this->postJson(route('auth.staff.mfa.verify'), ['code' => $code])->assertUnprocessable();
        $this->assertGuest();
        $this->travel(31)->seconds();
        $code = app(TotpAuthenticator::class)->code($secret, now()->timestamp);
        $this->postJson(route('auth.staff.mfa.verify'), ['code' => $code])->assertOk()->assertJsonPath('ok', true);
        $this->assertAuthenticatedAs($user);
        $this->postJson(route('auth.staff.mfa.verify'), ['code' => $code])->assertForbidden();
    }

    public function test_pending_mfa_is_session_bound_expiring_and_attempt_limited(): void
    {
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->withSession(['auth.pending.nonce' => 'different-session']);
        $this->get(route('auth.challenge'))->assertForbidden();
        $this->withSession(['auth.pending' => null]);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->travel(11)->minutes();
        $this->get(route('auth.challenge'))->assertForbidden();
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        for ($attempt = 0; $attempt < config('security.challenge_attempts'); $attempt++) {
            $this->postJson(route('auth.challenge.verify'), ['code' => '000000'])->assertUnprocessable();
            $this->travel(5)->seconds();
        }
        $this->get(route('auth.challenge'))->assertForbidden();
        $this->assertGuest();
    }

    public function test_recovery_code_is_hashed_and_single_use(): void
    {
        $user = User::factory()->create();
        $security = app(AuthenticationSecurity::class);
        $factor = $security->enrollment($user);
        DB::table('auth_factors')->where('id', $factor->id)->update(['confirmed_at' => now()]);
        $codes = $security->recoveryCodes($user);
        $stored = DB::table('auth_factors')->where('id', $factor->id)->value('recovery_hashes');
        $this->assertStringNotContainsString($codes[0], $stored);
        $this->assertTrue($security->verifyFactor($user, $codes[0]));
        $this->assertFalse($security->verifyFactor($user, $codes[0]));
        $this->assertCount(9, json_decode(DB::table('auth_factors')->where('id', $factor->id)->value('recovery_hashes'), true));
    }

    public function test_account_and_ip_limits_stop_distributed_and_multi_account_guessing(): void
    {
        config(['security.throttle.account' => 2, 'security.throttle.ip' => 3, 'security.throttle.backoff_after' => 20]);
        $user = User::factory()->create();
        foreach (['10.0.0.1', '10.0.0.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(route('auth.staff.login'), ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->postJson(route('auth.staff.login'), ['email' => $user->email, 'password' => 'password'])->assertStatus(429)->assertJsonPath('message', AuthenticationThrottle::MESSAGE);
        foreach (['a@example.test', 'b@example.test', 'c@example.test'] as $email) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])->postJson(route('auth.staff.login'), ['email' => $email, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson(route('auth.staff.login'), ['email' => 'd@example.test', 'password' => 'wrong'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_portal_accepts_normalized_verified_whatsapp_and_requires_new_device_verification(): void
    {
        $user = $this->portalAccount();
        $this->postJson(route('auth.cpmi.login'), ['identifier' => '0812 3456 7890', 'password' => 'password'])->assertOk()->assertJsonPath('mfaRequired', true)->assertJsonPath('next', '/auth/challenge');
        $this->assertGuest();
        $challenge = DB::table('auth_challenges')->where('user_id', $user->id)->first();
        $this->assertNotNull($challenge->code_hash);
        DB::table('auth_challenges')->where('id', $challenge->id)->update(['code_hash' => hash_hmac('sha256', '123456', config('app.key'))]);
        $this->postJson(route('auth.challenge.verify'), ['code' => '123456'])->assertOk()->assertJsonPath('next', route('portal.home'));
        $this->assertAuthenticatedAs($user);
        $this->get(route('candidates.index'))->assertForbidden();
    }

    public function test_known_portal_device_login_has_no_routine_mfa(): void
    {
        $user = $this->portalAccount();
        $token = str_repeat('a', 64);
        DB::table('auth_devices')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'user_agent' => 'Test browser', 'last_seen_at' => now(), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withCredentials()->withCookie('jm_auth_device', $token)->withHeader('User-Agent', 'Test browser')->postJson(route('auth.cpmi.login'), ['identifier' => $user->email, 'password' => 'password'])->assertOk()->assertJsonPath('next', '/portal')->assertJsonMissingPath('mfaRequired');
        $this->assertAuthenticatedAs($user);
    }

    public function test_cross_audience_credentials_and_nonexistent_identifiers_are_generic(): void
    {
        $staff = User::factory()->create();
        $portal = $this->portalAccount();
        $this->postJson(route('auth.staff.login'), ['email' => $portal->email, 'password' => 'password'])->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $unactivated = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cpmi')->value('id')]);
        $this->postJson(route('auth.staff.login'), ['email' => $unactivated->email, 'password' => 'password'])->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $known = $this->postJson(route('auth.cpmi.login'), ['identifier' => $staff->email, 'password' => 'password'])->assertUnauthorized();
        $missing = $this->postJson(route('auth.cpmi.login'), ['identifier' => 'absent@example.test', 'password' => 'password'])->assertUnauthorized();
        $this->assertSame($known->json(), $missing->json());
    }

    public function test_server_side_idle_absolute_revocation_and_role_changes_end_sessions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['security.last_active_at' => now()->subMinutes(31)->timestamp])->getJson(route('candidates.index'))->assertUnauthorized();
        $this->actingAs($user)->withSession(['security.started_at' => now()->subHours(9)->timestamp])->getJson(route('candidates.index'))->assertUnauthorized();
        $this->actingAs($user);
        DB::table('auth_session_records')->where('id', session('security.record'))->update(['revoked_at' => now()]);
        $this->getJson(route('candidates.index'))->assertUnauthorized();
        $this->actingAs($user);
        $user->update(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]);
        $this->getJson(route('candidates.index'))->assertUnauthorized();
    }

    public function test_expired_authenticated_cookie_does_not_block_public_pages(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['security.last_active_at' => now()->subHours(2)->timestamp]);
        $this->get(route('privacy'))->assertOk();
        $this->assertGuest();
    }

    public function test_high_risk_actions_need_recent_password_and_mfa_reauthentication(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['security.reauth_at' => now()->subMinutes(11)->timestamp]);
        $this->postJson(route('auth.sessions.revoke'))->assertForbidden()->assertJsonPath('code', 'REAUTH_REQUIRED');
        $this->postJson(route('auth.confirm'), ['password' => 'password', 'code' => '000000'])->assertUnprocessable();
        $this->assertDatabaseCount('auth_factors', 0);
        $factor = app(AuthenticationSecurity::class)->enrollment($user);
        DB::table('auth_factors')->where('id', $factor->id)->update(['confirmed_at' => now()]);
        $code = app(TotpAuthenticator::class)->code(Crypt::decryptString($factor->secret), now()->timestamp);
        $this->postJson(route('auth.confirm'), ['password' => 'password', 'code' => $code])->assertOk();
        $this->post(route('auth.sessions.revoke'))->assertSessionHasNoErrors();
    }

    public function test_recovery_token_is_hashed_expiring_single_use_and_preserves_mfa(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        app(AuthenticationSecurity::class)->enrollment($user);
        $token = str_repeat('b', 64);
        DB::table('auth_reset_tokens')->insert(['user_id' => $user->id, 'audience' => 'staff', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(20), 'created_at' => now()]);
        $this->get(route('password.reset', $token))->assertOk();
        $payload = ['token' => $token, 'password' => 'frasa-sandi-aman-unik-2026', 'password_confirmation' => 'frasa-sandi-aman-unik-2026'];
        $this->post(route('password.update'), $payload)->assertRedirect(route('login'));
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertDatabaseCount('auth_factors', 1);
        $this->get(route('password.reset', $token))->assertNotFound();
        $this->post(route('password.update'), $payload)->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset.completed']);
    }

    public function test_no_smtp_recovery_is_assisted_and_never_logs_or_returns_a_reset_token(): void
    {
        config(['mail.default' => 'log']);
        $user = User::factory()->create();
        $this->get(route('password.request'))->assertOk()->assertSee('Pemulihan dibantu Admin')->assertDontSee('name="identifier"', false);
        $this->post(route('password.email'), ['audience' => 'staff', 'identifier' => $user->email])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseCount('auth_reset_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_reset.requested']);
    }

    public function test_security_notifications_and_session_lists_never_expose_raw_tokens_or_other_users(): void
    {
        $mine = User::factory()->create();
        $other = User::factory()->create(['name' => 'Other private person']);
        $this->actingAs($other);
        $this->actingAs($mine)->get(route('auth.sessions'))->assertOk()->assertDontSee('Other private person')->assertDontSee('session_hash');
        $this->get(route('settings.users.edit', $other))->assertOk()->assertSee(route('security.users.revoke', $other));
        $this->post(route('security.users.revoke', $other))->assertRedirect();
        $this->assertDatabaseHas('auth_session_records', ['user_id' => $other->id]);
        $this->assertSame(0, DB::table('auth_session_records')->where('user_id', $other->id)->whereNull('revoked_at')->count());
    }

    public function test_auth_endpoints_enforce_csrf_when_test_exemption_is_disabled(): void
    {
        $middleware = new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
        $this->app->instance(PreventRequestForgery::class, $middleware);
        foreach (['login.store', 'auth.staff.login', 'auth.cpmi.login', 'auth.staff.mfa.verify', 'password.email', 'password.update'] as $route) {
            $this->post(route($route), [])->assertStatus(419)->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_secure_cookie_flags_and_tokens_are_never_stored_in_browser_auth_storage(): void
    {
        $response = $this->get('https://juraganmigran.my.id/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertStringNotContainsString('localStorage', file_get_contents(public_path('js/authentication.js')));
        $this->assertStringNotContainsString('portal-react.js', file_get_contents(public_path('portal-sw.js')));
    }

    public function test_revocation_endpoint_cannot_be_used_by_non_administrators(): void
    {
        $cs = User::factory()->create(['crm_role_id' => DB::table('crm_roles')->where('code', 'cs')->value('id')]);
        $other = User::factory()->create();
        $this->actingAs($cs)->postJson(route('security.users.revoke', $other))->assertForbidden();
        $this->assertSame(0, $other->fresh()->auth_version);
    }

    public function test_contact_changes_revoke_old_access_and_require_cs_verification(): void
    {
        $user = $this->portalAccount();
        $this->actingAs($user);
        $account = DB::table('portal_accounts')->where('user_id', $user->id)->first();
        $person = Candidate::withoutGlobalScope('access')->findOrFail($account->candidate_id)->person;
        $person->update(['whatsapp' => '089999888777', 'email' => 'changed@example.test']);
        $this->assertDatabaseHas('portal_accounts', ['user_id' => $user->id, 'status' => 'verification_required', 'phone_verified_at' => null]);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->getJson(route('portal.api.summary'))->assertUnauthorized();
        $this->postJson(route('auth.cpmi.login'), ['identifier' => '081234567890', 'password' => 'password'])->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_external_or_portal_intended_redirect_is_ignored_for_staff(): void
    {
        $user = User::factory()->create();
        $request = request();
        $request->setLaravelSession(app('session.store'));
        foreach (['https://evil.example/path', '//evil.example', route('portal.home')] as $destination) {
            $request->session()->put('url.intended', $destination);
            $this->assertSame($user->crmHomeUrl(), app(AuthenticationSecurity::class)->destination($request, $user, 'staff'));
        }
    }
}
