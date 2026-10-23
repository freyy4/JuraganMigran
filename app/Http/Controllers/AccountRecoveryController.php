<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\SafePassphrase;
use App\Services\AccessPolicy;
use App\Services\AuthenticationSecurity;
use App\Services\AuthenticationThrottle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AccountRecoveryController extends Controller
{
    public function create(Request $request): View
    {
        $request->validate(['audience' => 'nullable|in:staff,cpmi']);

        return view('auth.forgot-password', ['audience' => $request->input('audience', 'staff'), 'deliveryAvailable' => app(AuthenticationSecurity::class)->mailAvailable()]);
    }

    public function store(Request $request, AuthenticationSecurity $security): RedirectResponse
    {
        $data = $request->validate(['audience' => 'required|in:staff,cpmi', 'identifier' => 'required|string|max:255']);
        $identifier = $security->normalize($data['identifier']);
        $throttle = app(AuthenticationThrottle::class);
        $throttle->ensure($request, $identifier, 'recovery');
        $throttle->failure($request, $identifier, 'recovery');
        $user = $data['audience'] === 'cpmi' ? $security->portalUser($identifier) : User::where('email', $identifier)->where('is_active', true)->first();
        app(AccessPolicy::class)->audit(null, 'auth.password_reset.requested', ['audience' => $data['audience']]);
        if ($user && $user->email_verified_at && $security->mailAvailable() && ($security->isPortal($user) === ($data['audience'] === 'cpmi'))) {
            $token = bin2hex(random_bytes(32));
            DB::transaction(function () use ($user, $token, $data): void {
                DB::table('auth_reset_tokens')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
                DB::table('auth_reset_tokens')->insert(['user_id' => $user->id, 'audience' => $data['audience'], 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(config('security.reset_minutes')), 'created_at' => now()]);
            });
            $security->mail($user, 'Pemulihan kata sandi Juragan Migran', 'Gunakan tautan sekali pakai berikut dalam '.config('security.reset_minutes').' menit: '.route('password.reset', ['token' => $token]).'. Jangan bagikan tautan ini.');
        } else {
            Hash::make(bin2hex(random_bytes(16)));
        }

        return back()->with('success', 'Jika akun dan kontak memenuhi syarat, instruksi pemulihan akan dikirim. Jika belum menerima, hubungi Admin/CS melalui kanal resmi.');
    }

    public function edit(string $token): View
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token) && DB::table('auth_reset_tokens')->where('token_hash', hash('sha256', $token))->whereNull('used_at')->where('expires_at', '>', now())->exists(), 404, 'Tautan tidak tersedia atau sudah berakhir.');

        return view('auth.reset-password', compact('token'));
    }

    public function update(Request $request, AuthenticationSecurity $security): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64'], 'password' => ['required', 'string', 'confirmed', new SafePassphrase]]);
        app(AuthenticationThrottle::class)->ensure($request, hash('sha256', $data['token']), 'reset', 'password');
        app(AuthenticationThrottle::class)->failure($request, hash('sha256', $data['token']), 'reset');
        $audience = DB::transaction(function () use ($data, $security): string {
            $reset = DB::table('auth_reset_tokens')->where('token_hash', hash('sha256', $data['token']))->whereNull('used_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($reset, 422, 'Tautan tidak tersedia atau sudah berakhir.');
            $user = User::whereKey($reset->user_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $user->forceFill(['password' => Hash::make($data['password']), 'auth_version' => $user->auth_version + 1, 'remember_token' => bin2hex(random_bytes(30))])->saveQuietly();
            DB::table('auth_reset_tokens')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('auth_devices')->where('user_id', $user->id)->delete();
            DB::table('auth_challenges')->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            DB::table('auth_session_records')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $security->notify($user, 'auth.password_reset.completed', 'Kata sandi akun Anda telah diubah. Semua sesi sebelumnya telah dicabut.');

            return $reset->audience;
        });
        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route($audience === 'cpmi' ? 'portal.login' : 'login')->with('success', 'Kata sandi diperbarui. Silakan masuk kembali; MFA staff tetap diwajibkan.');
    }
}
