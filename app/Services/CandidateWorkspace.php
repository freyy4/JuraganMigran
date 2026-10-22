<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $leadRows = $permissions['timeline'] ? app(\App\Http\Controllers\AcquisitionController::class)->scopedLeads($request, 'candidate.read.timeline')->where('candidate_id', $candidate->id)->get(['id', 'name', 'crm_source', 'crm_stage', 'next_followup_at', 'assigned_cs_id']) : collect();
        $leadIds = $leadRows->pluck('id');
        $timeline = collect();
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
        $targetCountry = !empty($preferences['target_country_id']) ? Country::find($preferences['target_country_id'])?->name : $current?->vacancy?->jobOrder?->country?->name;
        $portalAccount = DB::table('portal_accounts')->where('candidate_id', $candidate->id)->first(['status', 'activated_at']);
        $mobilityTimeline = $permissions['timeline'] ? DB::table('mobility_cases')->whereIn('application_id', $historyIds)->latest('id')->limit(10)->get(['id', 'code', 'purpose', 'status', 'created_at']) : collect();

        return compact('candidate', 'permissions', 'canEdit', 'canClose', 'applications', 'current', 'leadRows', 'timeline', 'activityCounts', 'healthSummary', 'documentSummary', 'preferences', 'targetCountry', 'portalAccount', 'mobilityTimeline')
            + ['completion' => app(CrmWorkspace::class)->completeness($candidate), 'nextFollowup' => $leadRows->whereNotNull('next_followup_at')->sortBy('next_followup_at')->first()?->next_followup_at];
    }
}
