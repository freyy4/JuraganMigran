<?php

namespace Tests;

use App\Models\User;
use App\Services\AuthenticationSecurity;
use App\Services\TotpAuthenticator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /** Simulate a completed, verified login for authorization tests that use actingAs. */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);
        if ($user instanceof User && Schema::hasTable('auth_session_records')) {
            $user->refresh();
            $record = bin2hex(random_bytes(24));
            $portal = app(AuthenticationSecurity::class)->isPortal($user);
            $this->withSession(['security' => ['user_id' => $user->id, 'audience' => $portal ? 'cpmi' : 'staff', 'mfa' => ! $portal, 'started_at' => now()->timestamp, 'last_active_at' => now()->timestamp, 'reauth_at' => now()->timestamp, 'version' => $user->auth_version, 'fingerprint' => app(AuthenticationSecurity::class)->fingerprint($user), 'record' => $record]]);
            DB::table('auth_session_records')->insert(['id' => $record, 'user_id' => $user->id, 'session_hash' => hash('sha256', session()->getId()), 'device_label' => 'PHPUnit verified session', 'ip_address' => '127.0.0.1', 'authenticated_at' => now(), 'last_active_at' => now()]);
        }

        return $this;
    }

    protected function trustPortalDevice(User $user): void
    {
        $token = bin2hex(random_bytes(32));
        DB::table('auth_devices')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'user_agent' => 'PHPUnit portal device', 'last_seen_at' => now(), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withCredentials()->withHeader('User-Agent', 'PHPUnit portal device')->withCookie('jm_auth_device', $token);
    }

    protected function completeStaffMfa(User $user): TestResponse
    {
        $this->get(route('auth.challenge'))->assertOk();
        $factor = DB::table('auth_factors')->where('user_id', $user->id)->first();
        $code = app(TotpAuthenticator::class)->code(Crypt::decryptString($factor->secret), now()->timestamp);

        return $this->post(route('auth.challenge.verify'), ['code' => $code]);
    }
}
