<?php

namespace App\Http\Controllers;

use App\Services\AccessPolicy;
use App\Services\AttributionService;
use App\Services\ConversationCrm;
use App\Services\CsRouter;
use App\Services\DuplicateResolutionService;
use App\Services\IdentityResolutionService;
use App\Services\LeadUpsertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CrmIdentityController extends Controller
{
    private function pair(Request $request, int $duplicate, string $permission = 'crm.lead.read'): object
    {
        $pair = DB::table('crm_duplicate_candidates')->find($duplicate) ?? abort(404);
        abort_unless(app(ConversationCrm::class)->leads($request, $permission)->whereIn('id', [$pair->primary_lead_id, $pair->secondary_lead_id])->count() === 2, 404);

        return $pair;
    }

    public function index(Request $request): View
    {
        $data = $request->validate(['search' => 'nullable|string|max:100']);
        $visible = app(ConversationCrm::class)->leads($request)->select('id');
        $pairs = DB::table('crm_duplicate_candidates')->where('status', 'PENDING')->whereIn('primary_lead_id', clone $visible)->whereIn('secondary_lead_id', clone $visible);
        if ($data['search'] ?? null) {
            $matching = app(ConversationCrm::class)->leads($request)->where('name', 'like', '%'.$data['search'].'%')->select('id');
            $pairs->where(fn ($q) => $q->whereIn('primary_lead_id', clone $matching)->orWhereIn('secondary_lead_id', clone $matching));
        }

        return view('conversation.duplicates', ['pairs' => $pairs->orderByDesc('confidence')->paginate(20)->withQueryString(), 'comparison' => null]);
    }

    public function compare(Request $request, int $duplicate): View
    {
        $pair = $this->pair($request, $duplicate);
        $leads = app(ConversationCrm::class)->leads($request)->whereIn('id', [$pair->primary_lead_id, $pair->secondary_lead_id])->get();
        $token = (string) Str::uuid();
        $request->session()->put('crm_duplicate_review.'.$duplicate, ['token' => $token, 'versions' => $leads->pluck('crm_version', 'id')->all(), 'expires' => now()->addMinutes(15)->timestamp]);
        $comparison = $leads->map(function ($lead) use ($request): array {
            $contact = app(ConversationCrm::class)->leads($request, 'lead.read.contact')->where('id', $lead->id)->exists();

            return ['id' => $lead->id, 'name' => $lead->name, 'phone' => $contact ? $lead->normalized_phone : 'Terbatas', 'email' => $contact ? $lead->email : 'Terbatas', 'owner' => DB::table('users')->where('id', $lead->assigned_cs_id)->value('name') ?? 'Belum ditugaskan', 'source' => $lead->crm_source ?: 'UNKNOWN', 'candidate' => $lead->candidate_id, 'created_at' => $lead->created_at, 'messages' => DB::table('crm_messages')->whereIn('conversation_id', app(ConversationCrm::class)->conversations($request)->where('lead_id', $lead->id)->select('id'))->count(), 'submissions' => DB::table('lead_submissions')->where('lead_id', $lead->id)->count(), 'interests' => DB::table('crm_lead_interests')->where('lead_id', $lead->id)->count(), 'tags' => DB::table('crm_tags')->whereIn('id', DB::table('crm_lead_tags')->where('lead_id', $lead->id)->select('tag_id'))->pluck('name')->implode(', ')];
        });

        return view('conversation.duplicates', compact('pair', 'comparison', 'token'));
    }

    public function resolve(Request $request, int $duplicate): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('crm.lead.assign') || $request->user()->hasPermission('crm.pipeline.manage'), 403);
        $pair = $this->pair($request, $duplicate, 'crm.lead.edit');
        $data = $request->validate(['action' => 'required|in:merge,ignore', 'primary_id' => 'required|integer', 'reason' => 'required|string|max:255', 'review_token' => 'required|uuid']);
        $review = $request->session()->get('crm_duplicate_review.'.$duplicate);
        abort_unless($review && $review['expires'] >= now()->timestamp && hash_equals($review['token'], $data['review_token']), 409, 'Bandingkan kedua lead sebelum melanjutkan.');
        abort_unless(in_array($data['primary_id'], [$pair->primary_lead_id, $pair->secondary_lead_id], true), 422);
        DB::transaction(function () use ($request, $pair, $data, $review): void {
            $leads = DB::table('acquisition_leads')->whereIn('id', [$pair->primary_lead_id, $pair->secondary_lead_id])->orderBy('id')->lockForUpdate()->get();
            foreach ($leads as $lead) {
                abort_if($lead->crm_version !== $review['versions'][$lead->id], 409, 'Lead berubah. Bandingkan ulang.');
            }
            if ($data['action'] === 'merge') {
                app(DuplicateResolutionService::class)->merge($data['primary_id'], $data['primary_id'] === $pair->primary_lead_id ? $pair->secondary_lead_id : $pair->primary_lead_id, $request->user()->id, $data['reason']);
            } else {
                DB::table('crm_duplicate_candidates')->where('id', $pair->id)->update(['status' => 'IGNORED', 'updated_at' => now()]);
                app(ConversationCrm::class)->activity($data['primary_id'], 'duplicate_ignored', $data['reason'], $request->user()->id);
            }
        });
        $request->session()->forget('crm_duplicate_review.'.$duplicate);

        return to_route('crm.duplicates')->with('success', 'Peninjauan duplikat tersimpan.');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:1024']);
        $file = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($file, 0, ',', '"', '');
        abort_unless(is_array($header), 422, 'CSV kosong.');
        $header = array_map(fn ($value) => trim(ltrim($value, "\xEF\xBB\xBF")), $header);
        abort_unless(! array_diff(['name', 'whatsapp'], $header) && count($header) === count(array_unique($header)), 422, 'Kolom wajib CSV: name,whatsapp; opsional: email,source,vacancy_id.');
        $rows = [];
        while (($row = fgetcsv($file, 0, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            abort_if(count($rows) >= 100 || count($row) !== count($header), 422, 'Maksimal 100 baris per impor; pastikan jumlah kolom sesuai header.');
            $rows[] = array_combine($header, $row);
        }
        fclose($file);
        $success = 0;
        $failed = 0;
        foreach ($rows as $index => $row) {
            try {
                DB::transaction(function () use ($request, $row, $index): void {
                    $data = validator($row, ['name' => 'required|string|max:255', 'whatsapp' => 'required|string|max:30', 'email' => 'nullable|email|max:255', 'source' => 'nullable|string|max:100', 'vacancy_id' => 'nullable|integer|exists:vacancies,id'])->validate();
                    $existing = app(IdentityResolutionService::class)->resolve($data['whatsapp']);
                    if ($existing) {
                        abort_unless(app(ConversationCrm::class)->leads($request, 'crm.lead.edit')->where('id', $existing->id)->exists(), 403);
                    }
                    $lead = app(LeadUpsertService::class)->upsert($data['name'], $data['whatsapp'], $data['email'] ?? null, ($data['source'] ?? null) ?: 'Import');
                    if (! empty($data['vacancy_id'])) {
                        abort_unless(DB::table('vacancies')->where('id', $data['vacancy_id'])->whereIn('id', app(AccessPolicy::class)->vacancyIds($request->user(), 'crm.lead.edit'))->exists(), 403);
                        app(LeadUpsertService::class)->interest($lead->id, $data['vacancy_id']);
                        app(CsRouter::class)->assign($lead->id, $data['vacancy_id']);
                    }
                    if (! $lead->assigned_cs_id && $request->user()->hasPermission('crm.conversation.reply')) {
                        DB::table('acquisition_leads')->where('id', $lead->id)->whereNull('assigned_cs_id')->update(['assigned_cs_id' => $request->user()->id]);
                    }
                    app(AttributionService::class)->record($lead->id, 'import-'.hash_file('sha256', $request->file('file')->getRealPath()).'-'.$index, 'LEAD_IMPORTED', ($data['source'] ?? null) ?: 'Import');
                    app(ConversationCrm::class)->activity($lead->id, 'imported', 'Data impor CSV diperiksa dan dihubungkan ke identitas CRM.', $request->user()->id);
                });
                $success++;
            } catch (ValidationException|HttpException $e) {
                $failed++;
            }
        }
        app(AccessPolicy::class)->audit($request->user(), 'crm.import_completed', ['success' => $success, 'failed' => $failed]);

        return back()->with('success', $success.' baris berhasil; '.$failed.' baris ditolak karena validasi atau cakupan akses.');
    }

    public function campaign(Request $request): View
    {
        $data = $request->validate(['campaign' => 'nullable|string|max:100']);
        $leads = app(ConversationCrm::class)->leads($request, 'marketing.analytics.read');
        if ($campaign = $data['campaign'] ?? null) {
            $leads->whereIn('id', DB::table('crm_attribution_touchpoints')->where('context->utm_campaign', $campaign)->select('lead_id'));
        }
        $ids = (clone $leads)->select('id');
        $applications = DB::table('applications')->whereNull('deleted_at')->whereIn('candidate_id', (clone $leads)->whereNotNull('candidate_id')->select('candidate_id'));
        $counts = ['Lead' => (clone $leads)->count(), 'Qualified / converted' => (clone $leads)->whereIn('crm_stage', ['QUALIFIED', 'CONVERTED'])->count(), 'Candidate' => (clone $leads)->whereNotNull('candidate_id')->distinct()->count('candidate_id'), 'Application' => (clone $applications)->count(), 'Departed' => (clone $applications)->whereIn('id', DB::table('travel_events')->where('event_type', 'departure')->where('status', 'completed')->select('application_id'))->count()];
        $campaigns = DB::table('crm_attribution_touchpoints')->whereIn('lead_id', $ids)->whereNotNull('context->utm_campaign')->select('context')->get()->map(fn ($row) => json_decode($row->context, true)['utm_campaign'] ?? null)->filter()->unique()->sort()->values();

        return view('conversation.campaign', ['counts' => $counts, 'campaigns' => $campaigns, 'rows' => $leads->orderByDesc('id')->paginate(25)->withQueryString()]);
    }

    public function attribution(Request $request, int $lead): View
    {
        app(ConversationCrm::class)->leads($request)->where('id', $lead)->first() ?? abort(404);

        return view('conversation.attribution', ['lead' => $lead, 'summary' => app(AttributionService::class)->summary($lead), 'touches' => DB::table('crm_attribution_touchpoints')->where('lead_id', $lead)->orderByDesc('event_at')->paginate(40)]);
    }
}
