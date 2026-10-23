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
        $data = $request->validate(['version' => 'required|integer|min:1', 'stages' => 'required|array|min:1|max:20', 'stages.*.key' => 'required|string|alpha_dash|max:60|distinct', 'stages.*.label' => 'required|string|max:100']);
        DB::transaction(function () use ($country, $data): void {
            $template = DB::table('care_journey_templates')->where('country_code', strtoupper($country))->lockForUpdate()->first() ?? abort(404);
            abort_unless($template->version === $data['version'], 409, 'Template telah berubah.');
            DB::table('care_journey_templates')->where('id', $template->id)->update(['stages' => json_encode(collect($data['stages'])->pluck('label', 'key')->all()), 'version' => $template->version + 1, 'updated_at' => now()]);
        });
        app(AccessPolicy::class)->audit($request->user(), 'care.template_updated', ['country' => $country]);

        return back()->with('success', 'Template journey diperbarui.');
    }

    private function stage(Candidate $candidate, string $stage): void
    {
        $application = $candidate->applications()->latest('id')->first();
        abort_unless(array_key_exists($stage, json_decode($this->care->template($application)->stages, true)), 422, 'Tahap tidak tersedia di template negara.');
    }
}
