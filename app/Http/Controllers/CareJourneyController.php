<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Services\AccessPolicy;
use App\Services\CareJourney;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CareJourneyController extends Controller
{
    public function __construct(private CareJourney $care) {}

    public function index(Request $request): View
    {
        abort_unless(config('care.enabled'), 404);
        $request->validate(['search' => 'nullable|string|max:100']);
        $candidates = Candidate::with('person')->whereIn('id', app(AccessPolicy::class)->candidateIds($request->user(), 'candidate.read.timeline'))->when($request->filled('search'), fn ($q) => $q->whereHas('person', fn ($p) => $p->where('full_name', 'like', '%'.$request->input('search').'%')))->latest('id')->paginate(20);
        $escalations = DB::table('care_escalations as e')->join('care_issues as i', 'i.id', '=', 'e.issue_id')->whereNotIn('i.status', ['RESOLVED', 'CLOSED'])->whereIn('i.candidate_id', app(AccessPolicy::class)->candidateIds($request->user(), 'incident.read'))->orderByDesc('e.id')->limit(20)->get(['e.*', 'i.candidate_id', 'i.severity', 'i.status']);
        app(AccessPolicy::class)->audit($request->user(), 'care.escalation_inbox_view');

        return view('care.index', compact('candidates', 'escalations'));
    }

    public function show(Request $request, Candidate $candidate): View
    {
        return view('care.show', $this->care->dashboard($request->user(), $candidate));
    }

    public function touchpoint(Request $request, Candidate $candidate): RedirectResponse
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'outcome' => ['required', Rule::in(array_keys(config('care.outcomes')))], 'channel' => 'required|in:CHAT,CALL,IN_PERSON,PORTAL', 'stage' => 'required|string|max:60', 'note' => 'nullable|string|max:1000']);
        $this->stage($candidate, $data['stage']);
        $this->care->touchpoint($request->user(), $candidate, $data);

        return back()->with('success', 'Touchpoint tersimpan. Keluhan welfare diteruskan menjadi insiden Care.');
    }

    public function issue(Request $request, Candidate $candidate): RedirectResponse
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'category' => ['required', Rule::in(array_keys(config('care.issues')))], 'severity' => 'required|in:S1,S2,S3,S4', 'description' => 'required|string|max:2000', 'partner_id' => 'nullable|integer|exists:companies,id']);
        $id = $this->care->openIssue($request->user(), $candidate, $data);

        return back()->with('success', 'Insiden #'.$id.' dibuka. Untuk kondisi darurat, jalankan protokol bantuan medis segera; pencatatan dan biaya tidak menunda pertolongan.');
    }

    public function issueStatus(Request $request, Candidate $candidate, int $issue): RedirectResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'status' => 'required|in:ACKNOWLEDGED,IN_REVIEW,IN_ACTION,MONITORING,RESOLVED,CLOSED', 'medical_action' => 'nullable|string|max:1000', 'outcome' => 'nullable|string|max:1000', 'followup' => 'nullable|string|max:1000', 'candidate_acknowledgement' => 'nullable|string|max:1000']);
        $this->care->transitionIssue($request->user(), $candidate, $issue, $data);

        return back()->with('success', 'Status insiden diperbarui.');
    }

    public function verify(Request $request, Candidate $candidate, int $issue): RedirectResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'verification' => 'required|in:UNDER_REVIEW,SUBSTANTIATED,INCONCLUSIVE,RESOLVED', 'reason' => 'required|string|max:1000']);
        $this->care->verifyIssue($request->user(), $candidate, $issue, $data);

        return back()->with('success', 'Review laporan dicatat.');
    }

    public function support(Request $request, Candidate $candidate): RedirectResponse
    {
        $data = $request->validate(['request_key' => 'required|uuid', 'type' => ['required', Rule::in(array_keys(config('care.support_types')))], 'stage' => 'required|string|max:60', 'reason' => 'nullable|string|max:1000', 'amount_rupiah' => 'nullable|integer|min:0|max:1000000000', 'issue_id' => 'nullable|integer']);
        $this->stage($candidate, $data['stage']);
        $data['amount_minor'] = ($data['amount_rupiah'] ?? 0) * 100;
        $id = $this->care->requestSupport($request->user(), $candidate, $data);

        return back()->with('success', 'Permintaan dukungan #'.$id.' dibuat untuk ditinjau.');
    }

    public function supportStatus(Request $request, Candidate $candidate, int $support): RedirectResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'status' => 'required|in:APPROVED,REJECTED,CANCELLED,SCHEDULED,DISBURSED,CONFIRMED,OUTCOME_REVIEWED', 'reason' => 'required|string|max:1000', 'reference' => 'nullable|string|max:255']);
        $this->care->transitionSupport($request->user(), $candidate, $support, $data);

        return back()->with('success', 'Status dukungan diperbarui.');
    }

    public function decision(Request $request, Candidate $candidate): RedirectResponse
    {
        $this->care->authorize($request->user(), $candidate, 'process.override');
        $data = $request->validate(['request_key' => 'required|uuid', 'vacancy_id' => 'required|integer', 'decision' => 'required|in:ACCEPT,OVERRIDE,DECLINE', 'reason' => 'required|string|max:1000']);
        abort_unless(app(AccessPolicy::class)->vacancyIds($request->user(), 'job_order.read')->where('vacancies.id', $data['vacancy_id'])->exists(), 404);
        $this->care->event($candidate, 'route-decision:'.$data['request_key'], 'route_decision', ['title' => 'Keputusan rute: '.$data['decision']] + $data, actor: $request->user());
        app(AccessPolicy::class)->audit($request->user(), 'care.route_decision', ['candidate_id' => $candidate->id, 'vacancy_id' => $data['vacancy_id'], 'decision' => $data['decision']]);

        return back()->with('success', 'Keputusan rute dicatat. Perubahan lamaran tetap melalui proses penempatan berizin.');
    }

    public function template(Request $request, string $country): RedirectResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'stages' => 'required|array|min:1|max:20', 'stages.*.key' => 'required|string|alpha_dash|max:60|distinct', 'stages.*.label' => 'required|string|max:100', 'policy' => 'nullable|array:consent_required,consent_document_type_id,marital_documents,teto_document_type_ids,post_mcu_lpk_days', 'policy.consent_required' => 'nullable|boolean', 'policy.consent_document_type_id' => 'required_if:policy.consent_required,1|nullable|integer|exists:document_types,id', 'policy.marital_documents' => 'nullable|array:married,divorced,widowed', 'policy.marital_documents.*' => 'nullable|integer|exists:document_types,id', 'policy.teto_document_type_ids' => 'nullable|array|max:30', 'policy.teto_document_type_ids.*' => 'integer|distinct|exists:document_types,id', 'policy.post_mcu_lpk_days' => 'nullable|integer|min:1|max:90']);
        if (isset($data['policy'])) {
            $data['policy']['consent_required'] = (bool) ($data['policy']['consent_required'] ?? false);
            $data['policy']['consent_document_type_id'] = filled($data['policy']['consent_document_type_id'] ?? null) ? (int) $data['policy']['consent_document_type_id'] : null;
            $data['policy']['marital_documents'] = array_map(fn ($id): ?int => filled($id) ? (int) $id : null, $data['policy']['marital_documents'] ?? []);
            $data['policy']['teto_document_type_ids'] = array_map('intval', $data['policy']['teto_document_type_ids'] ?? []);
        }
        DB::transaction(function () use ($country, $data): void {
            $template = DB::table('care_journey_templates')->where('country_code', strtoupper($country))->lockForUpdate()->first() ?? abort(404);
            abort_unless($template->version === $data['version'], 409, 'Template telah berubah.');
            DB::table('care_journey_templates')->where('id', $template->id)->update(['stages' => json_encode(collect($data['stages'])->pluck('label', 'key')->all()), 'policy' => json_encode($data['policy'] ?? []), 'version' => $template->version + 1, 'updated_at' => now()]);
        });
        app(AccessPolicy::class)->audit($request->user(), 'care.template_updated', ['country' => $country]);

        return back()->with('success', 'Template journey diperbarui.');
    }

    public function documentChecks(Request $request, Candidate $candidate, \App\Models\CandidateDocument $document): RedirectResponse
    {
        $this->care->authorize($request->user(), $candidate, 'document.verify');
        abort_unless($document->candidate_id === $candidate->id, 404);
        if ($document->application_id) {
            abort_unless(app(AccessPolicy::class)->applicationIds($request->user(), 'document.verify')->where('applications.id', $document->application_id)->exists(), 404);
        }
        $data = $request->validate(['version' => 'required|integer|min:0', 'checks' => 'required|array:readable,qr_visible,qr_inspected,identity_readable,profile_reconciled', 'checks.readable' => 'required|boolean', 'checks.qr_visible' => 'required|boolean', 'checks.qr_inspected' => 'required|boolean', 'checks.identity_readable' => 'required|boolean', 'checks.profile_reconciled' => 'required|boolean']);
        DB::transaction(function () use ($request, $candidate, $document, $data): void {
            \App\Models\CandidateDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('care_document_checks')->where('candidate_document_id', $document->id)->first();
            abort_unless(($row->version ?? 0) === $data['version'], 409, 'Pemeriksaan telah berubah. Muat ulang.');
            DB::table('care_document_checks')->updateOrInsert(['candidate_document_id' => $document->id], ['checked_by' => $request->user()->id, 'checks' => json_encode($data['checks']), 'version' => $data['version'] + 1, 'created_at' => $row->created_at ?? now(), 'updated_at' => now()]);
            $this->care->event($candidate, 'document-check:'.$document->id.':'.($data['version'] + 1), 'document_inspected', ['title' => 'Checklist inspeksi TETO diperbarui', 'document_id' => $document->id, 'checks' => $data['checks']], 'document.read.metadata', $request->user(), $document->application_id, stage: 'teto');
        });

        return back()->with('success', 'Checklist inspeksi disimpan. QR terlihat tidak membuktikan keaslian dokumen.');
    }

    public function exitReview(Request $request, Candidate $candidate): RedirectResponse
    {
        $this->care->authorize($request->user(), $candidate, 'candidate.close');
        $data = $request->validate(['request_key' => 'required|uuid', 'reason' => ['required', Rule::in(array_keys(config('care.issues')))], 'doubts_started_at' => 'nullable|date|before_or_equal:today', 'preventability' => 'required|in:UNKNOWN,POSSIBLE,UNLIKELY', 'support_needed' => 'nullable|string|max:1000', 'reroute_consent' => 'nullable|boolean', 'note' => 'nullable|string|max:1000']);
        $this->care->event($candidate, 'exit-review:'.$data['request_key'], 'exit_review', ['title' => 'Exit review CPMI'] + $data, 'incident.read', $request->user());
        app(AccessPolicy::class)->audit($request->user(), 'care.exit_review', ['candidate_id' => $candidate->id]);

        return back()->with('success', 'Exit review tersimpan. Hak CPMI untuk berhenti tetap dihormati.');
    }

    public function portalWelfare(Request $request): View|RedirectResponse
    {
        abort_unless(config('care.enabled'), 404);
        $candidate = Candidate::withoutGlobalScopes()->with('person')->findOrFail($request->attributes->get('portal_account')->candidate_id);
        if ($request->isMethod('post')) {
            $data = $request->validate(['request_key' => 'required|uuid', 'status' => ['required', Rule::in(array_keys(config('care.welfare_outcomes')))], 'note' => 'nullable|string|max:1000']);
            $this->care->portalCheck($request->user(), $candidate, $data);

            return to_route('portal.welfare')->with('success', 'Kabar Anda sudah diterima tim JM. Untuk kondisi darurat, segera minta bantuan langsung kepada pendamping atau layanan medis setempat.');
        }

        return view('portal.welfare', ['data' => ['name' => $candidate->person?->full_name]]);
    }

    private function stage(Candidate $candidate, string $stage): void
    {
        $application = $candidate->applications()->latest('id')->first();
        abort_unless(array_key_exists($stage, json_decode($this->care->template($application)->stages, true)), 422, 'Tahap tidak tersedia di template negara.');
    }
}
