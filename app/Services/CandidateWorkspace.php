<?php

namespace App\Services;

use App\Http\Controllers\AcquisitionController;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CandidateWorkspace
{
    public const AGE_OPTIONS = ['18-25' => '18–25 tahun', '26-35' => '26–35 tahun', '36-45' => '36–45 tahun', '46+' => '46 tahun ke atas'];

    public const PASSPORT_OPTIONS = ['NONE' => 'Belum ada paspor', 'VALID' => 'Paspor berlaku', 'EXPIRED' => 'Paspor kedaluwarsa', 'IN_PROGRESS' => 'Dalam pengurusan'];

    public const READINESS_OPTIONS = ['READY' => 'Siap berangkat', 'PREPARING' => 'Dalam persiapan', 'UNDECIDED' => 'Belum ditentukan'];

    public function allowed(Request $request, Candidate $candidate, string $permission): bool
    {
        $cached = $request->attributes->get('crm_field_permissions', []);

        return array_key_exists($permission, $cached) ? in_array($candidate->id, $cached[$permission], true) : app(AccessPolicy::class)->canCandidate($request->user(), $candidate->id, $permission);
    }

    public function details(Request $request, Candidate $candidate): array
    {
        $candidate->load(['person', 'contacts']);
        $permissions = collect(['identity', 'contact', 'location', 'timeline'])->mapWithKeys(fn (string $field): array => [$field => $this->allowed($request, $candidate, 'candidate.read.'.$field)])->all();
        $canEdit = $this->allowed($request, $candidate, 'candidate.update.profile') && $permissions['identity'] && $permissions['contact'] && $permissions['location'];
        $canClose = $this->allowed($request, $candidate, 'candidate.close');
        $applications = Application::where('candidate_id', $candidate->id)->with(['vacancy.jobOrder.country', 'currentStage', 'assignedCs'])->orderByDesc('applied_at')->orderByDesc('id')->get();
        $current = $applications->first(fn (Application $application): bool => ! in_array($application->status, ['closed', 'cancelled', 'rejected', 'withdrawn', 'completed'], true)) ?? $applications->first();
        $policy = app(AccessPolicy::class);
        $leadRows = $permissions['timeline'] ? app(AcquisitionController::class)->scopedLeads($request, 'candidate.read.timeline')->where('candidate_id', $candidate->id)->get(['id', 'name', 'crm_source', 'crm_stage', 'next_followup_at', 'assigned_cs_id']) : collect();
        $leadIds = $leadRows->pluck('id');
        $timeline = collect();
        $historyIds = collect();
        $activityCounts = ['messages' => 0, 'stages' => 0, 'followups' => 0, 'forms' => 0];
        $since = now()->subDays(30);
        $activityLabels = [
            'message_received' => ['Pesan WhatsApp diterima', 'whatsapp'], 'message_sent' => ['Pesan WhatsApp terkirim', 'whatsapp'],
            'conversation_opened' => ['Percakapan dibuka', 'message'], 'stage_changed' => ['Tahap lead berubah', 'route'],
            'followup_scheduled' => ['Tindak lanjut dijadwalkan', 'calendar'], 'followup_completed' => ['Tindak lanjut diselesaikan', 'check'],
            'lead_created' => ['Lead dibuat', 'users'], 'form_submitted' => ['Form masuk', 'file'],
            'owner_changed' => ['Owner diperbarui', 'users'], 'converted' => ['Lead dikonversi', 'check'],
        ];
        if ($permissions['timeline']) {
            $limit = $request->query('timeline') === 'all' ? 50 : 6;
            $events = DB::table('crm_activities as activity')->leftJoin('users as actor', 'actor.id', '=', 'activity.actor_id')
                ->whereIn('activity.lead_id', $leadIds)->orderByDesc('activity.created_at')->orderByDesc('activity.id')->limit($limit)
                ->get(['activity.event_type', 'activity.summary', 'activity.created_at', 'actor.name as actor_name']);
            $timeline = $events->map(fn (object $event): array => [
                'title' => $activityLabels[$event->event_type][0] ?? ucfirst(str_replace('_', ' ', $event->event_type)),
                'icon' => $activityLabels[$event->event_type][1] ?? 'activity', 'summary' => $event->summary,
                'actor' => $event->actor_name, 'at' => $event->created_at,
            ]);
            $historyIds = DB::table('applications')->whereIn('id', $applications->modelKeys())->whereIn('id', $policy->applicationIds($request->user(), 'candidate.read.timeline'))->pluck('id');
            $histories = DB::table('application_stage_histories as history')->leftJoin('pipeline_stages as stage', 'stage.id', '=', 'history.to_stage_id')
                ->whereIn('history.application_id', $historyIds)->orderByDesc('history.entered_at')->limit($limit)->get(['history.entered_at', 'history.reason', 'stage.name']);
            foreach ($histories as $history) {
                $timeline->push(['title' => 'Tahap lamaran berubah', 'icon' => 'route', 'summary' => $history->name ?: 'Tahap diperbarui', 'actor' => null, 'at' => $history->entered_at]);
            }
            $timeline = $timeline->sortByDesc('at')->take($limit)->values();
            $activityCounts['messages'] = DB::table('crm_messages')->whereIn('conversation_id', DB::table('crm_conversations')->whereIn('lead_id', $leadIds)->select('id'))->whereIn('direction', ['INBOUND', 'OUTBOUND'])->where('created_at', '>=', $since)->count();
            $activityCounts['stages'] = DB::table('crm_activities')->whereIn('lead_id', $leadIds)->where('event_type', 'stage_changed')->where('created_at', '>=', $since)->count()
                + DB::table('application_stage_histories')->whereIn('application_id', $historyIds)->where('entered_at', '>=', $since)->count();
            $activityCounts['followups'] = DB::table('crm_follow_ups')->whereIn('lead_id', $leadIds)->where('created_at', '>=', $since)->count();
            $activityCounts['forms'] = DB::table('lead_submissions')->whereIn('lead_id', $leadIds)->where('is_test', false)->where('created_at', '>=', $since)->count();
        }
        $permissions['health'] = $this->allowed($request, $candidate, 'health.prescreen.read');
        $permissions['documents'] = $this->allowed($request, $candidate, 'document.read.metadata');
        $healthSummary = $permissions['health'] ? Application::whereIn('id', $applications->modelKeys())->whereIn('id', $policy->applicationIds($request->user(), 'health.prescreen.read'))->with('latestHealthInterview')->get() : collect();
        $documentSummary = $permissions['documents'] ? Application::whereIn('id', $applications->modelKeys())->whereIn('id', $policy->applicationIds($request->user(), 'document.read.metadata'))->get()->map(fn (Application $application): array => ['application' => $application, 'summary' => app(DocumentRequirementEngine::class)->summary($application)]) : collect();
        $preferences = $candidate->preferences ?? [];
        $targetCountry = ! empty($preferences['target_country_id']) ? Country::find($preferences['target_country_id'])?->name : $current?->vacancy?->jobOrder?->country?->name;
        $portalAccount = DB::table('portal_accounts')->where('candidate_id', $candidate->id)->first(['status', 'activated_at']);
        $mobilityTimeline = $permissions['timeline'] ? DB::table('mobility_cases')->whereIn('application_id', $historyIds)->latest('id')->limit(10)->get(['id', 'code', 'purpose', 'status', 'created_at']) : collect();

        $dashboard = $this->dashboard($request, $candidate, $current, $leadRows, $permissions, $preferences, $targetCountry, $documentSummary);

        return $dashboard + compact('candidate', 'permissions', 'canEdit', 'canClose', 'applications', 'current', 'leadRows', 'timeline', 'activityCounts', 'healthSummary', 'documentSummary', 'preferences', 'targetCountry', 'portalAccount', 'mobilityTimeline')
            + ['completion' => app(CrmWorkspace::class)->completeness($candidate), 'nextFollowup' => $leadRows->whereNotNull('next_followup_at')->sortBy('next_followup_at')->first()?->next_followup_at];
    }
    private function dashboard(Request $request, Candidate $candidate, ?Application $current, \Illuminate\Support\Collection $leads, array $permissions, array $preferences, ?string $targetCountry, \Illuminate\Support\Collection $documentSummary): array
    {
        $policy = app(AccessPolicy::class);
        $canCommunicate = $permissions['contact'] && $this->allowed($request, $candidate, 'crm.conversation.reply');
        $canPrint = $this->allowed($request, $candidate, 'report.export');
        $manageableLeadIds = app(ConversationCrm::class)->leads($request, 'crm.followup.manage')->where('candidate_id', $candidate->id)->pluck('id');
        $nextActions = $permissions['timeline'] ? DB::table('crm_follow_ups')->whereIn('lead_id', $leads->pluck('id'))->where('status', 'SCHEDULED')->orderBy('due_at')->limit(4)->get()->map(function (object $row) use ($manageableLeadIds): object {
            $row->canManage = $manageableLeadIds->contains($row->lead_id);

            return $row;
        }) : collect();
        $documentDashboard = $current ? $documentSummary->first(fn (array $row): bool => $row['application']->id === $current->id) : null;
        $documentRows = $documentDashboard ? app(DocumentRequirementEngine::class)->rows($current)->take(8) : collect();
        $canMedical = $current && $policy->applicationIds($request->user(), 'health.mcu.summary.read')->where('applications.id', $current->id)->exists();
        $medical = $canMedical ? \App\Models\MedicalEvent::where('application_id', $current->id)->where('status', 'completed')->orderByDesc('completed_at')->orderByDesc('id')->first() : null;
        $canSchedule = $current && $policy->applicationIds($request->user(), 'schedule.read')->where('applications.id', $current->id)->exists();
        $interview = $canSchedule ? $current->interviews()->orderByDesc('scheduled_at')->orderByDesc('id')->first() : null;
        $factors = [
            'documents' => ['label' => 'Dokumen lengkap', 'ready' => $documentDashboard && $documentDashboard['summary']['total'] > 0 && $documentDashboard['summary']['percentage'] === 100, 'visible' => $permissions['documents']],
            'medical' => ['label' => 'Lolos medical checkup', 'ready' => $medical && $medical->clearance_status === 'fit' && $medical->expires_at && $medical->expires_at->endOfDay()->isFuture(), 'visible' => (bool) $canMedical],
            'interview' => ['label' => 'Siap wawancara user', 'ready' => $interview && (($interview->status === 'scheduled' && $interview->scheduled_at?->isFuture()) || ($interview->status === 'completed' && $interview->outcome === 'continue')), 'visible' => (bool) $canSchedule],
            'preferences' => ['label' => 'Preferensi kerja jelas', 'ready' => filled($targetCountry) && filled($preferences['work_sector'] ?? null), 'visible' => true],
            'departure' => ['label' => 'Kesiapan berangkat', 'ready' => ($preferences['departure_readiness'] ?? null) === 'READY', 'visible' => true],
        ];
        $weights = config('candidate-dashboard.readiness_weights');
        $score = (int) round(100 * collect($factors)->sum(fn (array $factor, string $key): int => $factor['visible'] && $factor['ready'] ? $weights[$key] : 0) / max(1, array_sum($weights)));
        $readiness = ['score' => $score, 'label' => $score >= 85 ? 'Sangat Siap' : ($score >= 60 ? 'Dalam Persiapan' : 'Perlu Dilengkapi'), 'factors' => $factors, 'restricted' => collect($factors)->contains(fn (array $factor): bool => ! $factor['visible'])];
        $currentStage = null;
        foreach (config('candidate-dashboard.stages') as $label => $patterns) {
            if ($current && collect($patterns)->contains(fn (string $pattern): bool => str_contains(mb_strtolower($current->currentStage?->code.' '.$current->currentStage?->name), $pattern))) {
                $currentStage = $label;
                break;
            }
        }
        if ($current?->status === 'completed') {
            $currentStage = 'Selesai';
        }
        if (! $current) {
            $currentStage = 'Lead';
        }
        $visited = collect();
        if ($current && $permissions['timeline'] && $policy->applicationIds($request->user(), 'candidate.read.timeline')->where('applications.id', $current->id)->exists()) {
            $names = DB::table('application_stage_histories as history')->join('pipeline_stages as stage', 'stage.id', '=', 'history.to_stage_id')->where('history.application_id', $current->id)->get(['stage.name', 'stage.code']);
            foreach ($names as $stage) {
                foreach (config('candidate-dashboard.stages') as $label => $patterns) {
                    if (collect($patterns)->contains(fn (string $pattern): bool => str_contains(mb_strtolower($stage->code.' '.$stage->name), $pattern))) {
                        $visited->push($label);
                        break;
                    }
                }
            }
        }
        if ($current && $candidate->registered_at) {
            $visited->push('Lead');
        }
        $processStages = collect(array_keys(config('candidate-dashboard.stages')))->map(fn (string $label): array => ['label' => $label, 'current' => $label === $currentStage, 'completed' => $label !== $currentStage && $visited->contains($label)]);
        $source = $leads->first()?->crm_source;

        return compact('canCommunicate', 'canPrint', 'nextActions', 'documentDashboard', 'documentRows', 'readiness', 'processStages', 'source');
    }

    public static function whatsapp(?string $number): ?string
    {
        $digits = preg_replace('/\D/', '', $number ?? '');
        $digits = str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;

        return preg_match('/^[1-9][0-9]{7,14}$/', $digits) ? 'https://wa.me/'.$digits : null;
    }

}
