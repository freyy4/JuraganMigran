<?php

namespace App\Http\Controllers;

use App\Models\Vacancy;
use App\Services\AccessPolicy;
use App\Services\ConversationCrm;
use App\Services\ConversionService;
use App\Services\CrmWorkspace;
use App\Services\CsRouter;
use App\Services\TrackingHub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AcquisitionController extends Controller
{
    public function groups(): View
    {
        return view('acquisition.groups', ['groups' => DB::table('cs_groups')->get(), 'members' => DB::table('cs_members')->orderBy('name')->get(), 'mappings' => DB::table('cs_vacancy_mappings')->get(), 'users' => DB::table('users')->where('is_active', true)->select('id', 'name')->get(), 'vacancies' => Vacancy::orderBy('title')->get(), 'events' => DB::table('cs_assignment_events')->latest('id')->paginate(20)]);
    }

    public function saveGroup(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => ['nullable', 'integer', 'exists:cs_groups,id'], 'name' => ['required', 'string', 'max:100', Rule::unique('cs_groups')->ignore($request->input('id'))], 'is_active' => ['required', 'boolean']]);
        $id = $data['id'] ?? null;
        unset($data['id']);
        if ($id) {
            DB::table('cs_groups')->where('id', $id)->update($data + ['updated_at' => now()]);
        } else {
            DB::table('cs_groups')->insert($data + ['created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('success', 'Grup CS disimpan.');
    }

    public function member(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => ['nullable', 'integer', 'exists:cs_members,id'], 'group_id' => ['required', 'integer', 'exists:cs_groups,id'], 'user_id' => ['nullable', 'integer', 'exists:users,id'], 'name' => ['required', 'string', 'max:100'], 'whatsapp' => ['required', 'string', 'max:30'], 'is_active' => ['required', 'boolean']]);
        $data['whatsapp'] = CsRouter::phone($data['whatsapp']);
        $id = $data['id'] ?? null;
        unset($data['id']);
        $data['active_number'] = $data['is_active'] ? $data['whatsapp'] : null;
        $request->merge(['active_number' => $data['active_number']]);
        $request->validate(['active_number' => ['nullable', Rule::unique('cs_members')->ignore($id)]]);
        DB::transaction(function () use ($data, $id): void {
            DB::table('cs_groups')->where('id', $data['group_id'])->lockForUpdate()->first();
            if ($id) {
                DB::table('cs_members')->where('id', $id)->update($data + ['updated_at' => now()]);
            } else {
                DB::table('cs_members')->insert($data + ['created_at' => now(), 'updated_at' => now()]);
            }
        });

        return back()->with('success', 'Anggota CS disimpan.');
    }

    public function mapping(Request $request): RedirectResponse
    {
        $data = $request->validate(['vacancy_id' => ['required', 'integer', 'exists:vacancies,id'], 'group_id' => ['required', 'integer', 'exists:cs_groups,id']]);
        DB::table('cs_vacancy_mappings')->updateOrInsert(['vacancy_id' => $data['vacancy_id']], ['group_id' => $data['group_id'], 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Mapping lowongan disimpan.');
    }

    public function templates(): View
    {
        return view('acquisition.templates', ['templates' => DB::table('whatsapp_templates')->latest('id')->get(), 'vacancies' => Vacancy::orderBy('title')->get()]);
    }

    public function saveTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => ['nullable', 'integer', 'exists:whatsapp_templates,id'], 'name' => ['required', 'string', 'max:255'], 'vacancy_id' => ['nullable', 'integer', 'exists:vacancies,id'], 'body' => ['required', 'string', 'max:5000'], 'status' => ['required', 'in:active,draft,inactive']]);
        preg_match_all('/{{\s*([^}]+)\s*}}/', $data['body'], $matches);
        $allowed = ['name', 'phone', 'vacancy', 'country', 'sponsor', 'stage', 'cs_name', 'candidate_id', 'lead_id', 'application_id', 'funnel_name'];
        foreach ($matches[1] as $variable) {
            if (! in_array(trim($variable), $allowed, true)) {
                throw ValidationException::withMessages(['body' => 'Variabel tidak dikenal: '.$variable]);
            }
        }
        $data['body'] = preg_replace_callback('/{{\s*([^}]+)\s*}}/', fn ($match) => '{{'.trim($match[1]).'}}', $data['body']);
        $id = $data['id'] ?? null;
        unset($data['id']);
        $data['updated_by'] = $request->user()->id;
        if ($id) {
            DB::table('whatsapp_templates')->where('id', $id)->update($data + ['updated_at' => now()]);
        } else {
            DB::table('whatsapp_templates')->insert($data + ['created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('success', 'Template WhatsApp disimpan.');
    }

    public function scopedLeads(Request $request, string $permission = 'lead.read.basic'): object
    {
        $query = DB::table('acquisition_leads')->whereNull('merged_into_id');
        $policies = app(AccessPolicy::class)->policies($request->user(), $permission);
        $query->where(function ($scope) use ($policies, $request): void {
            $scope->whereRaw('1=0');
            foreach ($policies as $policy) {
                if ($policy['scope'] === 'OPERATIONAL_ALL') {
                    $scope->orWhereRaw('1=1');
                } elseif ($policy['scope'] === 'ASSIGNED') {
                    $scope->orWhere('assigned_cs_id', $request->user()->id);
                } elseif ($policy['scope'] === 'TEAM' && $request->user()->team_id) {
                    $scope->orWhereIn('assigned_cs_id', DB::table('users')->where('team_id', $request->user()->team_id)->select('id'));
                } elseif (in_array($policy['scope'], ['VACANCY', 'SPONSOR', 'COUNTRY'])) {
                    $submissions = DB::table('lead_submissions');
                    if ($policy['scope'] === 'VACANCY') {
                        $submissions->whereIn('vacancy_id', $policy['refs']);
                    } else {
                        $submissions->whereIn('vacancy_id', DB::table('vacancies')->join('job_orders', 'job_orders.id', '=', 'vacancies.job_order_id')->whereIn($policy['scope'] === 'SPONSOR' ? 'job_orders.company_id' : 'job_orders.country_id', $policy['refs'])->select('vacancies.id'));
                    }
                    $scope->orWhereIn('id', $submissions->select('lead_id'));
                }
            }
        });

        return $query;
    }

    public function leads(Request $request): View|JsonResponse
    {
        $workspace = app(CrmWorkspace::class);
        $filters = $workspace->filters($request, ['status' => 'nullable|in:new,qualified,converted,disqualified,duplicate_review', 'followup_status' => 'nullable|in:NOT_CONTACTED,DRAFT_OPENED,SENT_CONFIRMED,REPLIED,NO_RESPONSE,ESCALATED', 'source' => 'nullable|string|max:255', 'assigned_cs_id' => 'nullable|integer|exists:users,id', 'vacancy_id' => 'nullable|integer|exists:vacancies,id']);
        $query = $this->scopedLeads($request);
        $query->where('status', ($filters['status'] ?? '') === 'converted' ? '=' : '!=', 'converted');
        foreach (['status', 'followup_status', 'assigned_cs_id'] as $field) {
            if ($filters[$field] ?? '') {
                $query->where($field, $filters[$field]);
            }
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('id', ctype_digit($search) ? (int) $search : -1)->when($request->user()->hasPermission('lead.read.contact'), fn ($q) => $q->orWhere('normalized_phone', 'like', '%'.$search.'%')));
        }
        if (($filters['source'] ?? '') || ($filters['vacancy_id'] ?? '')) {
            $query->whereExists(function ($q) use ($filters): void {
                $q->selectRaw('1')->from('lead_submissions')->whereColumn('lead_submissions.lead_id', 'acquisition_leads.id')->where('is_test', false);
                if ($filters['source'] ?? '') {
                    $q->where('attribution->utm_source', $filters['source']);
                }
                if ($filters['vacancy_id'] ?? '') {
                    $q->where('vacancy_id', $filters['vacancy_id']);
                }
            });
        }
        $workspace->dates($query, $filters, 'acquisition_leads.created_at');
        $summary = ['Lead Baru' => (clone $query)->where('status', 'new')->count(), 'Belum Dihubungi' => (clone $query)->where('followup_status', 'NOT_CONTACTED')->count(), 'Menunggu Balasan' => (clone $query)->whereIn('followup_status', ['SENT_CONFIRMED', 'NO_RESPONSE'])->count(), 'Overdue Follow-up' => (clone $query)->where('next_followup_at', '<', now())->where('followup_status', '!=', 'REPLIED')->count()];
        $query->select('acquisition_leads.*')->selectSub(DB::table('followup_events')->selectRaw('MAX(created_at)')->whereColumn('lead_id', 'acquisition_leads.id'), 'last_activity')->selectSub(DB::table('lead_submissions')->whereColumn('lead_id', 'acquisition_leads.id')->where('is_test', false)->orderByDesc('id')->limit(1)->select('attribution->utm_source'), 'source_label')->selectSub(DB::table('lead_submissions')->join('vacancies', 'vacancies.id', '=', 'lead_submissions.vacancy_id')->whereColumn('lead_id', 'acquisition_leads.id')->where('is_test', false)->orderByDesc('lead_submissions.id')->limit(1)->select('vacancies.title'), 'vacancy_label')->selectSub(DB::table('users')->whereColumn('id', 'acquisition_leads.assigned_cs_id')->select('name')->limit(1), 'cs_name');
        if (($filters['sort'] ?? '') === 'due') {
            $query->orderByRaw('next_followup_at IS NULL')->orderBy('next_followup_at');
        } elseif (($filters['sort'] ?? '') === 'name') {
            $query->orderBy('name');
        } else {
            $query->orderBy('created_at', ($filters['sort'] ?? '') === 'oldest' ? 'asc' : 'desc');
        }
        $rows = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $contact = $this->scopedLeads($request, 'lead.read.contact')->whereIn('id', $rows->pluck('id'))->pluck('id')->all();
        $sources = DB::table('lead_submissions')->where('is_test', false)->whereIn('lead_id', $this->scopedLeads($request)->select('id'))->select('attribution->utm_source as source')->distinct()->limit(50)->pluck('source')->filter()->mapWithKeys(fn ($value) => [$value => $value])->all();

        return $workspace->response($request, 'acquisition.leads', ['leads' => $rows, 'contactIds' => $contact, 'sources' => $sources, 'users' => DB::table('users')->orderBy('name')->get(['id', 'name']), 'vacancies' => DB::table('vacancies')->whereIn('id', app(AccessPolicy::class)->vacancyIds($request->user(), 'lead.read.basic'))->orderBy('title')->limit(200)->get(['id', 'title'])], $rows, $summary, $filters, fn ($lead): array => ['id' => $lead->id, 'name' => $lead->name, 'phone' => in_array($lead->id, $contact) ? $lead->normalized_phone : app(AccessPolicy::class)->mask($lead->normalized_phone), 'source' => $lead->source_label, 'vacancy' => $lead->vacancy_label, 'owner' => $lead->cs_name, 'status' => $lead->status, 'followup_status' => $lead->followup_status, 'next_followup_at' => $lead->next_followup_at]);
    }

    public function detail(Request $request, int $lead): View
    {
        $row = $this->scopedLeads($request)->where('id', $lead)->first() ?? abort(404);
        $allowed = $this->scopedLeads($request, 'lead.read.contact')->where('id', $lead)->exists();

        return view('acquisition.lead-quick', ['lead' => $row, 'phone' => $allowed ? $row->normalized_phone : app(AccessPolicy::class)->mask($row->normalized_phone)]);
    }

    public function schedule(Request $request, int $lead): RedirectResponse
    {
        $this->scopedLeads($request, 'lead.update')->where('id', $lead)->first() ?? abort(404);
        $data = $request->validate(['next_followup_at' => 'required|date_format:Y-m-d\TH:i']);
        DB::transaction(function () use ($request, $lead, $data): void {
            $row = DB::table('acquisition_leads')->where('id', $lead)->lockForUpdate()->first();
            abort_if($row->status === 'converted', 422, 'Lead sudah dikonversi. Jadwalkan tindak lanjut melalui lamaran.');
            $due = Carbon::parse($data['next_followup_at'], 'Asia/Jakarta')->utc();
            DB::table('crm_follow_ups')->insert(['lead_id' => $lead, 'assigned_user_id' => $row->assigned_cs_id ?? $request->user()->id, 'due_at' => $due, 'type' => 'CHAT', 'note' => 'Dijadwalkan melalui Lead Saya.', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('acquisition_leads')->where('id', $lead)->update(['next_followup_at' => $due, 'updated_at' => now()]);
            DB::table('followup_events')->insert(['lead_id' => $lead, 'user_id' => $request->user()->id, 'status' => 'FOLLOWUP_SCHEDULED', 'context' => json_encode(['due_at' => $due->toISOString()]), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Follow-up dijadwalkan. Status pesan tetap mengikuti konfirmasi pengiriman.');
    }

    public function show(Request $request, int $lead): View
    {
        $row = $this->scopedLeads($request)->where('id', $lead)->first() ?? abort(404);

        return view('acquisition.lead', ['lead' => $row, 'submissions' => DB::table('lead_submissions')->where('lead_id', $lead)->where('is_test', false)->latest('id')->get(), 'events' => DB::table('followup_events')->where('lead_id', $lead)->latest('id')->get(), 'members' => $request->user()->hasPermission('lead.assign') ? DB::table('cs_members')->where('is_active', true)->get() : collect()]);
    }

    public function qualify(Request $request, int $lead): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('lead.qualify'), 403);
        $row = $this->scopedLeads($request, 'lead.qualify')->where('id', $lead)->first() ?? abort(404);
        $data = $request->validate(['status' => ['required', 'in:qualified,duplicate_review,new']]);
        abort_if($row->status === 'converted', 422);
        DB::table('acquisition_leads')->where('id', $lead)->update(['status' => $data['status'], 'crm_stage' => $data['status'] === 'qualified' ? 'QUALIFIED' : 'NEW', 'crm_stage_entered_at' => now(), 'crm_version' => DB::raw('crm_version + 1'), 'updated_at' => now()]);
        app(ConversationCrm::class)->activity($lead, 'stage_changed', 'Kualifikasi melalui Lead Saya: '.$data['status'], $request->user()->id);
        if ($data['status'] === 'qualified') {
            app(TrackingHub::class)->leadMilestone($lead, 'QUALIFIED_LEAD', 'lead-'.$lead.'-qualified');
        }

        return back()->with('success', 'Status kualifikasi diperbarui.');
    }

    public function convert(Request $request, int $lead): RedirectResponse
    {
        $this->scopedLeads($request, 'lead.convert_to_candidate')->where('id', $lead)->first() ?? abort(404);
        $request->validate(['submission_id' => ['required', 'integer']]);
        $submission = DB::table('lead_submissions')->where('id', $request->integer('submission_id'))->where('lead_id', $lead)->where('is_test', false)->first() ?? abort(404);
        abort_unless(DB::table('vacancies')->whereIn('id', app(AccessPolicy::class)->vacancyIds($request->user(), 'lead.convert_to_candidate'))->where('id', $submission->vacancy_id)->exists(), 403);
        $application = app(ConversionService::class)->convert($request, $lead, $submission->vacancy_id);

        return to_route('applications.show', $application)->with('success', 'Lead dikonversi ke CPMI dan lamaran.');
    }

    public function reassign(Request $request, int $lead): RedirectResponse
    {
        $this->scopedLeads($request, 'lead.assign')->where('id', $lead)->first() ?? abort(404);
        if (DB::table('crm_conversations')->where('lead_id', $lead)->exists()) {
            throw ValidationException::withMessages(['member_id' => 'Lead sudah memiliki percakapan CRM. Transfer owner melalui Inbox agar kepemilikan dan antrean pesan tetap konsisten.']);
        }
        $data = $request->validate(['member_id' => ['required', 'integer', 'exists:cs_members,id'], 'reason' => ['required', 'string', 'max:255']]);
        DB::transaction(function () use ($lead, $data, $request): void {
            $row = DB::table('acquisition_leads')->where('id', $lead)->lockForUpdate()->first();
            $member = DB::table('cs_members')->where('id', $data['member_id'])->where('is_active', true)->first() ?? abort(422);
            $policies = app(AccessPolicy::class)->policies($request->user(), 'lead.assign');
            if (! collect($policies)->contains(fn ($policy) => $policy['scope'] === 'OPERATIONAL_ALL')) {
                abort_unless($member->user_id && DB::table('users')->where('id', $member->user_id)->where('team_id', $request->user()->team_id)->where('is_active', true)->exists(), 403);
            }
            DB::table('acquisition_leads')->where('id', $lead)->update(['assigned_cs_id' => $member->user_id, 'assigned_member_id' => $member->id, 'updated_at' => now()]);
            if ($row->candidate_id) {
                DB::table('applications')->where('candidate_id', $row->candidate_id)->where('assigned_cs_id', $row->assigned_cs_id)->update(['assigned_cs_id' => $member->user_id, 'updated_at' => now()]);
            }
            DB::table('cs_assignment_events')->insert(['lead_id' => $lead, 'member_id' => $member->id, 'status' => 'reassigned', 'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Penugasan diperbarui dengan alasan tercatat.');
    }

    public function whatsapp(Request $request, int $lead): RedirectResponse
    {
        $row = $this->scopedLeads($request, 'lead.update')->where('id', $lead)->first() ?? abort(404);
        abort_unless($request->user()->hasPermission('lead.read.contact'), 403);
        $submission = DB::table('lead_submissions')->where('lead_id', $lead)->latest('id')->first() ?? abort(404);
        $member = $row->assigned_member_id ? DB::table('cs_members')->find($row->assigned_member_id) : null;
        $vacancy = Vacancy::with(['jobOrder.country', 'jobOrder.company'])->findOrFail($submission->vacancy_id);
        $message = 'Halo '.$row->name.', kami dari Juragan Migran menghubungi Anda mengenai pendaftaran '.$vacancy->title.'.';
        $template = DB::table('whatsapp_templates')->where('status', 'active')->where(fn ($q) => $q->whereNull('vacancy_id')->orWhere('vacancy_id', $vacancy->id))->latest('vacancy_id')->latest('id')->first();
        if ($template) {
            $message = app(CsRouter::class)->message($row, $vacancy, $member, $template->id, $submission);
        }
        DB::table('followup_events')->insert(['lead_id' => $lead, 'user_id' => $request->user()->id, 'status' => 'DRAFT_OPENED', 'created_at' => now(), 'updated_at' => now()]);
        if ($row->followup_status === 'NOT_CONTACTED') {
            DB::table('acquisition_leads')->where('id', $lead)->update(['followup_status' => 'DRAFT_OPENED']);
        }

        return redirect()->away('https://wa.me/'.ltrim($row->normalized_phone, '+').'?text='.rawurlencode($message));
    }

    public function followup(Request $request, int $lead): RedirectResponse
    {
        $this->scopedLeads($request, 'lead.update')->where('id', $lead)->first() ?? abort(404);
        $data = $request->validate(['status' => ['required', 'in:SENT_CONFIRMED,REPLIED,NO_RESPONSE,ESCALATED']]);
        DB::transaction(function () use ($lead, $request, $data): void {
            $row = DB::table('acquisition_leads')->where('id', $lead)->lockForUpdate()->first();
            $allowed = match ($row->followup_status) {
                'NOT_CONTACTED' => ['ESCALATED'],'DRAFT_OPENED' => ['SENT_CONFIRMED', 'ESCALATED'],'SENT_CONFIRMED' => ['REPLIED', 'NO_RESPONSE', 'ESCALATED'],'NO_RESPONSE' => ['REPLIED', 'SENT_CONFIRMED', 'ESCALATED'],default => ['ESCALATED']
            };
            if (! in_array($data['status'], $allowed, true)) {
                throw ValidationException::withMessages(['status' => 'Urutan status follow-up tidak valid. Membuka WhatsApp belum berarti pesan terkirim.']);
            }
            DB::table('acquisition_leads')->where('id', $lead)->update(['followup_status' => $data['status'], 'updated_at' => now()]);
            DB::table('followup_events')->insert(['lead_id' => $lead, 'user_id' => $request->user()->id, 'status' => $data['status'], 'created_at' => now(), 'updated_at' => now()]);
            if ($row->candidate_id) {
                DB::table('timeline_events')->insert(['candidate_id' => $row->candidate_id, 'event_type' => 'whatsapp_followup', 'title' => 'Tindak lanjut WhatsApp: '.$data['status'], 'created_by' => $request->user()->id, 'event_at' => now()]);
            }
        });

        return back()->with('success', 'Status tindak lanjut dicatat.');
    }
}
