<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\AccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse|JsonResponse
    {
        $result = $request->authenticate();

        return $request->expectsJson() ? response()->json($result) : redirect($result['next']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        app(AccessPolicy::class)->audit($request->user(), 'auth.logout');
        DB::table('auth_session_records')->where('id', $request->session()->get('security.record'))->update(['revoked_at' => now()]);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login')->with('success', 'Anda berhasil keluar.');
    }
}
