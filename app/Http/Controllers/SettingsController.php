<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Task;
use App\Models\User;
use App\Services\AccessPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public const MODULES = ['users' => 'Pengguna', 'roles' => 'Peran & Hak Akses', 'automation' => 'Otomasi', 'templates' => 'Template Notifikasi', 'integrations' => 'Integrasi', 'activity' => 'Riwayat Aktivitas', 'general' => 'Pengaturan Umum'];

    private const TABLES = ['users' => 'users', 'roles' => 'crm_roles', 'automation' => 'automation_rules', 'templates' => 'notification_templates', 'integrations' => 'crm_integrations', 'activity' => 'audit_logs'];

    public function index(Request $request, string $module): View
    {
        abort_unless(isset(self::MODULES[$module]), 404);
        if ($module === 'general') {
            return $this->form($module, (object) DB::table('crm_settings')->pluck('value', 'key')->all());
        }
        $request->validate(['search' => ['nullable', 'string', 'max:150']]);
        $query = DB::table(self::TABLES[$module]);
        if ($module === 'activity') {
            $query->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->select('audit_logs.*', 'users.name as user_name');
            if ($request->filled('search')) {
                $query->where('action', 'like', '%'.$request->input('search').'%');
            }
            $query->orderByDesc('audit_logs.id');
        } else {
            if ($request->filled('search')) {
                $query->where('name', 'like', '%'.$request->input('search').'%');
            }
            if ($module === 'users') {
                $query->leftJoin('crm_roles', 'crm_roles.id', '=', 'users.crm_role_id')->select('users.id', 'users.name', 'users.email', 'users.is_active', 'crm_roles.name as role_name')->orderByDesc('users.id');
            } elseif ($module === 'integrations') {
                $query->select('id', 'name', 'endpoint')->orderByDesc('id');
            } else {
                $query->orderByDesc('id');
            }
        }

        return view('settings.index', ['module' => $module, 'title' => self::MODULES[$module], 'rows' => $query->paginate(25)->withQueryString()]);
    }

    public function create(string $module): View
    {
        abort_unless(isset(self::TABLES[$module]) && $module !== 'activity', 404);

        return $this->form($module, null);
    }

    public function edit(int $record, string $module): View
    {
        abort_unless(isset(self::TABLES[$module]) && $module !== 'activity', 404);
        $row = DB::table(self::TABLES[$module])->find($record);
        abort_unless($row, 404);
        if ($module === 'automation') {
            $row->conditions = json_decode($row->conditions ?? '{}', true);
            $row->action = json_decode($row->action, true);
            $row->status = $row->conditions['status'] ?? 'active';
            $row->stage_id = $row->conditions['stage_id'] ?? null;
            $row->task_title = $row->action['title'] ?? '';
            $row->due_days = $row->action['due_days'] ?? 1;
        }
        if ($module === 'roles') {
            $row->permissions = json_decode($row->permissions, true);
        }
        if ($module === 'users') {
            unset($row->password, $row->remember_token);
        }
        if ($module === 'integrations') {
            $row->has_secret = ! empty($row->secret);
            unset($row->secret);
        }

        return $this->form($module, $row);
    }

    private function form(string $module, ?object $row): View
    {
        return view('settings.form', ['module' => $module, 'title' => self::MODULES[$module], 'row' => $row, 'roles' => DB::table('crm_roles')->orderBy('name')->get(), 'groups' => config('governance.permissions'), 'scopes' => config('governance.scopes'), 'stages' => DB::table('pipeline_stages')->orderBy('sequence')->get(), 'statuses' => Application::statusOptions()]);
    }

    public function store(Request $request, string $module): RedirectResponse
    {
        return $this->save($request, $module, null);
    }

    public function update(Request $request, int $record, string $module): RedirectResponse
    {
        return $this->save($request, $module, $record);
    }

    private function save(Request $request, string $module, ?int $record): RedirectResponse
    {
        abort_unless(isset(self::MODULES[$module]) && $module !== 'activity', 404);
        if ($record !== null) {
            abort_unless(isset(self::TABLES[$module]) && DB::table(self::TABLES[$module])->where('id', $record)->exists(), 404);
        }
        $rules = match ($module) {
            'users' => ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($record)], 'password' => [$record ? 'nullable' : 'required', 'string', new \App\Rules\SafePassphrase, 'max:255', 'confirmed'], 'crm_role_id' => ['required', 'integer', 'exists:crm_roles,id'], 'whatsapp' => ['nullable', 'string', 'max:30'], 'team_id' => ['nullable', 'integer', 'min:1'], 'is_active' => ['required', 'boolean']],
            'roles' => ['name' => ['required', 'string', 'max:100', Rule::unique('crm_roles')->ignore($record)], 'permissions' => ['nullable', 'array'], 'permissions.*' => ['string', 'distinct', Rule::in(array_merge(config('governance.permissions'), collect(config('navigation'))->pluck('label')->all()))], 'is_admin' => ['required', 'boolean'], 'scope_type' => ['nullable', Rule::in(config('governance.scopes'))], 'scope_refs' => ['nullable', 'string', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/']],
            'automation' => ['name' => ['required', 'string', 'max:255'], 'status' => ['required', Rule::in(Application::statusOptions())], 'stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id'], 'task_title' => ['required', 'string', 'max:255'], 'due_days' => ['required', 'integer', 'min:0', 'max:365'], 'is_active' => ['required', 'boolean']],
            'templates' => ['name' => ['required', 'string', 'max:255', Rule::unique('notification_templates')->ignore($record)], 'subject' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:10000'], 'is_active' => ['required', 'boolean']],
            'integrations' => ['name' => ['required', 'string', 'max:255', Rule::unique('crm_integrations')->ignore($record)], 'endpoint' => ['required', 'url:https', 'max:255'], 'secret' => ['nullable', 'string', 'max:4096'], 'clear_secret' => ['sometimes', 'boolean']],
            'general' => ['site_name' => ['required', 'string', 'max:100'], 'contact_email' => ['nullable', 'email', 'max:255'], 'contact_phone' => ['nullable', 'string', 'max:30']],
        };
        $data = $request->validate($rules);
        DB::transaction(function () use ($request, $module, $record, $data): void {
            if (in_array($module, ['users', 'roles'])) {
                DB::table('users')->orderBy('id')->lockForUpdate()->get();
                DB::table('crm_roles')->orderBy('id')->lockForUpdate()->get();
            }
            if ($module === 'general') {
                foreach ($data as $key => $value) {
                    DB::table('crm_settings')->updateOrInsert(['key' => $key], ['value' => $value ?? '', 'created_at' => now(), 'updated_at' => now()]);
                }

                return;
            }
            if ($module === 'users') {
                unset($data['password_confirmation']);
                if (empty($data['password'])) {
                    unset($data['password']);
                }
                $user = $record ? User::findOrFail($record) : new User;
                if ($record === $request->user()->id && ! $data['is_active']) {
                    throw ValidationException::withMessages(['is_active' => 'Akun yang sedang digunakan tidak dapat dinonaktifkan.']);
                }
                $user->fill($data);
                if (isset($data['password']) || ! $data['is_active']) {
                    $user->remember_token = Str::random(60);
                    if ($record) {
                        DB::table('sessions')->where('user_id', $record)->delete();
                    }
                }
                $user->save();
            } else {
                if ($module === 'roles') {
                    $permissions = app(AccessPolicy::class)->expand($data['permissions'] ?? []);
                    if ($data['is_admin'] && ! in_array('admin.user.manage', $permissions, true)) {
                        $permissions = array_values(array_unique(array_merge($permissions, config('governance.roles.super_admin')[2])));
                    }
                    $data['permissions'] = json_encode($permissions);
                    $data['scope_type'] = $data['scope_type'] ?? ($data['is_admin'] ? 'TECHNICAL_ONLY' : 'ASSIGNED');
                    $data['scope_refs'] = json_encode(array_map('intval', array_filter(explode(',', $data['scope_refs'] ?? ''))));
                } elseif ($module === 'automation') {
                    $data = ['name' => $data['name'], 'trigger' => 'manual', 'conditions' => json_encode(['status' => $data['status'], 'stage_id' => $data['stage_id'] ?? null]), 'action' => json_encode(['type' => 'create_task', 'title' => $data['task_title'], 'due_days' => $data['due_days']]), 'is_active' => $data['is_active']];
                    if (! $record) {
                        $data['created_by'] = $request->user()->id;
                    }
                } elseif ($module === 'integrations') {
                    $clear = $request->boolean('clear_secret');
                    unset($data['clear_secret']);
                    if ($clear) {
                        $data['secret'] = null;
                    } elseif (! empty($data['secret'])) {
                        $data['secret'] = Crypt::encryptString($data['secret']);
                    } else {
                        unset($data['secret']);
                    }
                }
                $data['updated_at'] = now();
                if ($record) {
                    DB::table(self::TABLES[$module])->where('id', $record)->update($data);
                } else {
                    DB::table(self::TABLES[$module])->insert($data + ['created_at' => now()]);
                }
            }
            if (in_array($module, ['users', 'roles'])) {
                $admins = User::where('is_active', true)->get()->filter(fn ($user) => $user->hasPermission('admin.permission.manage') && $user->hasPermission('admin.user.manage'))->count();
                if ($admins === 0) {
                    throw ValidationException::withMessages(['is_admin' => 'Minimal satu administrator aktif harus tersedia.']);
                }
            }
        });

        return to_route('settings.'.$module.'.index')->with('success', 'Pengaturan berhasil disimpan.');
    }

    public function run(Request $request, int $record): RedirectResponse
    {
        $count = DB::transaction(function () use ($request, $record): int {
            $rule = DB::table('automation_rules')->where('id', $record)->lockForUpdate()->first();
            abort_unless($rule, 404);
            abort_unless($rule->is_active && $rule->trigger === 'manual', 422, 'Aturan tidak aktif atau pemicu tidak didukung.');
            $conditions = json_decode($rule->conditions ?? '{}', true);
            $action = json_decode($rule->action, true);
            abort_unless(($action['type'] ?? null) === 'create_task' && ! empty($action['title']), 422);
            $applications = Application::where('status', $conditions['status'] ?? 'active');
            if (! empty($conditions['stage_id'])) {
                $applications->where('current_stage_id', $conditions['stage_id']);
            }
            $count = 0;
            foreach ($applications->get() as $application) {
                $type = 'automation:'.$record;
                if (Task::where('application_id', $application->id)->where('task_type', $type)->exists()) {
                    continue;
                }
                Task::create(['application_id' => $application->id, 'assigned_to' => $application->assigned_cs_id, 'task_type' => $type, 'title' => $action['title'], 'priority' => 'normal', 'due_at' => now()->addDays((int) ($action['due_days'] ?? 1)), 'status' => 'open', 'created_by' => $request->user()->id]);
                $count++;
            }

            return $count;
        });

        return to_route('settings.automation.index')->with('success', $count.' tugas dibuat. Lamaran yang sudah diproses aturan ini tidak diduplikasi.');
    }
}
