<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccessPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GovernanceController extends Controller
{
    public function index(Request $request): View
    {
        $admin = $request->user()->hasPermission('admin.permission.manage');
        $users = DB::table('users')->where('is_active', true);
        $grants = DB::table('access_grants');
        if (! $admin) {
            $users->where(fn ($query) => $query->where('id', $request->user()->id)->when($request->user()->hasPermission('access.delegation.manage') && $request->user()->team_id, fn ($query) => $query->orWhere('team_id', $request->user()->team_id)));
            $grants->where('user_id', $request->user()->id);
        }

        return view('governance.index', ['roles' => $admin ? DB::table('crm_roles')->orderBy('name')->get() : collect(), 'users' => $users->select('id', 'name', 'team_id')->get(), 'assignments' => $admin ? DB::table('user_role_assignments')->latest('id')->get() : collect(), 'grants' => $grants->latest('id')->get(), 'permissions' => config('governance.permissions'), 'scopes' => config('governance.scopes')]);
    }

    public function assignment(Request $request): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id'], 'role_id' => ['required', 'integer', 'exists:crm_roles,id'], 'scope_type' => ['required', Rule::in(config('governance.scopes'))], 'scope_refs' => ['nullable', 'string', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'], 'valid_until' => ['required', 'date', 'after:now']]);
        DB::transaction(function () use ($data, $request): void {
            $id = DB::table('user_role_assignments')->insertGetId(['user_id' => $data['user_id'], 'role_id' => $data['role_id'], 'valid_from' => now(), 'valid_until' => Carbon::parse($data['valid_until'], 'Asia/Jakarta')->utc(), 'assigned_by' => $request->user()->id, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('scope_grants')->insert(['assignment_id' => $id, 'scope_type' => $data['scope_type'], 'scope_refs' => json_encode(array_map('intval', array_filter(explode(',', $data['scope_refs'] ?? '')))), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Penugasan peran dan cakupan tersimpan dengan batas waktu.');
    }

    public function revokeAssignment(int $assignment): RedirectResponse
    {
        DB::table('user_role_assignments')->where('id', $assignment)->update(['active' => false, 'updated_at' => now()]);

        return back()->with('success', 'Penugasan peran dicabut.');
    }

    public function grantRequest(Request $request): RedirectResponse
    {
        $data = $request->validate(['candidate_id' => ['required', 'integer', 'exists:candidates,id'], 'permissions' => ['required', 'array', 'min:1'], 'permissions.*' => [Rule::in(config('governance.permissions'))], 'reason' => ['required', 'string', 'min:10', 'max:255'], 'duration_minutes' => ['required', 'integer', 'min:5', 'max:120']]);
        DB::table('access_grants')->insert(['type' => 'break_glass', 'user_id' => $request->user()->id, 'candidate_id' => $data['candidate_id'], 'permissions' => json_encode($data['permissions']), 'reason' => $data['reason'], 'status' => 'pending', 'valid_from' => now(), 'valid_until' => now()->addMinutes($data['duration_minutes']), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Permintaan akses darurat diajukan. Belum memberikan akses.');
    }

    public function approve(Request $request, int $grant): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('access.break_glass.approve'), 403);
        DB::transaction(function () use ($grant, $request): void {
            $row = DB::table('access_grants')->where('id', $grant)->lockForUpdate()->first() ?? abort(404);
            abort_unless($row->status === 'pending' && $row->user_id !== $request->user()->id && $row->valid_until > now(), 422, 'Permintaan kedaluwarsa atau tidak dapat disetujui oleh pemohon sendiri.');
            DB::table('access_grants')->where('id', $grant)->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'updated_at' => now()]);
        });

        return back()->with('success', 'Akses sementara disetujui hingga waktu kedaluwarsa.');
    }

    public function revoke(int $grant): RedirectResponse
    {
        DB::table('access_grants')->where('id', $grant)->update(['status' => 'revoked', 'updated_at' => now()]);

        return back()->with('success', 'Akses sementara dicabut.');
    }

    public function delegation(Request $request): RedirectResponse
    {
        $data = $request->validate(['from_user_id' => ['required', 'integer', 'exists:users,id'], 'user_id' => ['required', 'integer', 'exists:users,id', 'different:from_user_id'], 'reason' => ['required', 'string', 'max:255'], 'valid_until' => ['required', 'date', 'after:now']]);
        $from = User::findOrFail($data['from_user_id']);
        $to = User::findOrFail($data['user_id']);
        $policies = app(AccessPolicy::class)->policies($request->user(), 'access.delegation.manage');
        $all = collect($policies)->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL');
        abort_unless($all || ($request->user()->team_id && $from->team_id === $request->user()->team_id && $to->team_id === $request->user()->team_id), 403);
        $permissions = config('governance.roles.cs')[2];
        DB::table('access_grants')->insert(['type' => 'delegation', 'user_id' => $to->id, 'from_user_id' => $from->id, 'approved_by' => $request->user()->id, 'permissions' => json_encode($permissions), 'reason' => $data['reason'], 'status' => 'approved', 'valid_from' => now(), 'valid_until' => Carbon::parse($data['valid_until'], 'Asia/Jakarta')->utc(), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Delegasi dibuat tanpa mengubah pemilik historis.');
    }
}
