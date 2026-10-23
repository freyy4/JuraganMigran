<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthenticationSecurity
{
    public const INVALID = 'Email/nomor WhatsApp atau kata sandi tidak sesuai.';

    public function isPortal(User $user): bool
    {
        return DB::table('portal_accounts')->where('user_id', $user->id)->exists();
    }

    public function verifiedPassword(?User $user, string $password): bool
    {
        $hash = $user?->password ?? config('security.dummy_hash', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');

        return Hash::check($password, $hash) && $user !== null;
    }

    public function normalize(string $identifier): string
    {
        $identifier = mb_strtolower(trim($identifier));
        if (str_contains($identifier, '@')) {
            return $identifier;
        }
        try {
            return CsRouter::phone($identifier);
        } catch (ValidationException) {
            return $identifier;
        }
    }

    public function portalUser(string $identifier): ?User
    {
        $account = DB::table('portal_accounts')->where('status', 'active')->whereNotNull('activated_at')->whereNotNull('phone_verified_at')
            ->whereIn('candidate_id', DB::table('candidates')->whereNull('deleted_at')->select('id'))
            ->when(str_contains($identifier, '@'), fn ($query) => $query->whereIn('user_id', User::where('email', $identifier)->whereNotNull('email_verified_at')->select('id')), fn ($query) => $query->where('normalized_phone', $identifier))->first();

        return $account ? User::whereKey($account->user_id)->where('is_active', true)->first() : null;
    }

    public function primary(Request $request, string $audience, string $identifier, string $password): array
    {
        $identifier = $this->normalize($identifier);
        $field = $audience === 'staff' ? 'email' : 'identifier';
        $throttle = app(AuthenticationThrottle::class);
        $throttle->ensure($request, $identifier, 'login:'.$audience, $field);
        $user = $audience === 'cpmi' ? $this->portalUser($identifier) : User::where('email', $identifier)->where('is_active', true)->first();
        $valid = $this->verifiedPassword($user, $password);
        if (! $valid || ($audience === 'staff' && $this->isPortal($user))) {
            $throttle->failure($request, $identifier, 'login:'.$audience);
            app(AccessPolicy::class)->audit(null, $audience.'.login.denied');
            throw ValidationException::withMessages([$field => $audience === 'staff' ? 'Email atau kata sandi tidak sesuai.' : self::INVALID]);
        }
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($password)])->saveQuietly();
        }
        $throttle->success($request, $identifier, 'login:'.$audience);
        if ($audience === 'cpmi' && $this->knownDevice($request, $user)) {
            $this->finish($request, $user, $audience);

            return ['ok' => true, 'next' => route('portal.home')];
        }
        Auth::logout();
        $request->session()->regenerate(true);
        $nonce = bin2hex(random_bytes(32));
        $challenge = bin2hex(random_bytes(32));
        $code = $audience === 'cpmi' ? (string) random_int(100000, 999999) : null;
        $request->session()->put('auth.pending', ['id' => $challenge, 'nonce' => $nonce]);
        DB::table('auth_challenges')->insert(['id' => $challenge, 'user_id' => $user->id, 'audience' => $audience, 'purpose' => $audience === 'staff' ? 'mfa' : 'new_device', 'session_binding' => hash('sha256', $nonce), 'code_hash' => $code ? hash_hmac('sha256', $code, config('app.key')) : null, 'expires_at' => now()->addMinutes(config('security.challenge_minutes')), 'created_at' => now()]);
        if ($audience === 'cpmi' && $user->email_verified_at) {
            $this->mail($user, 'Verifikasi perangkat Portal CPMI', 'Kode verifikasi perangkat Anda: '.$code.'. Kode berlaku '.config('security.challenge_minutes').' menit. Jangan bagikan kode ini.');
        }
        app(AccessPolicy::class)->audit($user, 'auth.challenge.started', ['audience' => $audience]);

        return ['ok' => true, 'mfaRequired' => true, 'challengeId' => $challenge, 'enrollmentRequired' => $audience === 'staff' && ! DB::table('auth_factors')->where('user_id', $user->id)->whereNotNull('confirmed_at')->exists(), 'next' => route('auth.challenge')];
    }

    public function pending(Request $request): object
    {
        $pending = $request->session()->get('auth.pending');
        $challenge = is_array($pending) ? DB::table('auth_challenges')->where('id', $pending['id'] ?? '')->where('session_binding', hash('sha256', $pending['nonce'] ?? ''))->whereNull('consumed_at')->where('expires_at', '>', now())->where('attempts', '<', config('security.challenge_attempts'))->first() : null;
        abort_unless($challenge && User::whereKey($challenge->user_id)->where('is_active', true)->exists(), 403, 'Verifikasi berakhir. Silakan masuk kembali.');

        return $challenge;
    }

    public function enrollment(User $user): object
    {
        return DB::transaction(function () use ($user): object {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $factor = DB::table('auth_factors')->where('user_id', $user->id)->first();
            if (! $factor) {
                DB::table('auth_factors')->insert(['user_id' => $user->id, 'secret' => Crypt::encryptString(app(TotpAuthenticator::class)->secret()), 'created_at' => now(), 'updated_at' => now()]);
                $factor = DB::table('auth_factors')->where('user_id', $user->id)->first();
            }

            return $factor;
        });
    }

    public function verifyFactor(User $user, string $code, bool $enrollment = false): bool
    {
        return DB::transaction(function () use ($user, $code, $enrollment): bool {
            $factor = DB::table('auth_factors')->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $factor || (! $enrollment && ! $factor->confirmed_at)) {
                return false;
            }
            $counter = app(TotpAuthenticator::class)->counter(Crypt::decryptString($factor->secret), $code, $factor->last_counter);
            if ($counter !== null) {
                DB::table('auth_factors')->where('id', $factor->id)->update(['last_counter' => $counter, 'confirmed_at' => $factor->confirmed_at ?? now(), 'updated_at' => now()]);

                return true;
            }
            if ($factor->confirmed_at) {
                $hashes = json_decode($factor->recovery_hashes ?? '[]', true);
                foreach ($hashes as $index => $hash) {
                    if (hash_equals($hash, hash('sha256', strtoupper(str_replace([' ', '-'], '', $code))))) {
                        unset($hashes[$index]);
                        DB::table('auth_factors')->where('id', $factor->id)->update(['recovery_hashes' => json_encode(array_values($hashes)), 'updated_at' => now()]);
                        $this->notify($user, 'auth.recovery_code.used', 'Satu kode pemulihan MFA telah digunakan. Periksa keamanan akun jika ini bukan Anda.');

                        return true;
                    }
                }
            }

            return false;
        });
    }

    public function recoveryCodes(User $user): array
    {
        $codes = array_map(fn (): string => strtoupper(implode('-', str_split(bin2hex(random_bytes(12)), 6))), range(1, 10));
        DB::table('auth_factors')->where('user_id', $user->id)->update(['recovery_hashes' => json_encode(array_map(fn ($code) => hash('sha256', str_replace('-', '', $code)), $codes)), 'updated_at' => now()]);

        return $codes;
    }

    public function verifyChallenge(Request $request, string $code): array
    {
        $challenge = $this->pending($request);
        $throttle = app(AuthenticationThrottle::class);
        $throttle->ensure($request, (string) $challenge->user_id, 'mfa', 'code');
        $result = DB::transaction(function () use ($request, $challenge, $code): array {
            $locked = DB::table('auth_challenges')->where('id', $challenge->id)->lockForUpdate()->first();
            abort_if($locked->consumed_at || $locked->expires_at <= now() || $locked->attempts >= config('security.challenge_attempts'), 403);
            $user = User::whereKey($locked->user_id)->where('is_active', true)->firstOrFail();
            $factor = DB::table('auth_factors')->where('user_id', $user->id)->first();
            $enrollment = $locked->audience === 'staff' && ! $factor?->confirmed_at;
            $valid = $locked->audience === 'staff' ? $this->verifyFactor($user, $code, $enrollment) : ($locked->code_hash && hash_equals($locked->code_hash, hash_hmac('sha256', $code, config('app.key'))));
            DB::table('auth_challenges')->where('id', $locked->id)->update($valid ? ['consumed_at' => now()] : ['attempts' => $locked->attempts + 1]);
            if (! $valid) {
                return ['valid' => false];
            }
            $codes = $enrollment ? $this->recoveryCodes($user) : [];
            if ($enrollment) {
                $this->notify($user, 'auth.mfa.added', 'Authenticator telah diaktifkan untuk akun Anda. Simpan kode pemulihan dengan aman.');
            }
            $this->finish($request, $user, $locked->audience);

            return ['valid' => true, 'codes' => $codes, 'user' => $user, 'audience' => $locked->audience];
        });
        if (! $result['valid']) {
            $throttle->failure($request, (string) $challenge->user_id, 'mfa');
            app(AccessPolicy::class)->audit(null, 'auth.challenge.denied', ['audience' => $challenge->audience]);
            throw ValidationException::withMessages(['code' => 'Kode tidak sesuai atau sudah digunakan. Gunakan kode baru dari authenticator atau kode pemulihan.']);
        }
        app(AccessPolicy::class)->audit($result['user'], 'auth.challenge.success', ['audience' => $challenge->audience]);

        return $result;
    }

    public function fingerprint(User $user): string
    {
        $roles = DB::table('crm_roles')->where('id', $user->crm_role_id)->get(['id', 'permissions', 'scope_type', 'scope_refs', 'active']);
        $assignments = DB::table('user_role_assignments as assignment')->join('crm_roles as role', 'role.id', '=', 'assignment.role_id')->where('assignment.user_id', $user->id)->where('assignment.active', true)->where(fn ($q) => $q->whereNull('assignment.valid_from')->orWhere('assignment.valid_from', '<=', now()))->where(fn ($q) => $q->whereNull('assignment.valid_until')->orWhere('assignment.valid_until', '>', now()))->get(['role.id', 'role.permissions', 'role.scope_type', 'role.scope_refs', 'role.active']);

        return hash('sha256', json_encode([$user->crm_role_id, $roles, $assignments]));
    }

    public function knownDevice(Request $request, User $user): bool
    {
        $token = $request->cookie('jm_auth_device');

        return is_string($token) && DB::table('auth_devices')->where('user_id', $user->id)->where('token_hash', hash('sha256', $token))->where('user_agent', mb_substr($request->userAgent() ?? 'Perangkat tidak dikenal', 0, 255))->where('expires_at', '>', now())->exists();
    }

    public function finish(Request $request, User $user, string $audience): void
    {
        $newDevice = ! $this->knownDevice($request, $user);
        Auth::login($user, false);
        $request->session()->forget(['auth.pending', 'security']);
        $request->session()->regenerate(true);
        $record = bin2hex(random_bytes(24));
        $request->session()->put('security', ['user_id' => $user->id, 'audience' => $audience, 'mfa' => $audience === 'staff', 'started_at' => now()->timestamp, 'last_active_at' => now()->timestamp, 'reauth_at' => now()->timestamp, 'version' => $user->auth_version, 'fingerprint' => $this->fingerprint($user), 'record' => $record]);
        DB::table('auth_session_records')->insert(['id' => $record, 'user_id' => $user->id, 'session_hash' => hash('sha256', $request->session()->getId()), 'device_label' => mb_substr($request->userAgent() ?? 'Perangkat tidak dikenal', 0, 255), 'ip_address' => $request->ip(), 'authenticated_at' => now(), 'last_active_at' => now()]);
        $token = $request->cookie('jm_auth_device');
        $token = is_string($token) && strlen($token) === 64 ? $token : bin2hex(random_bytes(32));
        DB::table('auth_devices')->updateOrInsert(['user_id' => $user->id, 'token_hash' => hash('sha256', $token)], ['user_agent' => mb_substr($request->userAgent() ?? 'Perangkat tidak dikenal', 0, 255), 'ip_address' => $request->ip(), 'last_seen_at' => now(), 'expires_at' => now()->addDays(config('security.trusted_device_days')), 'created_at' => now(), 'updated_at' => now()]);
        Cookie::queue(cookie('jm_auth_device', $token, config('security.trusted_device_days') * 1440, '/', null, true, true, false, 'lax'));
        if ($audience === 'cpmi') {
            DB::table('portal_accounts')->where('user_id', $user->id)->update(['last_login_at' => now()]);
        }
        if ($newDevice) {
            $this->notify($user, 'auth.new_device', 'Login dari perangkat baru terdeteksi. Tinjau daftar sesi jika ini bukan Anda.');
        }
        app(AccessPolicy::class)->audit($user, $audience.'.login.success');
    }

    public function notify(User $user, string $event, string $message): void
    {
        DB::table('auth_security_notifications')->insert(['user_id' => $user->id, 'event' => $event, 'message' => $message, 'created_at' => now()]);
        app(AccessPolicy::class)->audit($user, $event);
        if ($user->email_verified_at) {
            $this->mail($user, 'Keamanan akun Juragan Migran', $message);
        }
    }

    public function mailAvailable(): bool
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');

        return app()->runningUnitTests() || in_array($transport, ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun', 'sendmail'], true);
    }

    public function mail(User $user, string $subject, string $message): bool
    {
        if (! $this->mailAvailable()) {
            return false;
        }
        try {
            Mail::raw($message, fn ($mail) => $mail->to($user->email)->subject($subject));

            return true;
        } catch (\Throwable) {
            app(AccessPolicy::class)->audit($user, 'auth.notification.delivery_failed');

            return false;
        }
    }

    public function revokeOtherSessions(Request $request, User $user): void
    {
        $user->forceFill(['auth_version' => $user->auth_version + 1, 'remember_token' => bin2hex(random_bytes(30))])->saveQuietly();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        DB::table('auth_session_records')->where('user_id', $user->id)->where('id', '!=', $request->session()->get('security.record'))->whereNull('revoked_at')->update(['revoked_at' => now()]);
        DB::table('auth_devices')->where('user_id', $user->id)->delete();
        $request->session()->put('security.version', $user->auth_version);
        $request->session()->regenerate(true);
        $this->notify($user, 'auth.sessions.revoked', 'Sesi pada perangkat lain telah dicabut.');
    }

    public function destination(Request $request, User $user, string $audience): string
    {
        if ($audience === 'cpmi') {
            $request->session()->forget('url.intended');

            return route('portal.home');
        }
        $intended = $request->session()->pull('url.intended');
        $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;
        $host = is_string($intended) ? parse_url($intended, PHP_URL_HOST) : null;
        if ($path && str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_starts_with($path, '/portal') && (! $host || $host === parse_url(config('app.url'), PHP_URL_HOST))) {
            return url($path);
        }

        return $user->crmHomeUrl();
    }
}
