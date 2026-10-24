<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\User;
use App\Services\AccessPolicy;
use App\Services\CareJourney;
use App\Services\TrustCommitmentProtocol;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TrustCommitmentController extends Controller
{
    public function __construct(private TrustCommitmentProtocol $protocol) {}

    public function index(Request $request): View
    {
        abort_unless($this->protocol->available(), 404);
        $request->validate(['queue' => 'nullable|string|max:40', 'overdue' => 'nullable|boolean']);
        $all = $this->protocol->queue($request->user());
        $rows = $all->when($request->filled('queue'), fn ($rows) => $rows->where('queue', $request->input('queue')))->when($request->boolean('overdue'), fn ($rows) => $rows->filter(fn ($row) => Carbon::parse($row->due_at)->isPast()));
        $metrics = $all->groupBy('stage')->map(fn ($group) => ['count' => $group->count(), 'age' => round($group->map(fn ($row) => Carbon::parse($row->entered_at)->diffInHours(now()))->median(), 1), 'overdue' => $group->filter(fn ($row) => Carbon::parse($row->due_at)->isPast())->count()]);
        $settings = DB::table('protocol_settings')->find(1);
        $policy = $this->protocol->policy();
        $profiles = DB::table('protocol_routing_profiles as p')->join('users as u', 'u.id', '=', 'p.user_id')->get(['p.*', 'u.name']);
        $users = $request->user()->hasPermission('admin.master_data.manage') ? User::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect();
        $content = $request->user()->hasPermission('admin.master_data.manage') ? DB::table('protocol_content_versions')->latest('id')->limit(30)->get() : collect();
        $stageLabels = collect(config('protocol.stages'))->pluck('label', 'queue');
        $overrideCount = DB::table('protocol_actions')->where('action', 'override')->whereIn('run_id', $all->pluck('run_id'))->count();
        $transitionCount = DB::table('protocol_actions')->whereIn('action', ['override', 'advance'])->whereIn('run_id', $all->pluck('run_id'))->count();
        app(AccessPolicy::class)->audit($request->user(), 'protocol.queue_view');

        return view('protocol.index', compact('rows', 'all', 'metrics', 'settings', 'policy', 'profiles', 'users', 'content', 'overrideCount', 'transitionCount'));
    }

    public function show(Request $request, Application $application): View
    {
        $this->protocol->authorize($request->user(), $application, 'process.read');
        abort_unless($this->protocol->available(), 404);
        $run = DB::table('protocol_runs')->where('application_id', $application->id)->first();
        $definition = $run ? json_decode($run->definition, true) : $this->protocol->policy();
        $stage = $run ? DB::table('protocol_stage_instances')->where('run_id', $run->id)->whereNull('exited_at')->first() : null;
        $gate = $run ? $this->protocol->gate($application, $run) : null;
        $can = fn (string $permission): bool => app(AccessPolicy::class)->applicationIds($request->user(), $permission)->where('applications.id', $application->id)->exists();
        $canAct = $run && $can('process.update') && $can($definition['stages'][$run->stage]['permission']);
        $content = DB::table('protocol_content_versions')->where('status', 'Approved')->where('effective_at', '<=', now())->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->where(fn ($q) => $q->whereNull('vacancy_id')->orWhere('vacancy_id', $application->vacancy_id))->get();
        $consent = $run && $can('document.read.metadata') ? DB::table('protocol_family_consents')->where('run_id', $run->id)->first() : null;
        $appointment = $run ? DB::table('protocol_appointments')->where('run_id', $run->id)->first() : null;
        $golden = $run && ($can('protocol.placement') || $can('health.mcu.summary.read')) ? DB::table('protocol_golden_windows')->where('run_id', $run->id)->first() : null;
        $documents = $can('document.read.metadata') ? $this->protocol->checklist($application) : [];
        $contacts = $can('candidate.read.contact') ? $application->candidate->contacts()->get() : collect();
        $evidence = $can('document.read.metadata') ? app(\App\Services\DocumentRequirementEngine::class)->documents($application) : collect();
        $medical = $can('protocol.ops') || $can('protocol.medical') ? $application->medicalEvents()->with('clinic')->get() : collect();
        $enrollments = $can('protocol.placement') ? $application->trainingEnrollments()->with('batch.company')->get() : collect();
        $people = User::where('is_active', true)->get(['id', 'name'])->filter(fn ($u) => app(AccessPolicy::class)->applicationIds($u, 'process.update')->where('applications.id', $application->id)->exists());
        $blockers = $application->blockers()->whereNotIn('status', ['resolved', 'completed', 'done', 'cancelled', 'closed'])->with('owner')->get();
        $interview = $can('protocol.senior') ? $application->interviews()->where('type', 'senior_protocol')->latest('id')->first() : null;
        $health = $can('health.prescreen.read') ? $application->latestHealthInterview : null;
        $workflow = app(\App\Services\ProcessWorkflow::class)->stages($application);
        return view('protocol.show', compact('application', 'run', 'definition', 'stage', 'gate', 'can', 'canAct', 'content', 'consent', 'appointment', 'golden', 'documents', 'contacts', 'evidence', 'medical', 'enrollments', 'people', 'blockers', 'interview', 'health', 'workflow'));
    }

    public function state(Request $request, Application $application): \Illuminate\Http\JsonResponse
    {
        $this->protocol->authorize($request->user(), $application, 'process.read');
        $run = DB::table('protocol_runs')->where('application_id', $application->id)->first(['version', 'stage']);
        return response()->json(['version' => $run?->version, 'stage' => $run?->stage])->header('Cache-Control', 'private, no-store');
    }

    public function action(Request $request, Application $application): RedirectResponse
    {
        $data = $request->validate([
            'request_key' => 'required|uuid', 'version' => 'required|integer|min:0', 'stage' => ['required', Rule::in(array_keys(config('protocol.stages')))],
            'action' => 'required|in:start,advance,override,outcome,block,unblock,consent,appointment,mode', 'target' => ['nullable', Rule::in(array_keys(config('protocol.stages')))],
            'interest' => 'nullable|in:PROGRAM,COUNTRY,JOB,QUESTION', 'inbound_confirmed' => 'nullable|boolean', 'response' => 'nullable|in:UNDERSTOOD,CLARIFY,FAMILY_DOUBT,DISTRUST', 'checklist_complete' => 'nullable|boolean', 'content_id' => 'nullable|integer', 'wants_continue' => 'nullable|boolean', 'reviewed' => 'nullable|boolean',
            'rubric' => 'nullable|array:process,commitment,training,family,documents,practical,objections', 'rubric.*' => 'required|in:READY,CLARIFY,BLOCKED', 'interview_outcome' => 'nullable|in:continue,reinterview,hold,rejected', 'note' => 'nullable|string|max:1000',
            'reason_code' => ['nullable', Rule::in(array_keys(config('protocol.blockers')))], 'reason' => 'nullable|string|max:1000', 'risk_acknowledged' => 'nullable|boolean', 'owner_id' => 'nullable|integer|exists:users,id', 'next_action' => 'nullable|string|max:255', 'due_at' => 'nullable|date_format:Y-m-d\TH:i|after:now',
            'consent_status' => 'nullable|in:EXPLAINED,HESITANT,AGREED,SENT,SIGNED,VERIFIED,COMPLETE', 'contact_id' => 'nullable|integer', 'document_id' => 'nullable|integer', 'template_version' => 'nullable|string|max:100', 'verification_method' => 'nullable|string|max:255', 'endorsement' => 'nullable|string|max:255',
            'pic_id' => 'nullable|integer|exists:users,id', 'office_location' => 'nullable|string|max:255', 'scheduled_at' => 'nullable|date_format:Y-m-d\TH:i', 'transport' => 'nullable|string|max:255', 'medical_event_id' => 'nullable|integer', 'appointment_status' => 'nullable|in:CONFIRMED,ATTENDED,NO_SHOW,CANCELLED',
            'mode' => 'nullable|in:SHADOW,GUIDED,ENFORCED', 'enrollment_id' => 'nullable|integer', 'handover' => 'nullable|string|max:1000', 'support_reviewed' => 'nullable|boolean',
        ]);
        foreach (['due_at', 'scheduled_at'] as $field) {
            if (filled($data[$field] ?? null)) {
                $data[$field] = Carbon::createFromFormat('Y-m-d\TH:i', $data[$field], 'Asia/Jakarta')->utc()->toDateTimeString();
            }
        }
        if ($data['action'] === 'start') {
            $this->protocol->authorize($request->user(), $application, 'process.update');
            $this->protocol->initialize($application, true);
        } else {
            if ($data['action'] === 'mode' && ! isset($data['mode'])) {
                return back()->withErrors(['mode' => 'Pilih mode rollout.']);
            }
            $this->protocol->act($request->user(), $application, $data);
        }
        return back()->with('success', 'Protokol diperbarui. PIC, gate dan tindakan berikutnya sudah disinkronkan.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'new_application_mode' => 'required|in:SHADOW,GUIDED,ENFORCED', 'golden_hours' => 'required|array|size:3', 'golden_hours.*' => 'required|integer|min:1|max:2160', 'stages' => 'required|array|size:15', 'stages.*.hours' => 'required|integer|min:1|max:2160', 'stages.*.action' => 'required|string|max:255']);
        abort_unless($data['golden_hours'][0] < $data['golden_hours'][1] && $data['golden_hours'][1] < $data['golden_hours'][2], 422, 'SLA Golden Window harus berurutan X < Y < Z.');
        DB::transaction(function () use ($data): void {
            $settings = DB::table('protocol_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            abort_unless($settings->version === $data['version'], 409, 'Pengaturan telah berubah.');
            $policy = json_decode($settings->policy, true);
            abort_unless(array_keys($data['stages']) === array_keys($policy['stages']), 422);
            foreach ($data['stages'] as $key => $values) {
                $policy['stages'][$key] = [...$policy['stages'][$key], ...$values, 'hours' => (int) $values['hours']];
            }
            $policy['golden_hours'] = array_map('intval', $data['golden_hours']);
            $policy['new_application_mode'] = $data['new_application_mode'];
            DB::table('protocol_settings')->where('id', 1)->update(['policy' => json_encode($policy), 'version' => $settings->version + 1, 'updated_at' => now()]);
        });
        app(AccessPolicy::class)->audit($request->user(), 'protocol.settings_updated');
        return back()->with('success', 'Kebijakan protokol tersimpan untuk proses baru; snapshot proses berjalan tetap dapat diaudit.');
    }

    public function content(Request $request): RedirectResponse
    {
        $data = $request->validate(['content_id' => 'nullable|integer', 'title' => 'required_without:content_id|string|max:150', 'version' => 'required_without:content_id|integer|min:1', 'source' => 'required_without:content_id|string|max:255', 'body' => 'required_without:content_id|string|max:12000', 'effective_at' => 'required_without:content_id|date', 'expires_at' => 'nullable|date|after:effective_at', 'vacancy_id' => 'nullable|integer|exists:vacancies,id', 'status' => 'required|in:Draft,Approved,Archived']);
        if (! isset($data['content_id'])) {
            $request->validate(['version' => [Rule::unique('protocol_content_versions', 'version')->where('title', $data['title'])]]);
            foreach (['effective_at', 'expires_at'] as $field) {
                if (filled($data[$field] ?? null)) {
                    $data[$field] = Carbon::parse($data[$field], 'Asia/Jakarta')->utc()->toDateTimeString();
                }
            }
        }
        DB::transaction(function () use ($request, $data): void {
            if (isset($data['content_id'])) {
                $content = DB::table('protocol_content_versions')->where('id', $data['content_id'])->lockForUpdate()->firstOrFail();
                abort_unless(($content->status === 'Draft' && in_array($data['status'], ['Approved', 'Archived'], true)) || ($content->status === 'Approved' && $data['status'] === 'Archived'), 422, 'Isi versi tidak dapat diubah setelah disetujui; buat versi baru.');
                DB::table('protocol_content_versions')->where('id', $content->id)->update(['status' => $data['status'], 'approved_by' => $data['status'] === 'Approved' ? $request->user()->id : $content->approved_by, 'updated_at' => now()]);
            } else {
                abort_unless($data['status'] === 'Draft', 422, 'Buat Draft lalu review dan setujui.');
                DB::table('protocol_content_versions')->insert([...collect($data)->except('content_id')->all(), 'owner_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        app(AccessPolicy::class)->audit($request->user(), 'protocol.content_updated', ['content_id' => $data['content_id'] ?? null, 'status' => $data['status']]);
        return back()->with('success', 'Versi materi tersimpan. Hanya Approved yang dapat digunakan CS.');
    }

    public function routing(Request $request): RedirectResponse
    {
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id', 'available' => 'required|boolean', 'capacity' => 'required|integer|min:1|max:1000', 'queues' => 'required|array|min:1', 'queues.*' => ['required', Rule::in(array_unique(array_column(config('protocol.stages'), 'queue')))], 'country_codes' => 'nullable|string|max:100', 'start_hour' => 'required|integer|min:0|max:23', 'end_hour' => 'required|integer|min:1|max:24|gt:start_hour']);
        DB::table('protocol_routing_profiles')->updateOrInsert(['user_id' => $data['user_id']], [...collect($data)->except(['queues', 'country_codes'])->all(), 'queues' => json_encode($data['queues']), 'country_codes' => json_encode(array_values(array_filter(array_map('trim', explode(',', strtoupper($data['country_codes'] ?? '')))))), 'created_at' => now(), 'updated_at' => now()]);
        app(AccessPolicy::class)->audit($request->user(), 'protocol.routing_updated', ['user_id' => $data['user_id']]);
        $this->protocol->monitor();
        return back()->with('success', 'Routing tersimpan. Penugasan tetap memeriksa izin, negara, kapasitas dan jam kerja WIB.');
    }
}
