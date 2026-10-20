<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->is_active) {
            Auth::logout();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Sesi Anda berakhir. Silakan masuk kembali.'], 401)->header('Cache-Control', 'private, no-store');
            }

            return to_route('portal.login');
        }
        $account = DB::table('portal_accounts')->where('user_id', $user->id)->where('status', 'active')->whereNotNull('activated_at')->whereNotNull('phone_verified_at')->first();
        if (! $account || ! DB::table('candidates')->where('id', $account->candidate_id)->whereNull('deleted_at')->exists()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Akun portal belum aktif. Hubungi CS untuk undangan atau aktivasi akses.'], 403)->header('Cache-Control', 'private, no-store');
            }

            return to_route('portal.login')->withErrors(['email' => 'Akun ini belum memiliki akses portal CPMI aktif. Masuk dengan akun CPMI dari undangan CS.']);
        }
        $request->attributes->set('portal_account', $account);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
