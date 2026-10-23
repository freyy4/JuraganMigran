<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccessPolicy;
use App\Services\AuthenticationSecurity;
use App\Services\AuthenticationThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticationSecurityController extends Controller
{
    public function challenge(Request $request, AuthenticationSecurity $security): View
    {
        $challenge = $security->pending($request);
        $user = User::findOrFail($challenge->user_id);
        $factor = $challenge->audience === 'staff' ? $security->enrollment($user) : null;
        $enrollment = $factor && ! $factor->confirmed_at;

        return view('auth.challenge', ['audience' => $challenge->audience, 'enrollment' => $enrollment, 'secret' => $enrollment ? Crypt::decryptString($factor->secret) : null, 'deliveryAvailable' => $security->mailAvailable() && (bool) $user->email_verified_at]);
    }

    public function verify(Request $request, AuthenticationSecurity $security): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64'], 'challengeId' => ['nullable', 'string', 'size:64']]);
        if ($request->routeIs('auth.staff.mfa.verify')) {
            abort_unless($security->pending($request)->audience === 'staff', 403);
        }
        if (isset($data['challengeId'])) {
            abort_unless(hash_equals($security->pending($request)->id, $data['challengeId']), 403);
        }
        $result = $security->verifyChallenge($request, $data['code']);
        $next = $security->destination($request, $result['user'], $result['audience']);
        if ($result['codes']) {
            $request->session()->put('auth.recovery_codes', $result['codes']);
            $request->session()->put('auth.after_recovery', $next);
            $next = route('auth.recovery-codes');
        }

        return $request->expectsJson() ? response()->json(['ok' => true, 'next' => $next]) : redirect($next);
    }

    public function recoveryCodes(Request $request): View
    {
        $codes = $request->session()->pull('auth.recovery_codes', []);
        abort_unless($codes, 404);

        return view('auth.recovery-codes', ['codes' => $codes, 'next' => $request->session()->pull('auth.after_recovery', route('dashboard'))]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        if ($pending = $request->session()->get('auth.pending.id')) {
            DB::table('auth_challenges')->where('id', $pending)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        }
        $request->session()->forget('auth.pending');
        $request->session()->regenerate(true);

        return to_route('login');
    }

    public function sessions(Request $request): View
    {
        return view('auth.sessions', ['sessions' => DB::table('auth_session_records')->where('user_id', $request->user()->id)->orderByDesc('last_active_at')->limit(20)->get(), 'notifications' => DB::table('auth_security_notifications')->where('user_id', $request->user()->id)->latest('id')->limit(10)->get()]);
    }

    public function revoke(Request $request, AuthenticationSecurity $security): RedirectResponse
    {
        $security->revokeOtherSessions($request, $request->user());

        return back()->with('success', 'Sesi perangkat lain telah dicabut.');
    }

    public function revokeUser(Request $request, User $user, AuthenticationSecurity $security): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->hasPermission('admin.user.manage'), 403);
        abort_if($user->id === $request->user()->id, 422, 'Gunakan Keluar dari perangkat lain untuk akun sendiri.');
        $user->forceFill(['auth_version' => $user->auth_version + 1, 'remember_token' => bin2hex(random_bytes(30))])->saveQuietly();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('auth_session_records')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        DB::table('auth_devices')->where('user_id', $user->id)->delete();
        $security->notify($user, 'auth.sessions.revoked_by_admin', 'Administrator telah mencabut sesi akun Anda. Silakan masuk kembali.');
        app(AccessPolicy::class)->audit($request->user(), 'auth.admin.session_revoked', ['target_user_id' => $user->id]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Sesi pengguna dicabut.');
    }

    public function reauthenticate(Request $request, AuthenticationSecurity $security): View
    {
        return view('auth.reauthenticate', ['audience' => $security->isPortal($request->user()) ? 'cpmi' : 'staff']);
    }

    public function confirm(Request $request, AuthenticationSecurity $security): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:4096'], 'code' => ['nullable', 'string', 'max:64']]);
        $user = $request->user();
        $throttle = app(AuthenticationThrottle::class);
        $throttle->ensure($request, (string) $user->id, 'reauth', 'password');
        $portal = $security->isPortal($user);
        if (! $security->verifiedPassword($user, $data['password']) || (! $portal && ! $security->verifyFactor($user, $data['code'] ?? ''))) {
            $throttle->failure($request, (string) $user->id, 'reauth');
            app(AccessPolicy::class)->audit($user, 'auth.reauthentication.denied');
            throw ValidationException::withMessages(['password' => 'Verifikasi tidak sesuai. Periksa kata sandi dan kode baru authenticator.']);
        }
        $request->session()->regenerate(true);
        $request->session()->put('security.reauth_at', now()->timestamp);
        app(AccessPolicy::class)->audit($user, 'auth.reauthentication.success');

        return $request->expectsJson() ? response()->json(['ok' => true]) : to_route($portal ? 'portal.home' : 'auth.sessions')->with('success', 'Verifikasi berhasil. Silakan ulangi tindakan Anda.');
    }
}
