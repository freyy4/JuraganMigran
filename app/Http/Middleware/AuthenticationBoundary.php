<?php

namespace App\Http\Middleware;

use App\Services\AccessPolicy;
use App\Services\AuthenticationSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticationBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        return $this->headers($request, $this->authenticateRequest($request, $next));
    }

    private function authenticateRequest(Request $request, Closure $next): Response
    {
        if (! app()->runningUnitTests() && str_starts_with(config('app.url'), 'https://') && ! $request->secure() && $request->isMethod('GET')) {
            return redirect()->secure($request->getRequestUri(), 302);
        }
        if ($user = $request->user()) {
            $security = app(AuthenticationSecurity::class);
            $state = $request->session()->get('security', []);
            $portal = $security->isPortal($user);
            $policy = $portal ? 'cpmi' : (($user->hasPermission('admin.permission.manage') && $user->hasPermission('admin.user.manage')) ? 'super_admin' : 'staff');
            $record = isset($state['record']) ? DB::table('auth_session_records')->where('id', $state['record'])->where('user_id', $user->id)->whereNull('revoked_at')->exists() : false;
            $user->refresh();
            if (! $user->is_active || ($state['user_id'] ?? null) !== $user->id || (! $portal && ! ($state['mfa'] ?? false)) || ! $record || ($state['version'] ?? -1) !== $user->auth_version || ($state['fingerprint'] ?? '') !== $security->fingerprint($user) || now()->timestamp - ($state['last_active_at'] ?? 0) >= config('security.'.$policy.'.idle_minutes') * 60 || now()->timestamp - ($state['started_at'] ?? 0) >= config('security.'.$policy.'.absolute_minutes') * 60) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                app(AccessPolicy::class)->audit($user, 'auth.session.expired');

                return $request->expectsJson() ? response()->json(['ok' => false, 'code' => 'SESSION_EXPIRED', 'message' => 'Sesi berakhir. Silakan masuk kembali.'], 401) : redirect()->route($portal ? 'portal.login' : 'login')->with('success', 'Silakan masuk kembali untuk memverifikasi keamanan sesi.');
            }
            if ($this->sensitive($request, $portal) && now()->timestamp - ($state['reauth_at'] ?? 0) >= config('security.reauth_minutes') * 60) {
                return $request->expectsJson() ? response()->json(['ok' => false, 'code' => 'REAUTH_REQUIRED', 'next' => route('auth.reauthenticate'), 'message' => 'Verifikasi ulang diperlukan sebelum melanjutkan.'], 403) : to_route('auth.reauthenticate');
            }
            $request->session()->put('security.last_active_at', now()->timestamp);
            DB::table('auth_session_records')->where('id', $state['record'])->update(['last_active_at' => now()]);
        }

        return $next($request);
    }

    private function headers(Request $request, Response $response): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        $login = $request->routeIs('login*', 'portal.login*', 'auth.*', 'password.*') || $request->is('api/auth/*');
        if ($login) {
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }
        $response->headers->set('Content-Security-Policy', $login ? "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'" : "frame-ancestors 'self'; object-src 'none'; base-uri 'self'");
        if ($login || $request->user() || $request->is('portal/*', 'api/portal/*', 'api/auth/*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    private function sensitive(Request $request, bool $portal): bool
    {
        if ($request->routeIs('auth.confirm', 'auth.reauthenticate') || $request->isMethodSafe()) {
            return ! $portal && $request->routeIs('settings.*.edit', 'meta-connect.*', 'whatsapp-qr.*');
        }
        if ($portal) {
            return $request->routeIs('portal.profile.update', 'portal.api.profile.update', 'portal.revokeSessions') || ($request->routeIs('portal.requests.store', 'portal.api.request') && $request->input('type') === 'document_return');
        }

        return $request->routeIs('settings.*', 'security.*', 'auth.sessions.*', 'meta-connect.*', 'whatsapp-qr.*', 'crm.*export*', 'crm.bulk', '*payment*', '*finance*', '*payout*', '*merge*', '*secret*', '*credential*', '*connection*');
    }
}
