<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Company;
use App\Models\Country;
use App\Models\HolidayEvent;
use App\Models\HolidayPolicy;
use App\Models\MobilityCase;
use App\Models\TrainingEnrollment;
use App\Models\User;
use App\Services\AccessPolicy;
use App\Services\HolidayEligibility;
use App\Services\MobilityService;
use App\Services\QrCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class HolidayMobilityController extends Controller
{
    public function __construct(private HolidayEligibility $eligibility, private MobilityService $mobility) {}

    private function ids(Request $request, string $permission): object
    {
        if ($account = $request->attributes->get('portal_account')) {
            return DB::table('applications')->where('candidate_id', $account->candidate_id)->whereNull('deleted_at')->select('id');
        }

        return app(AccessPolicy::class)->applicationIds($request->user(), $permission);
    }

    private function application(Request $request, int $id, string $permission): Application
    {
        return Application::withoutGlobalScope('access')->whereIn('id', $this->ids($request, $permission))->with(['candidate.person', 'vacancy.jobOrder.country', 'vacancy.jobOrder.company', 'assignedCs'])->findOrFail($id);
    }

    private function respond(Request $r, array $data, string $message): JsonResponse|RedirectResponse
    {
        return ($r->expectsJson() || $r->is('api/*')) ? response()->json($data) : back()->with('success', $message);
    }

    private function stamp(string $time): Carbon
    {
        return Carbon::parse($time, 'Asia/Jakarta')->utc();
    }

    private function filterApplications(Request $request, Builder $query): Builder
    {
        if ($request->filled('search')) {
            $term = '%'.$request->input('search').'%';
            $query->where(fn ($q) => $q->where('application_code', 'like', $term)->orWhereHas('candidate', fn ($c) => $c->where('candidate_code', 'like', $term)->orWhereHas('person', fn ($p) => $p->where('full_name', 'like', $term))));
        }
        foreach (['country_id' => 'country_id', 'company_id' => 'company_id'] as $input => $column) {
            if ($request->filled($input)) {
                $query->whereHas('vacancy.jobOrder', fn ($q) => $q->where($column, $request->integer($input)));
            }
        }
        if ($request->filled('cs_id')) {
            $query->where('assigned_cs_id', $request->integer('cs_id'));
        }
        if ($request->filled('lpk_id')) {
            $query->whereHas('trainingEnrollments', fn ($q) => $q->withoutGlobalScope('access')->whereHas('batch', fn ($b) => $b->where('company_id', $request->integer('lpk_id'))));
        }
        if ($request->filled('departure_before')) {
            $query->whereIn('id', DB::table('flight_details')->where('departure_at', '<=', Carbon::parse($request->input('departure_before'), 'Asia/Jakarta')->endOfDay()->utc())->select('application_id'));
        }

        return $query;
    }

    public function index(Request $r): View|JsonResponse
    {
        $r->validate(['search' => 'nullable|string|max:100', 'holiday_id' => 'nullable|integer|exists:holiday_events,id', 'status' => 'nullable|string|max:40', 'country_id' => 'nullable|integer|exists:countries,id', 'company_id' => 'nullable|integer|exists:companies,id', 'cs_id' => 'nullable|integer|exists:users,id', 'lpk_id' => 'nullable|integer|exists:companies,id', 'departure_before' => 'nullable|date'], config('site.validation_messages'));
        $permission = 'holiday.read';
        $ids = $this->ids($r, $permission);
        $apps = Application::withoutGlobalScope('access')->whereIn('id', $ids)->whereHas('trainingEnrollments', fn ($q) => $q->withoutGlobalScope('access')->whereIn('status', ['ongoing', 'on_hold']))->with(['candidate.person', 'vacancy.jobOrder.country', 'assignedCs']);
        $this->filterApplications($r, $apps);
        $filteredIds = $this->filterApplications($r, Application::withoutGlobalScope('access')->whereIn('id', $ids))->select('id');
        $applications = $apps->latest('id')->paginate(20)->withQueryString();
        $holiday = $r->filled('holiday_id') ? HolidayEvent::findOrFail($r->integer('holiday_id')) : HolidayEvent::where('status', 'ACTIVE')->latest('id')->first();
        $eligibilities = [];
        foreach ($applications as $app) {
            if ($holiday) {
                $result = $this->eligibility->evaluate($app, $holiday);
                $eligibilities[$app->id] = $result;
                DB::table('holiday_eligibilities')->updateOrInsert(['application_id' => $app->id, 'holiday_event_id' => $holiday->id], ['result' => $result['result'], 'snapshot' => json_encode($result), 'evaluated_at' => now()]);
            }
        }
        $leaves = DB::table('training_leave_requests as l')->join('training_enrollments as e', 'e.id', '=', 'l.training_enrollment_id')->join('applications as a', 'a.id', '=', 'e.application_id')->join('candidates as c', 'c.id', '=', 'a.candidate_id')->join('persons as p', 'p.id', '=', 'c.person_id')->whereIn('a.id', $filteredIds)->whereNotNull('l.holiday_event_id')->select('l.*', 'p.full_name', 'a.application_code', 'a.id as application_id');
        if ($r->filled('status')) {
            $leaves->where('l.journey_status', $r->input('status'));
        }
        $leaves = $leaves->latest('l.id')->paginate(20, ['*'], 'leave_page')->withQueryString();
        $mobilityIds = $this->ids($r, 'mobility.read');
        $cases = MobilityCase::whereIn('application_id', $this->filterApplications($r, Application::withoutGlobalScope('access')->whereIn('id', $mobilityIds))->select('id'))->with('application.candidate.person')->latest('id')->paginate(15, ['*'], 'mobility_page')->withQueryString();
        $stats = ['active' => TrainingEnrollment::withoutGlobalScope('access')->whereIn('application_id', $ids)->where('status', 'ongoing')->count(), 'holidays' => HolidayEvent::where('status', 'ACTIVE')->count(), 'overdue' => DB::table('training_leave_requests')->whereIn('training_enrollment_id', DB::table('training_enrollments')->whereIn('application_id', $ids)->select('id'))->where('journey_status', 'OVERDUE_RETURN')->count(), 'qr_pending' => DB::table('qr_passes as q')->join('mobility_legs as l', 'l.id', '=', 'q.mobility_leg_id')->join('mobility_cases as m', 'm.id', '=', 'l.mobility_case_id')->whereIn('m.application_id', $mobilityIds)->whereNull('q.used_at')->whereNull('q.revoked_at')->where('q.expires_at', '>', now())->count()];
        $evaluations = DB::table('holiday_eligibilities')->whereIn('application_id', $ids)->when($holiday, fn ($q) => $q->where('holiday_event_id', $holiday->id));
        $stats['locked'] = (clone $evaluations)->where('result', 'NOT_ALLOWED')->count();
        $stats['allowed'] = (clone $evaluations)->where('result', 'ALLOWED')->count();
        $travel = DB::table('mobility_legs as l')->join('mobility_cases as m', 'm.id', '=', 'l.mobility_case_id')->whereIn('m.application_id', $mobilityIds)->whereNotIn('l.status', ['CANCELLED', 'CHECKED_IN']);
        $stats['pickup'] = (clone $travel)->where('l.transport_mode', 'JM_PICKUP_SERVICE')->count();
        $stats['self'] = (clone $travel)->where('l.transport_mode', 'SELF_TRAVEL')->count();
        $month = $holiday?->start_date?->copy() ?? now('Asia/Jakarta')->startOfMonth();
        $calendarDays = [];
        $day = $month->copy()->startOfMonth()->startOfWeek(0);
        $last = $month->copy()->endOfMonth()->endOfWeek(6);
        while ($day->lte($last)) {
            $calendarDays[] = ['day' => $day->day, 'current' => $day->month === $month->month, 'holiday' => $holiday && $day->toDateString() >= $holiday->start_date->toDateString() && $day->toDateString() <= $holiday->end_date->toDateString(), 'date' => $day->toDateString()];
            $day->addDay();
        }
        $data = compact('applications', 'holiday', 'eligibilities', 'leaves', 'cases', 'stats', 'calendarDays', 'month') + ['partners' => Company::orderBy('name')->get(['id', 'name']), 'holidays' => HolidayEvent::latest('id')->get(), 'policies' => HolidayPolicy::where('active', true)->get(), 'lpks' => Company::whereIn('type', ['LPK', 'lpk'])->orderBy('name')->get(), 'countries' => Country::where('is_active', true)->get(), 'users' => User::where('is_active', true)->get(), 'points' => DB::table('pickup_points')->when(! $r->user()->hasPermission('transport.route.manage'), fn ($q) => $q->where('active', true))->get(), 'batches' => DB::table('transport_batches')->latest('id')->paginate(15, ['*'], 'batch_page'), 'alerts' => DB::table('operations_alerts')->whereIn('application_id', $ids)->whereNull('resolved_at')->latest('id')->limit(20)->get()];

        return ($r->expectsJson() || $r->is('api/*')) ? response()->json(['applications' => $applications, 'eligibilities' => $eligibilities, 'stats' => $stats, 'leaves' => $leaves, 'cases' => $cases]) : view('operations.holiday-transport', $data);
    }

    public function policy(Request $r): JsonResponse|RedirectResponse
    {
        if (is_array($r->input('scope'))) {
            $r->merge(['scope' => array_filter($r->input('scope'), fn ($v) => $v !== null && $v !== '')]);
        }
        $data = $r->validate(['name' => 'required|string|max:255', 'scope' => 'nullable|array:country_id,sponsor_id,vacancy_id,job_order_id,lpk_id', 'scope.*' => 'integer|min:1', 'rules' => 'required|array|min:1|max:20', 'rules.*.condition' => ['required', Rule::in(['departure_within_days', 'departure_before_return', 'readiness_incomplete', 'departure_unknown'])], 'rules.*.days' => 'nullable|integer|min:0|max:365', 'rules.*.result' => 'required|in:ALLOWED,RESTRICTED,NOT_ALLOWED,REQUIRES_REVIEW', 'rules.*.reason' => 'required|string|max:1000'], config('site.validation_messages'));
        foreach ($data['rules'] as $rule) {
            if ($rule['condition'] === 'departure_within_days' && ! isset($rule['days'])) {
                throw ValidationException::withMessages(['rules' => 'Isi batas hari untuk aturan kedekatan keberangkatan.']);
            }
        }
        $policy = HolidayPolicy::create($data + ['scope' => $data['scope'] ?? [], 'version' => HolidayPolicy::where('name', $data['name'])->max('version') + 1, 'created_by' => $r->user()->id]);
        app(AccessPolicy::class)->audit($r->user(), 'holiday.policy_created', ['policy_id' => $policy->id]);

        return $this->respond($r, ['id' => $policy->id], 'Versi kebijakan baru tersimpan.');
    }

    public function holiday(Request $r, ?int $holiday = null): JsonResponse|RedirectResponse
    {
        $data = $r->validate(['name' => 'required|string|max:255', 'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date', 'return_deadline' => 'required|date|after_or_equal:end_date', 'holiday_type' => 'required|string|max:100', 'lpk_ids' => 'required|array|min:1', 'lpk_ids.*' => ['integer', Rule::exists('companies', 'id')->whereIn('type', ['LPK', 'lpk'])->whereNull('deleted_at')], 'country_ids' => 'nullable|array', 'country_ids.*' => 'integer|exists:countries,id', 'policy_id' => 'required|integer|exists:holiday_policies,id', 'status' => 'required|in:DRAFT,ACTIVE,CLOSED', 'notes' => 'nullable|string|max:5000'], config('site.validation_messages'));
        $data['return_deadline'] = $this->stamp($data['return_deadline']);
        $event = $holiday ? HolidayEvent::findOrFail($holiday) : new HolidayEvent;
        $event->fill($data)->save();
        app(AccessPolicy::class)->audit($r->user(), 'holiday.saved', ['holiday_id' => $event->id]);

        return $this->respond($r, ['id' => $event->id], 'Periode libur disimpan. Libur tidak otomatis menyetujui izin pulang.');
    }

    public function leave(Request $r): JsonResponse|RedirectResponse
    {
        $data = $r->validate(['application_id' => 'required|integer', 'holiday_event_id' => 'required|integer|exists:holiday_events,id', 'start_at' => 'required|date', 'end_at' => 'required|date|after:start_at', 'destination_city' => 'required|string|max:255', 'emergency_contact' => 'required|string|max:500', 'reason' => 'required|string|max:5000', 'override_request_reason' => 'nullable|string|max:3000', 'outbound_mode' => ['required', Rule::in(array_keys(config('operations.modes')))], 'inbound_mode' => ['required', Rule::in(array_keys(config('operations.modes')))]], config('site.validation_messages'));
        $app = $this->application($r, $data['application_id'], 'leave.request');
        $id = DB::transaction(function () use ($r, $data, $app): int {
            DB::table('applications')->where('id', $app->id)->lockForUpdate()->first();
            $holiday = HolidayEvent::whereKey($data['holiday_event_id'])->lockForUpdate()->firstOrFail();
            abort_unless($holiday->status === 'ACTIVE', 422, 'Libur belum aktif.');
            $start = $this->stamp($data['start_at']);
            $end = $this->stamp($data['end_at']);
            if ($start->lt($holiday->start_date->timezone('Asia/Jakarta')->startOfDay()->utc()) || $end->gt($holiday->return_deadline) || $start->isPast()) {
                throw ValidationException::withMessages(['end_at' => 'Jadwal harus dalam periode libur dan kembali sebelum batas wajib.']);
            }
            $result = $this->eligibility->evaluate($app, $holiday, $end);
            if ($result['result'] === 'NOT_ALLOWED') {
                if ($r->attributes->has('portal_account') || ! $r->user()->hasPermission('leave.override') || empty($data['override_request_reason'])) {
                    throw ValidationException::withMessages(['application_id' => $result['reason']]);
                }
                $this->application($r, $app->id, 'leave.override');
                $result['exception_request'] = ['reason' => $data['override_request_reason'], 'requested_by' => $r->user()->id, 'at' => now()->toISOString()];
                app(AccessPolicy::class)->audit($r->user(), 'leave.exception_requested', ['application_id' => $app->id, 'holiday_id' => $holiday->id, 'reason' => $data['override_request_reason']]);
            }
            $enrollment = DB::table('training_enrollments')->where('application_id', $app->id)->whereIn('status', ['ongoing', 'on_hold'])->latest('id')->first() ?? abort(422);
            if (DB::table('training_leave_requests')->where('training_enrollment_id', $enrollment->id)->where('start_at', '<', $end)->where('end_at', '>', $start)->whereIn('approval_status', ['pending', 'approved'])->whereNull('return_confirmed_at')->exists()) {
                throw ValidationException::withMessages(['start_at' => 'Sudah ada pengajuan izin yang bertumpang tindih.']);
            }
            $id = DB::table('training_leave_requests')->insertGetId(['training_enrollment_id' => $enrollment->id, 'holiday_event_id' => $holiday->id, 'leave_type' => 'permission', 'start_at' => $start, 'end_at' => $end, 'mandatory_return_at' => $end, 'reason' => $data['reason'], 'destination_city' => $data['destination_city'], 'emergency_contact' => Crypt::encryptString($data['emergency_contact']), 'eligibility_snapshot' => json_encode($result), 'journey_status' => 'PLANNED', 'approval_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $case = $this->mobility->create($app, ['leave_request_id' => $id, 'purpose' => 'HOLIDAY', 'origin' => 'LPK', 'destination' => $data['destination_city'], 'appointment_at' => $start, 'transport_mode' => $data['outbound_mode']], $r->attributes->has('portal_account') ? null : $r->user()->id);
            DB::table('mobility_legs')->insert(['mobility_case_id' => $case->id, 'direction' => 'INBOUND', 'transport_mode' => $data['inbound_mode'], 'origin' => $data['destination_city'], 'destination' => 'LPK', 'scheduled_at' => $end, 'eta' => $end, 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });

        return $this->respond($r, ['id' => $id], 'Pengajuan izin dan rencana pergi/kembali tersimpan, menunggu persetujuan.');
    }

    public function review(Request $r, int $leave): JsonResponse|RedirectResponse
    {
        $data = $r->validate(['action' => 'required|in:approve,reject,cancel', 'override_reason' => 'nullable|string|max:3000', 'revised_return_at' => 'nullable|date', 'candidate_acknowledged' => 'sometimes|accepted'], config('site.validation_messages'));
        DB::transaction(function () use ($r, $data, $leave): void {
            $row = DB::table('training_leave_requests')->where('id', $leave)->lockForUpdate()->first() ?? abort(404);
            abort_unless($row->holiday_event_id, 422);
            $enrollment = DB::table('training_enrollments')->find($row->training_enrollment_id);
            $app = $this->application($r, $enrollment->application_id, 'leave.review');
            $holiday = HolidayEvent::findOrFail($row->holiday_event_id);
            abort_unless($row->approval_status === 'pending' || $data['action'] === 'cancel', 422, 'Pengajuan sudah diputuskan.');
            $returnAt = ! empty($data['revised_return_at']) ? $this->stamp($data['revised_return_at']) : Carbon::parse($row->mandatory_return_at);
            $originalResult = $this->eligibility->evaluate($app, $holiday, Carbon::parse($row->mandatory_return_at));
            $result = $this->eligibility->evaluate($app, $holiday, $returnAt);
            $override = null;
            if ($data['action'] === 'approve') {
                abort_unless(in_array($enrollment->status, ['ongoing', 'on_hold']) && $holiday->status === 'ACTIVE', 422, 'Peserta atau libur sudah tidak aktif.');
                if ($returnAt->lte(Carbon::parse($row->start_at)) || $returnAt->isPast()) {
                    throw ValidationException::withMessages(['revised_return_at' => 'Batas kembali tidak valid.']);
                }
                if ($originalResult['result'] !== 'ALLOWED' || $result['result'] !== 'ALLOWED' || $returnAt->gt($holiday->return_deadline)) {
                    abort_unless($r->user()->hasPermission('leave.override'), 403);
                    $this->application($r, $app->id, 'leave.override');
                    if (empty($data['override_reason']) || ! $r->boolean('candidate_acknowledged') || empty($data['revised_return_at'])) {
                        throw ValidationException::withMessages(['override_reason' => 'Pengecualian memerlukan alasan, batas kembali, dan konfirmasi bahwa CPMI telah memahami perubahan.']);
                    }
                    $override = ['original_result' => $originalResult, 'requested_snapshot' => json_decode($row->eligibility_snapshot, true), 'revised_result' => $result, 'result' => 'EXCEPTION_APPROVED', 'reason' => $data['override_reason'], 'approver' => $r->user()->id, 'at' => now()->toISOString(), 'return_at' => $returnAt->toISOString()];
                }
            }
            DB::table('training_leave_requests')->where('id', $leave)->update(['approval_status' => $data['action'] === 'approve' ? 'approved' : 'rejected', 'journey_status' => $data['action'] === 'approve' ? 'APPROVED' : 'CANCELLED', 'approved_by' => $r->user()->id, 'eligibility_snapshot' => json_encode($result), 'override_snapshot' => $override ? json_encode($override) : null, 'mandatory_return_at' => $returnAt, 'end_at' => $returnAt, 'candidate_acknowledged_at' => $override ? now() : null, 'updated_at' => now()]);
            $case = MobilityCase::where('leave_request_id', $leave)->first();
            if ($case) {
                $case->update(['status' => $data['action'] === 'approve' ? 'APPROVED' : 'CANCELLED']);
                DB::table('mobility_legs')->where('mobility_case_id', $case->id)->update(['status' => $data['action'] === 'approve' ? 'APPROVED' : 'CANCELLED', 'updated_at' => now()]);
                if ($data['action'] !== 'approve') {
                    DB::table('qr_passes')->whereIn('mobility_leg_id', DB::table('mobility_legs')->where('mobility_case_id', $case->id)->select('id'))->whereNull('revoked_at')->update(['revoked_at' => now()]);
                } else {
                    DB::table('mobility_legs')->where('mobility_case_id', $case->id)->where('direction', 'INBOUND')->update(['eta' => $returnAt, 'scheduled_at' => $returnAt, 'updated_at' => now()]);
                }
            }
            app(AccessPolicy::class)->audit($r->user(), $override ? 'leave.exception_approved' : 'leave.reviewed', ['leave_id' => $leave, 'decision' => $data['action'], 'override' => $override]);
        });

        return $this->respond($r, ['ok' => true], 'Keputusan izin tersimpan.');
    }

    public function storeMobility(Request $r): JsonResponse|RedirectResponse
    {
        $data = $r->validate(['application_id' => 'required|integer', 'purpose' => ['required', Rule::in(array_keys(config('operations.purposes')))], 'origin' => 'required|string|max:255', 'destination' => 'required|string|max:255', 'appointment_at' => 'required|date', 'transport_mode' => ['required', Rule::in(array_keys(config('operations.modes')))], 'route_notes' => 'nullable|string|max:5000', 'contact_name' => 'nullable|string|max:255', 'contact_number' => 'nullable|string|max:30'], config('site.validation_messages'));
        abort_if($data['purpose'] === 'HOLIDAY', 422, 'Gunakan pengajuan izin untuk perjalanan libur.');
        $app = $this->application($r, $data['application_id'], 'mobility.manage');
        $data['appointment_at'] = $this->stamp($data['appointment_at']);
        $case = $this->mobility->create($app, $data, $r->user()->id);
        app(AccessPolicy::class)->audit($r->user(), 'mobility.created', ['case_id' => $case->id]);

        return ($r->expectsJson() || $r->is('api/*')) ? response()->json(['id' => $case->id]) : to_route('mobility.show', $case);
    }

    public function show(Request $r, MobilityCase $mobility): View|JsonResponse
    {
        $app = $this->application($r, $mobility->application_id, 'mobility.read');
        $legs = DB::table('mobility_legs as l')->leftJoin('pickup_points as p', 'p.id', '=', 'l.pickup_point_id')->leftJoin('transport_batches as b', 'b.id', '=', 'l.batch_id')->where('l.mobility_case_id', $mobility->id)->select('l.*', 'p.name as pickup_name', 'p.address as pickup_address', 'p.instructions', 'b.code as batch_code', 'b.route_name', 'b.vehicle', 'b.pic_id')->get();
        $passes = DB::table('qr_passes')->whereIn('mobility_leg_id', $legs->pluck('id'))->latest('id')->get()->map(function ($pass): array {
            return ['id' => $pass->id, 'reference' => $pass->reference, 'status' => $this->mobility->qrStatus($pass), 'leg_id' => $pass->mobility_leg_id, 'url' => route('qr.public', Crypt::decryptString($pass->encrypted_token)), 'image' => route('qr.image', Crypt::decryptString($pass->encrypted_token)), 'expires_at' => $pass->expires_at];
        });
        $handover = DB::table('mobility_handover_items')->where('mobility_case_id', $mobility->id)->get();
        $custodies = DB::table('original_document_custodies as c')->join('candidate_documents as d', 'd.id', '=', 'c.candidate_document_id')->join('document_types as t', 't.id', '=', 'd.document_type_id')->where('d.candidate_id', $app->candidate_id)->where('c.status', '!=', 'returned')->select('c.id', 'c.current_holder', 't.name')->get();
        $data = compact('mobility', 'app', 'legs', 'passes', 'handover', 'custodies') + ['points' => DB::table('pickup_points')->when(! $r->user()->hasPermission('transport.route.manage'), fn ($q) => $q->where('active', true))->get(), 'batches' => DB::table('transport_batches')->whereNotIn('status', ['CLOSED', 'CANCELLED'])->get()];
        if (($r->expectsJson() || $r->is('api/*'))) {
            return response()->json(['mobility' => $mobility, 'legs' => $legs, 'passes' => $passes]);
        }
        if ($r->attributes->has('portal_account')) {
            return view('operations.portal-travel', $data + ['data' => ['name' => $app->candidate?->person?->full_name]]);
        }

        return view('operations.mobility', $data);
    }

    public function updateMobility(Request $r, MobilityCase $mobility): JsonResponse|RedirectResponse
    {
        $this->application($r, $mobility->application_id, 'mobility.manage');
        $data = $r->validate(['origin' => 'required|string|max:255', 'destination' => 'required|string|max:255', 'appointment_at' => 'required|date', 'route_notes' => 'nullable|string|max:5000', 'contact_name' => 'nullable|string|max:255', 'contact_number' => 'nullable|string|max:30'], config('site.validation_messages'));
        DB::transaction(function () use ($mobility, $data): void {
            $case = MobilityCase::whereKey($mobility->id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($case->status, ['CLOSED', 'CANCELLED']), 422, 'Perjalanan sudah ditutup.');
            $data['appointment_at'] = $this->stamp($data['appointment_at']);
            $case->update($data);
            DB::table('mobility_legs')->where('mobility_case_id', $case->id)->where('direction', 'OUTBOUND')->whereNotIn('status', ['CHECKED_IN', 'CANCELLED'])->update(['origin' => $case->origin, 'destination' => $case->destination, 'updated_at' => now()]);
        });
        app(AccessPolicy::class)->audit($r->user(), 'mobility.updated', ['case_id' => $mobility->id]);

        return $this->respond($r, ['ok' => true], 'Informasi perjalanan diperbarui.');
    }

    public function leg(Request $r, int $leg): JsonResponse|RedirectResponse
    {
        $data = $r->validate(['status' => 'required|in:PLANNED,APPROVED,TRAVELLING,DELAYED,NO_SHOW,CANCELLED', 'batch_id' => 'nullable|integer|exists:transport_batches,id', 'pickup_point_id' => ['nullable', 'integer', Rule::exists('pickup_points', 'id')->where('active', true)], 'pickup_override' => 'nullable|string|max:255', 'pickup_sequence' => 'nullable|integer|min:1|max:1000', 'scheduled_at' => 'required|date', 'route_notes' => 'nullable|string|max:5000'], config('site.validation_messages'));
        DB::transaction(function () use ($r, $data, $leg): void {
            $row = DB::table('mobility_legs')->where('id', $leg)->lockForUpdate()->first() ?? abort(404);
            $case = MobilityCase::findOrFail($row->mobility_case_id);
            $this->application($r, $case->application_id, 'mobility.manage');
            abort_if($row->status === 'CHECKED_IN', 422, 'Perjalanan sudah check-in.');
            if ($case->leave_request_id && $data['status'] !== 'CANCELLED') {
                abort_unless(DB::table('training_leave_requests')->where('id', $case->leave_request_id)->where('approval_status', 'approved')->exists(), 422, 'Izin pulang belum disetujui.');
            }
            if (! empty($data['batch_id'])) {
                abort_unless($r->user()->hasPermission('transport.batch.manage'), 403);
                $batch = DB::table('transport_batches')->where('id', $data['batch_id'])->lockForUpdate()->first();
                abort_if(in_array($batch->status, ['CLOSED', 'CANCELLED']), 422);
                $count = DB::table('mobility_legs')->where('batch_id', $batch->id)->where('id', '!=', $leg)->where('status', '!=', 'CANCELLED')->count();
                if ($count >= $batch->capacity) {
                    throw ValidationException::withMessages(['batch_id' => 'Kapasitas perjalanan sudah penuh.']);
                }
            }
            $update = $data;
            unset($update['route_notes']);
            $update['scheduled_at'] = $this->stamp($data['scheduled_at']);
            DB::table('mobility_legs')->where('id', $leg)->update($update + ['updated_at' => now()]);
            $case->update(['route_notes' => $data['route_notes'] ?? $case->route_notes]);
            if ($case->leave_request_id) {
                $state = $data['status'] === 'TRAVELLING' ? ($row->direction === 'INBOUND' ? 'RETURNING' : 'LEFT_LPK') : null;
                if ($state) {
                    DB::table('training_leave_requests')->where('id', $case->leave_request_id)->update(['journey_status' => $state, 'updated_at' => now()]);
                }
            }
            if ($data['status'] === 'CANCELLED') {
                DB::table('qr_passes')->where('mobility_leg_id', $leg)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                if (! DB::table('mobility_legs')->where('mobility_case_id', $case->id)->where('status', '!=', 'CANCELLED')->exists()) {
                    $case->update(['status' => 'CANCELLED']);
                }
            }
            app(AccessPolicy::class)->audit($r->user(), 'mobility.leg_updated', ['leg_id' => $leg, 'status' => $data['status']]);
        });

        return $this->respond($r, ['ok' => true], 'Perjalanan diperbarui.');
    }

    public function point(Request $r, ?int $point = null): JsonResponse|RedirectResponse
    {
        $data = $r->validate(['name' => 'required|string|max:255', 'address' => 'required|string|max:500', 'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180', 'instructions' => 'nullable|string|max:3000', 'contact_number' => 'nullable|string|max:30', 'safe_meeting_point' => 'sometimes|boolean', 'active' => 'sometimes|boolean'], config('site.validation_messages'));
        $data += ['safe_meeting_point' => $r->boolean('safe_meeting_point'), 'active' => $r->boolean('active')];
        if ($point) {
            abort_unless(DB::table('pickup_points')->where('id', $point)->exists(), 404);
            DB::table('pickup_points')->where('id', $point)->update($data + ['updated_at' => now()]);
            $id = $point;
        } else {
            $id = DB::table('pickup_points')->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        }
        app(AccessPolicy::class)->audit($r->user(), 'pickup.saved', ['point_id' => $id]);

        return $this->respond($r, ['id' => $id], 'Titik jemput disimpan.');
    }

    public function batch(Request $r, ?int $batch = null): JsonResponse|RedirectResponse
    {
        $data = $r->validate(['route_name' => 'required|string|max:255', 'destination' => 'required|string|max:255', 'pickup_at' => 'required|date', 'eta' => 'nullable|date|after_or_equal:pickup_at', 'pic_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)], 'vehicle' => 'nullable|string|max:255', 'capacity' => 'required|integer|min:1|max:100', 'route_notes' => 'nullable|string|max:5000', 'status' => 'required|in:PLANNED,READY,TRAVELLING,CLOSED,CANCELLED'], config('site.validation_messages'));
        $data['pickup_at'] = $this->stamp($data['pickup_at']);
        $data['eta'] = ! empty($data['eta']) ? $this->stamp($data['eta']) : null;
        $id = DB::transaction(function () use ($data, $batch): int {
            if ($batch) {
                $row = DB::table('transport_batches')->where('id', $batch)->lockForUpdate()->first() ?? abort(404);
                if (DB::table('mobility_legs')->where('batch_id', $batch)->where('status', '!=', 'CANCELLED')->count() > $data['capacity']) {
                    throw ValidationException::withMessages(['capacity' => 'Kapasitas lebih kecil dari jumlah peserta.']);
                }
                DB::table('transport_batches')->where('id', $batch)->update($data + ['updated_at' => now()]);

                return $batch;
            }

            return DB::table('transport_batches')->insertGetId($data + ['code' => 'JM-TRIP-'.Str::ulid(), 'created_at' => now(), 'updated_at' => now()]);
        });
        app(AccessPolicy::class)->audit($r->user(), 'transport.batch_saved', ['batch_id' => $id]);

        return $this->respond($r, ['id' => $id], 'Angkatan perjalanan disimpan.');
    }

    public function issuePayload(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['mobility_leg_id' => 'required|integer|exists:mobility_legs,id'], config('site.validation_messages'));

        return $this->issue($request, $data['mobility_leg_id']);
    }

    public function handoverPayload(Request $request, ?int $item = null): JsonResponse|RedirectResponse
    {
        if ($item) {
            $row = DB::table('mobility_handover_items')->find($item) ?? abort(404);
            $request->merge(['mobility_case_id' => $row->mobility_case_id, 'custody_id' => $row->custody_id]);
        }
        $data = $request->validate(['mobility_case_id' => 'required|integer|exists:mobility_cases,id'], config('site.validation_messages'));

        return $this->handover($request, MobilityCase::findOrFail($data['mobility_case_id']));
    }

    public function issue(Request $r, int $leg): JsonResponse|RedirectResponse
    {
        $data = $r->validate(['expires_at' => 'required|date'], config('site.validation_messages'));
        $row = DB::table('mobility_legs')->find($leg) ?? abort(404);
        $case = MobilityCase::findOrFail($row->mobility_case_id);
        $this->application($r, $case->application_id, 'qrpass.issue');
        if ($case->leave_request_id) {
            abort_unless(DB::table('training_leave_requests')->where('id', $case->leave_request_id)->where('approval_status', 'approved')->exists(), 422, 'Izin pulang belum disetujui.');
        }
        $expires = $this->stamp($data['expires_at']);
        abort_unless($expires->isFuture(), 422, 'Batas QR sudah terlewati.');
        $pass = $this->mobility->issue($leg, $expires, $r->user()->id);
        app(AccessPolicy::class)->audit($r->user(), 'qr.issued', ['pass_id' => $pass->id]);

        return $this->respond($r, ['reference' => $pass->reference], 'QR diterbitkan. QR lama pada perjalanan ini dicabut.');
    }

    public function revoke(Request $r, int $pass): JsonResponse|RedirectResponse
    {
        $qr = DB::table('qr_passes')->find($pass) ?? abort(404);
        $leg = DB::table('mobility_legs')->find($qr->mobility_leg_id);
        $case = MobilityCase::findOrFail($leg->mobility_case_id);
        $this->application($r, $case->application_id, 'qrpass.issue');
        DB::table('qr_passes')->where('id', $pass)->update(['revoked_at' => now(), 'updated_at' => now()]);
        app(AccessPolicy::class)->audit($r->user(), 'qr.revoked', ['pass_id' => $pass]);

        return $this->respond($r, ['ok' => true], 'QR dicabut.');
    }

    public function qr(Request $r, string $token): Response|JsonResponse
    {
        $pass = DB::table('qr_passes')->where('token_hash', hash('sha256', $token))->first() ?? abort(404);
        $status = $this->mobility->qrStatus($pass);
        $leg = DB::table('mobility_legs')->find($pass->mobility_leg_id);
        $case = MobilityCase::findOrFail($leg->mobility_case_id);
        $app = Application::withoutGlobalScope('access')->with(['candidate' => fn ($q) => $q->withoutGlobalScope('access')->with(['person', 'contacts' => fn ($q) => $q->whereRaw('1=0')]), 'vacancy.jobOrder.country', 'vacancy.jobOrder.company'])->findOrFail($case->application_id);
        $safe = ['status' => $status, 'reference' => $pass->reference, 'recruitment' => 'Juragan Migran'];
        if ($status === 'VALID') {
            $safe += ['name' => $app->candidate?->person?->full_name, 'candidate_code' => $app->candidate?->candidate_code, 'vacancy' => $app->vacancy?->title, 'country' => $app->vacancy?->jobOrder?->country?->name, 'destination' => $leg->destination, 'appointment_at' => $case->appointment_at->toISOString(), 'purpose' => config('operations.purposes.'.$case->purpose)];
        }
        DB::table('qr_scan_events')->insert(['qr_pass_id' => $pass->id, 'scanner_id' => null, 'action' => 'VERIFY', 'result' => $status, 'created_at' => now()]);

        return ($r->expectsJson() || $r->is('api/*')) ? response()->json($safe)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer') : response()->view('operations.qr', ['safe' => $safe, 'token' => $token])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function qrImage(Request $r, string $token): Response
    {
        $pass = DB::table('qr_passes')->where('token_hash', hash('sha256', $token))->first() ?? abort(404);
        abort_unless($this->mobility->qrStatus($pass) === 'VALID', 404);

        return response(app(QrCode::class)->svg(route('qr.public', $token)), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function checkIn(Request $r, string $token): JsonResponse|RedirectResponse
    {
        $pass = DB::table('qr_passes')->where('token_hash', hash('sha256', $token))->first() ?? abort(404);
        $leg = DB::table('mobility_legs')->find($pass->mobility_leg_id);
        $case = MobilityCase::findOrFail($leg->mobility_case_id);
        $this->application($r, $case->application_id, 'qrpass.scan');
        $result = $this->mobility->checkIn($token, $r->user()->id);

        return ($r->expectsJson() || $r->is('api/*')) ? response()->json((array) $result, $result->ok ? 200 : 409) : back()->with($result->ok ? 'success' : 'error', $result->ok ? 'Check-in berhasil dicatat.' : 'QR tidak dapat digunakan: '.$result->status);
    }

    public function handover(Request $r, MobilityCase $mobility): JsonResponse|RedirectResponse
    {
        $app = $this->application($r, $mobility->application_id, 'handover.manage');
        abort_unless($r->user()->hasPermission('document.custody.update'), 403);
        $data = $r->validate(['custody_id' => 'required|integer|exists:original_document_custodies,id', 'to_party' => 'required|string|max:255', 'status' => 'required|in:EXPECTED,HANDED_OVER,RECEIVED,REJECTED,MISSING,RETURNED', 'notes' => 'nullable|string|max:3000', 'evidence' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120'], config('site.validation_messages'));
        $path = $r->file('evidence')?->store('handover', 'documents');
        try {
            DB::transaction(function () use ($r, $app, $mobility, $data, $path): void {
                $custody = DB::table('original_document_custodies')->where('id', $data['custody_id'])->lockForUpdate()->first() ?? abort(404);
                $document = DB::table('candidate_documents')->find($custody->candidate_document_id);
                abort_unless((int) $document->candidate_id === $app->candidate_id && (! $custody->application_id || (int) $custody->application_id === $app->id), 404);
                $prior = DB::table('mobility_handover_items')->where('mobility_case_id', $mobility->id)->where('custody_id', $custody->id)->first();
                $allowed = ['EXPECTED' => ['HANDED_OVER', 'MISSING', 'REJECTED'], 'HANDED_OVER' => ['RECEIVED', 'MISSING', 'REJECTED'], 'RECEIVED' => ['RETURNED'], 'MISSING' => ['HANDED_OVER'], 'REJECTED' => ['HANDED_OVER'], 'RETURNED' => []];
                if ($prior && ! in_array($data['status'], $allowed[$prior->status])) {
                    throw ValidationException::withMessages(['status' => 'Urutan serah-terima tidak valid.']);
                }
                if (! $prior && $data['status'] !== 'EXPECTED') {
                    throw ValidationException::withMessages(['status' => 'Catat item yang diharapkan terlebih dahulu.']);
                }
                DB::table('mobility_handover_items')->updateOrInsert(['mobility_case_id' => $mobility->id, 'custody_id' => $custody->id], ['from_party' => $prior?->from_party ?? $custody->current_holder, 'to_party' => $data['to_party'], 'status' => $data['status'], 'handed_at' => in_array($data['status'], ['HANDED_OVER', 'RECEIVED', 'RETURNED']) ? ($prior?->handed_at ?? now()) : null, 'received_at' => $data['status'] === 'RECEIVED' ? now() : ($prior?->received_at), 'received_by' => $data['status'] === 'RECEIVED' ? $r->user()->id : ($prior?->received_by), 'notes' => $data['notes'] ?? null, 'evidence_path' => $path ?? $prior?->evidence_path, 'created_at' => $prior?->created_at ?? now(), 'updated_at' => now()]);
                if (in_array($data['status'], ['RECEIVED', 'RETURNED'])) {
                    abort_if($custody->status === 'returned', 422, 'Dokumen sudah dikembalikan.');
                    DB::table('original_document_custodies')->where('id', $custody->id)->update(['current_holder' => $data['to_party'], 'current_location' => $mobility->destination, 'status' => $data['status'] === 'RETURNED' ? 'returned' : 'transferred', 'updated_at' => now()]);
                    DB::table('document_custody_histories')->insert(['custody_id' => $custody->id, 'action' => $data['status'] === 'RETURNED' ? 'return' : 'transfer', 'from_holder' => $custody->current_holder, 'to_holder' => $data['to_party'], 'location' => $mobility->destination, 'evidence_file' => $path, 'performed_by' => $r->user()->id, 'performed_at' => now()]);
                }
                app(AccessPolicy::class)->audit($r->user(), 'mobility.handover_updated', ['case_id' => $mobility->id, 'custody_id' => $custody->id, 'status' => $data['status']]);
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('documents')->delete($path);
            }
            throw $e;
        }

        return $this->respond($r, ['ok' => true], 'Serah-terima dicatat pada riwayat dokumen asli.');
    }

    public function portal(Request $r): View|JsonResponse
    {
        $apps = Application::withoutGlobalScope('access')->whereIn('id', $this->ids($r, 'holiday.read'))->with('candidate.person')->get();
        $holidays = HolidayEvent::where('status', 'ACTIVE')->get();
        $rows = [];
        foreach ($apps as $app) {
            foreach ($holidays as $holiday) {
                $rows[] = ['application' => $app, 'holiday' => $holiday, 'eligibility' => $this->eligibility->evaluate($app, $holiday)];
            }
        }
        $cases = MobilityCase::whereIn('application_id', $apps->pluck('id'))->latest('id')->paginate(10);
        $leaves = DB::table('training_leave_requests')->whereIn('training_enrollment_id', DB::table('training_enrollments')->whereIn('application_id', $apps->pluck('id'))->select('id'))->whereNotNull('holiday_event_id')->latest('id')->get(['id', 'holiday_event_id', 'journey_status', 'approval_status', 'mandatory_return_at']);
        $data = ['rows' => $rows, 'cases' => $cases, 'leaves' => $leaves, 'data' => ['name' => $apps->first()?->candidate?->person?->full_name]];

        return ($r->expectsJson() || $r->is('api/*')) ? response()->json(['holidays' => collect($rows)->map(fn ($row) => ['holiday' => $row['holiday']->only(['name', 'start_date', 'end_date', 'return_deadline']), 'eligibility' => $row['eligibility']]), 'leaves' => $leaves, 'travel' => $cases]) : view('operations.portal', $data);
    }

    public function bulk(Request $r): JsonResponse|RedirectResponse
    {
        $data = $r->validate(['action' => 'required|in:approve_allowed,generate_qr,assign_pickup,return_reminder', 'ids' => 'required|array|min:1|max:50', 'ids.*' => 'integer|distinct', 'confirm' => 'accepted', 'expires_at' => 'nullable|date', 'pickup_point_id' => ['nullable', 'integer', Rule::exists('pickup_points', 'id')->where('active', true)], 'batch_id' => 'nullable|integer|exists:transport_batches,id'], config('site.validation_messages'));
        $permission = match ($data['action']) {
            'approve_allowed' => 'leave.review','generate_qr' => 'qrpass.issue','assign_pickup' => 'mobility.manage','return_reminder' => 'leave.review'
        };
        abort_unless($r->user()->hasPermission($permission), 403);
        DB::transaction(function () use ($r, $data, $permission): void {
            foreach ($data['ids'] as $id) {
                if (in_array($data['action'], ['approve_allowed', 'return_reminder'])) {
                    $leave = DB::table('training_leave_requests')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
                    $enrollment = DB::table('training_enrollments')->find($leave->training_enrollment_id);
                    $app = $this->application($r, $enrollment->application_id, $permission);
                    if ($data['action'] === 'approve_allowed') {
                        $holiday = HolidayEvent::findOrFail($leave->holiday_event_id);
                        $result = $this->eligibility->evaluate($app, $holiday, Carbon::parse($leave->mandatory_return_at));
                        if ($result['result'] !== 'ALLOWED') {
                            throw ValidationException::withMessages(['ids' => 'Persetujuan massal hanya untuk hasil Boleh Pulang. Tinjau pengecualian secara individual.']);
                        }
                        $r->merge(['action' => 'approve', 'override_reason' => null, 'revised_return_at' => null]);
                        $this->review($r, $id);
                    } else {
                        $key = 'manual-return-'.$id.'-'.now()->toDateString();
                        $insert = DB::table('operations_alerts')->insertOrIgnore(['dedupe_key' => $key, 'application_id' => $app->id, 'category' => 'RETURN_REMINDER', 'message' => 'Pengingat kembali LPK dikirim melalui portal sesuai preferensi CPMI.', 'created_at' => now(), 'updated_at' => now()]);
                        if ($insert && DB::table('portal_accounts')->where('candidate_id', $app->candidate_id)->where('status', 'active')->where('in_app_notifications', true)->exists()) {
                            DB::table('portal_notifications')->insert(['candidate_id' => $app->candidate_id, 'category' => 'TRAVEL_UPDATE', 'title' => 'Pastikan jadwal kembali LPK', 'body_safe' => 'Periksa batas kembali dan jadwal perjalanan Anda. Hubungi CS jika perlu bantuan.', 'deep_link' => '/portal/libur', 'created_at' => now(), 'updated_at' => now()]);
                        }
                    }
                } else {
                    $case = MobilityCase::findOrFail($id);
                    $this->application($r, $case->application_id, $permission);
                    foreach (DB::table('mobility_legs')->where('mobility_case_id', $case->id)->whereNotIn('status', ['CHECKED_IN', 'CANCELLED'])->get() as $leg) {
                        if ($data['action'] === 'generate_qr') {
                            abort_unless(! empty($data['expires_at']), 422, 'Isi masa berlaku QR.');
                            $this->issue($r, $leg->id);
                        } else {
                            $r->merge(['status' => $leg->status, 'scheduled_at' => Carbon::parse($leg->scheduled_at)->timezone('Asia/Jakarta')->format('Y-m-d\TH:i'), 'batch_id' => $data['batch_id'] ?? null, 'pickup_point_id' => $data['pickup_point_id'] ?? null]);
                            $this->leg($r, $leg->id);
                        }
                    }
                }
            }
            app(AccessPolicy::class)->audit($r->user(), 'operations.bulk', ['action' => $data['action'], 'ids' => $data['ids']]);
        });

        return $this->respond($r, ['ok' => true, 'count' => count($data['ids'])], 'Tindakan massal selesai sesuai akses dan kebijakan.');
    }

    public function manifest(Request $r, int $batch): Response
    {
        $ids = $this->ids($r, 'transport.batch.manage');
        $rows = DB::table('mobility_legs as l')->join('mobility_cases as m', 'm.id', '=', 'l.mobility_case_id')->join('applications as a', 'a.id', '=', 'm.application_id')->join('candidates as c', 'c.id', '=', 'a.candidate_id')->join('persons as p', 'p.id', '=', 'c.person_id')->where('l.batch_id', $batch)->whereIn('a.id', $ids)->orderBy('l.pickup_sequence')->get(['m.code', 'p.full_name', 'c.candidate_code', 'l.pickup_sequence', 'l.destination', 'l.status']);
        app(AccessPolicy::class)->audit($r->user(), 'transport.manifest_exported', ['batch_id' => $batch, 'rows' => $rows->count()]);
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Referensi', 'Nama', 'Kode CPMI', 'Urutan Jemput', 'Tujuan', 'Status']);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($value) => preg_match('/^[=+@-]/', (string) $value) ? "'".$value : $value, (array) $row));
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="manifest.csv"', 'Cache-Control' => 'no-store']);
    }
}
