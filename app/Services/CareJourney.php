<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CareJourney
{
    public function allowed(User $user, Candidate $candidate, string $permission): bool
    {
        return app(AccessPolicy::class)->canCandidate($user, $candidate->id, $permission);
    }

    public function authorize(User $user, Candidate $candidate, string $permission): void
    {
        abort_unless(config('care.enabled') && $this->allowed($user, $candidate, $permission), 403);
    }

    public function event(Candidate $candidate, string $key, string $type, array $payload, string $permission = 'candidate.read.timeline', ?User $actor = null, ?int $application = null, bool $automatic = false, ?string $stage = null, ?string $occurred = null): void
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        DB::table('care_events')->insertOrIgnore(['id' => (string) Str::uuid(), 'source_key' => $key, 'candidate_id' => $candidate->id, 'application_id' => $application, 'actor_id' => $actor?->id, 'event_type' => $type, 'stage' => $stage, 'visibility' => $permission, 'auto_generated' => $automatic, 'payload' => Crypt::encryptString($body), 'signature' => hash_hmac('sha256', $candidate->id.'|'.$key.'|'.$type.'|'.$body, config('app.key')), 'occurred_at' => $occurred ?? now(), 'created_at' => now()]);
    }

    public function decode(string $payload): array
    {
        return json_decode(Crypt::decryptString($payload), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Normalize existing domain facts without copying medical notes, chat bodies, or binary files. */
    public function sync(Candidate $candidate): void
    {
        $this->event($candidate, 'candidate:'.$candidate->id, 'candidate_registered', ['title' => 'CPMI terdaftar'], automatic: true, stage: 'trust', occurred: $candidate->registered_at?->toDateTimeString());
        $apps = DB::table('applications')->where('candidate_id', $candidate->id)->whereNull('deleted_at')->get();
        foreach ($apps as $application) {
            $this->event($candidate, 'application:'.$application->id.':'.$application->status, 'application_status', ['title' => 'Status lamaran: '.$application->status], application: $application->id, automatic: true, stage: 'trust', occurred: $application->updated_at);
            foreach (DB::table('medical_events')->where('application_id', $application->id)->get() as $row) {
                $this->event($candidate, 'medical:'.$row->id.':'.hash('sha256', $row->status.$row->clearance_status.$row->expires_at), 'medical_updated', ['title' => 'Proses MCU diperbarui', 'status' => $row->status, 'clearance' => $row->clearance_status], 'health.mcu.summary.read', application: $application->id, automatic: true, stage: 'mcu', occurred: $row->updated_at);
            }
            foreach (DB::table('interviews')->where('application_id', $application->id)->get() as $row) {
                $this->event($candidate, 'interview:'.$row->id.':'.$row->status.':'.$row->outcome, 'interview_updated', ['title' => 'Wawancara: '.$row->status], 'schedule.read', application: $application->id, automatic: true, stage: 'screening', occurred: $row->updated_at);
            }
            foreach (DB::table('health_interviews')->where('application_id', $application->id)->get() as $row) {
                $this->event($candidate, 'screening:'.$row->id.':'.$row->status, 'screening_updated', ['title' => 'Screening awal: '.$row->status], 'health.prescreen.read', application: $application->id, automatic: true, stage: 'screening', occurred: $row->updated_at);
            }
            foreach (DB::table('flight_details')->where('application_id', $application->id)->get() as $row) {
                $this->event($candidate, 'flight:'.$row->id.':'.hash('sha256', $row->departure_at.$row->arrival_at), 'flight_updated', ['title' => 'Jadwal penerbangan diperbarui'], 'schedule.read', application: $application->id, automatic: true, stage: 'flight', occurred: $row->updated_at);
            }
            foreach (DB::table('training_enrollments')->where('application_id', $application->id)->get() as $row) {
                $this->event($candidate, 'training:'.$row->id.':'.$row->status, 'training_updated', ['title' => 'Pelatihan: '.$row->status], 'process.read', application: $application->id, automatic: true, stage: 'lpk', occurred: $row->updated_at);
                if ($row->joined_at && in_array($row->status, ['ongoing', 'on_hold'], true)) {
                    $days = max(0, (int) Carbon::parse($row->joined_at)->diffInDays(now(), false));
                    $checkDays = [1, 3, 7];
                    for ($checkDay = 14; $checkDay <= min(364, $days); $checkDay += 7) {
                        $checkDays[] = $checkDay;
                    }
                    foreach ($checkDays as $day) {
                        if ($day <= $days) {
                            $this->event($candidate, 'welfare-due:'.$row->id.':'.$day, 'welfare_due', ['title' => 'Welfare check LPK hari '.$day.' diperlukan', 'day' => $day], 'incident.read', application: $application->id, automatic: true, stage: 'lpk', occurred: Carbon::parse($row->joined_at)->addDays($day)->toDateTimeString());
                        }
                    }
                }
            }
            foreach (DB::table('application_stage_histories as h')->leftJoin('pipeline_stages as s', 's.id', '=', 'h.to_stage_id')->where('h.application_id', $application->id)->select('h.id', 'h.entered_at', 's.name')->get() as $row) {
                $this->event($candidate, 'stage:'.$row->id, 'stage_changed', ['title' => 'Tahap: '.($row->name ?: 'Diperbarui')], application: $application->id, automatic: true, occurred: $row->entered_at);
            }
        }
        foreach (DB::table('candidate_documents')->where('candidate_id', $candidate->id)->whereNull('deleted_at')->get() as $row) {
            $this->event($candidate, 'document:'.$row->id.':'.hash('sha256', $row->status.$row->updated_at), 'document_updated', ['title' => 'Dokumen: '.(DocumentRequirementEngine::LABELS[$row->status] ?? $row->status)], 'document.read.metadata', application: $row->application_id, automatic: true, stage: 'family', occurred: $row->updated_at);
        }
        $leadIds = DB::table('acquisition_leads')->where('candidate_id', $candidate->id)->pluck('id');
        foreach (DB::table('crm_activities')->whereIn('lead_id', $leadIds)->orderByDesc('id')->limit(200)->get() as $row) {
            $this->event($candidate, 'crm:'.$row->id, $row->event_type, ['title' => 'CRM · '.str_replace('_', ' ', $row->event_type)], automatic: true, occurred: $row->created_at);
        }
    }

    public function template(?Application $application): object
    {
        $code = strtoupper($application?->vacancy?->jobOrder?->country?->code ?? 'DEFAULT');

        $template = DB::table('care_journey_templates')->where('country_code', $code)->first() ?? DB::table('care_journey_templates')->where('country_code', 'DEFAULT')->firstOrFail();
        $stages = json_decode($template->stages, true);
        $policy = json_decode($template->policy ?? '{}', true);
        $order = $policy['stage_order'] ?? ['trust', 'screening', 'family', 'mcu', 'lpk', 'teto', 'opp', 'flight'];
        $ordered = [];
        foreach (array_unique([...$order, ...array_keys($stages)]) as $key) {
            if (isset($stages[$key])) {
                $ordered[$key] = $stages[$key];
            }
        }
        $template->stages = json_encode($ordered);

        return $template;
    }

    public function ledger(User $user, Candidate $candidate): Collection
    {
        $rows = DB::table('care_events')->where('candidate_id', $candidate->id)->orderByDesc('occurred_at')->limit(200)->get();
        $policy = app(AccessPolicy::class);
        $cache = [];

        return $rows->filter(function (object $row) use ($user, $candidate, $policy, &$cache): bool {
            $key = $row->visibility.':'.$row->application_id;
            if (! array_key_exists($key, $cache)) {
                $cache[$key] = $this->allowed($user, $candidate, $row->visibility) && (! $row->application_id || $policy->applicationIds($user, $row->visibility)->where('applications.id', $row->application_id)->exists());
            }

            return $cache[$key];
        })->map(function (object $row) use ($user, $candidate): object {
            $row->data = $this->decode($row->payload);
            $expected = hash_hmac('sha256', $row->candidate_id.'|'.$row->source_key.'|'.$row->event_type.'|'.json_encode($row->data, JSON_THROW_ON_ERROR), config('app.key'));
            abort_unless(hash_equals($expected, $row->signature), 409, 'Integritas event perlu ditinjau administrator.');
            if ((in_array($row->event_type, ['welfare_check', 'issue_report'], true) || ($row->data['outcome'] ?? null) === 'WELFARE') && ! $this->allowed($user, $candidate, 'health.prescreen.read') && ! $this->allowed($user, $candidate, 'health.mcu.summary.read')) {
                $row->data = ['title' => 'Welfare check tercatat · rincian kesehatan terbatas'];
            }

            return $row;
        })->values();
    }

    public function touchpoint(User $user, Candidate $candidate, array $data): void
    {
        $this->authorize($user, $candidate, 'candidate.read.timeline');
        $this->authorize($user, $candidate, 'process.update');
        DB::transaction(function () use ($user, $candidate, $data): void {
            Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $key = 'touch:'.$data['request_key'];
            if (DB::table('care_events')->where('source_key', $key)->exists()) {
                abort_unless(DB::table('care_events')->where('source_key', $key)->where('candidate_id', $candidate->id)->where('actor_id', $user->id)->exists(), 409);

                return;
            }
            $sensitive = in_array($data['outcome'], ['WELFARE', 'PARTNER', 'PEER'], true);
            if ($sensitive) {
                $this->authorize($user, $candidate, 'incident.create');
            }
            $payload = ['title' => config('care.outcomes.'.$data['outcome']), 'outcome' => $data['outcome'], 'note' => $data['note'] ?? '', 'channel' => $data['channel'], 'stage' => $data['stage']];
            $this->event($candidate, $key, 'touchpoint', $payload, $sensitive ? 'incident.read' : 'candidate.read.timeline', $user, stage: $data['stage']);
            if ($data['outcome'] === 'WELFARE') {
                $this->openIssue($user, $candidate, ['request_key' => $data['request_key'], 'category' => 'HEALTH', 'severity' => 'S2', 'description' => 'Welfare check: CPMI memerlukan bantuan. '.($data['note'] ?? '')]);
            }
            app(AccessPolicy::class)->audit($user, 'care.touchpoint', ['candidate_id' => $candidate->id, 'request_key' => $data['request_key']]);
        });
    }

    public function openIssue(User $user, Candidate $candidate, array $data): int
    {
        $this->authorize($user, $candidate, 'incident.create');

        return $this->createIssue($user, $candidate, $data);
    }

    private function createIssue(User $user, Candidate $candidate, array $data): int
    {
        return DB::transaction(function () use ($user, $candidate, $data): int {
            Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('care_issues')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->candidate_id === $candidate->id && $existing->opened_by === $user->id, 409);

                return $existing->id;
            }
            $application = Application::where('candidate_id', $candidate->id)->latest('id')->first();
            $partnerId = $data['partner_id'] ?? null;
            if ($partnerId) {
                $related = DB::table('training_enrollments as e')->join('training_batches as b', 'b.id', '=', 'e.batch_id')->join('applications as a', 'a.id', '=', 'e.application_id')->where('a.candidate_id', $candidate->id)->where('b.company_id', $partnerId)->exists()
                    || DB::table('applications as a')->join('vacancies as v', 'v.id', '=', 'a.vacancy_id')->join('job_orders as j', 'j.id', '=', 'v.job_order_id')->where('a.candidate_id', $candidate->id)->where('j.company_id', $partnerId)->exists();
                abort_unless($related, 422, 'Partner harus terkait dengan perjalanan CPMI.');
            }
            [$ack, $action] = config('care.sla_minutes.'.$data['severity']);
            $activeHealth = $data['category'] === 'HEALTH' ? DB::table('care_issues')->where('candidate_id', $candidate->id)->where('category', 'HEALTH')->whereNotIn('status', ['RESOLVED', 'CLOSED'])->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId))->latest('id')->lockForUpdate()->first() : null;
            if ($activeHealth) {
                if ((int) substr($data['severity'], 1) > (int) substr($activeHealth->severity, 1)) {
                    DB::table('care_issues')->where('id', $activeHealth->id)->update(['severity' => $data['severity'], 'ack_due_at' => min(Carbon::parse($activeHealth->ack_due_at), now()->addMinutes($ack)), 'action_due_at' => min(Carbon::parse($activeHealth->action_due_at), now()->addMinutes($action)), 'acknowledged_at' => null, 'action_at' => null, 'version' => $activeHealth->version + 1, 'updated_at' => now()]);
                }
                $this->event($candidate, 'issue-report:'.$data['request_key'], 'issue_report', ['title' => 'Laporan welfare ditambahkan ke kasus aktif', 'issue_id' => $activeHealth->id, 'severity' => $data['severity'], 'note' => $data['description']], 'incident.read', $user, $activeHealth->application_id);
                $this->monitorIssue($activeHealth->id);

                return $activeHealth->id;
            }
            $id = DB::table('care_issues')->insertGetId(['request_key' => $data['request_key'], 'candidate_id' => $candidate->id, 'application_id' => $application?->id, 'partner_id' => $partnerId, 'opened_by' => $user->id, 'owner_id' => $application?->assigned_cs_id, 'category' => $data['category'], 'severity' => $data['severity'], 'status' => 'OPEN', 'verification' => 'REPORTED', 'details' => Crypt::encryptString(json_encode(['description' => $data['description']])), 'version' => 1, 'ack_due_at' => now()->addMinutes($ack), 'action_due_at' => now()->addMinutes($action), 'created_at' => now(), 'updated_at' => now()]);
            $this->event($candidate, 'issue:'.$id.':open', 'issue_opened', ['title' => 'Insiden dibuka', 'issue_id' => $id, 'severity' => $data['severity'], 'category' => $data['category']], 'incident.read', $user, $application?->id);
            $this->monitorIssue($id);

            return $id;
        });
    }

    public function portalCheck(User $user, Candidate $candidate, array $data): void
    {
        abort_unless(config('care.enabled') && DB::table('portal_accounts')->where('user_id', $user->id)->where('candidate_id', $candidate->id)->where('status', 'active')->exists(), 403);
        DB::transaction(function () use ($user, $candidate, $data): void {
            Candidate::withoutGlobalScopes()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $key = 'welfare:'.$data['request_key'];
            if (DB::table('care_events')->where('source_key', $key)->exists()) {
                abort_unless(DB::table('care_events')->where('source_key', $key)->where('candidate_id', $candidate->id)->where('actor_id', $user->id)->exists(), 409);

                return;
            }
            $this->event($candidate, $key, 'welfare_check', ['title' => 'Welfare check CPMI: '.config('care.welfare_outcomes.'.$data['status']), 'status' => $data['status'], 'note' => $data['note'] ?? ''], 'incident.read', $user, stage: 'lpk');
            if (in_array($data['status'], ['ILL', 'SAFETY', 'EMERGENCY'], true)) {
                $this->createIssue($user, $candidate, ['request_key' => $data['request_key'], 'category' => $data['status'] === 'SAFETY' ? 'PEER' : 'HEALTH', 'severity' => ['ILL' => 'S2', 'SAFETY' => 'S3', 'EMERGENCY' => 'S4'][$data['status']], 'description' => 'CPMI melaporkan: '.config('care.welfare_outcomes.'.$data['status']).'. '.($data['note'] ?? '')]);
            }
            app(AccessPolicy::class)->audit($user, 'care.portal_welfare_check', ['candidate_id' => $candidate->id]);
        });
    }

    public function transitionIssue(User $user, Candidate $candidate, int $id, array $data): void
    {
        $closing = in_array($data['status'], ['RESOLVED', 'CLOSED'], true);
        $this->authorize($user, $candidate, $closing ? 'incident.close' : 'incident.update');
        DB::transaction(function () use ($user, $candidate, $id, $data, $closing): void {
            $row = DB::table('care_issues')->where('candidate_id', $candidate->id)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $this->authorizeApplication($user, $row->application_id, $closing ? 'incident.close' : 'incident.update');
            $this->version($row, $data['version']);
            $transitions = ['OPEN' => ['ACKNOWLEDGED'], 'ACKNOWLEDGED' => ['IN_REVIEW', 'IN_ACTION'], 'IN_REVIEW' => ['IN_ACTION'], 'IN_ACTION' => ['MONITORING'], 'MONITORING' => ['IN_ACTION', 'RESOLVED'], 'RESOLVED' => ['CLOSED'], 'CLOSED' => []];
            if (! in_array($data['status'], $transitions[$row->status], true)) {
                throw ValidationException::withMessages(['status' => 'Perubahan status insiden tidak diizinkan.']);
            }
            $closure = null;
            if ($closing) {
                $fields = in_array($row->severity, ['S3', 'S4'], true) ? ['medical_action', 'outcome', 'followup', 'candidate_acknowledgement'] : ['outcome'];
                foreach ($fields as $field) {
                    if (! filled($data[$field] ?? null)) {
                        throw ValidationException::withMessages([$field => 'Data penutupan insiden wajib dilengkapi.']);
                    }
                }
                $closure = Crypt::encryptString(json_encode(array_intersect_key($data, array_flip($fields))));
            }
            $updates = ['status' => $data['status'], 'version' => $row->version + 1, 'updated_at' => now()];
            if ($data['status'] === 'ACKNOWLEDGED') {
                $updates['acknowledged_at'] = now();
            }
            if ($data['status'] === 'IN_ACTION' && ! $row->action_at) {
                $updates['action_at'] = now();
            }
            if ($closing) {
                $updates['closure'] = $closure;
                $updates['resolved_at'] = $row->resolved_at ?? now();
            }
            DB::table('care_issues')->where('id', $id)->update($updates);
            $this->event($candidate, 'issue:'.$id.':version:'.($row->version + 1), 'issue_status', ['title' => 'Insiden: '.$data['status'], 'issue_id' => $id, 'status' => $data['status']], 'incident.read', $user, $row->application_id);
        });
    }

    public function verifyIssue(User $user, Candidate $candidate, int $id, array $data): void
    {
        $this->authorize($user, $candidate, 'incident.escalate');
        DB::transaction(function () use ($user, $candidate, $id, $data): void {
            $row = DB::table('care_issues')->where('candidate_id', $candidate->id)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $this->authorizeApplication($user, $row->application_id, 'incident.escalate');
            $this->version($row, $data['version']);
            $states = ['REPORTED' => ['UNDER_REVIEW'], 'UNDER_REVIEW' => ['SUBSTANTIATED', 'INCONCLUSIVE'], 'SUBSTANTIATED' => ['RESOLVED'], 'INCONCLUSIVE' => ['RESOLVED'], 'RESOLVED' => []];
            if (! in_array($data['verification'], $states[$row->verification], true)) {
                throw ValidationException::withMessages(['verification' => 'Laporan harus melalui verifikasi bertahap.']);
            }
            DB::table('care_issues')->where('id', $id)->update(['verification' => $data['verification'], 'substantiated_at' => $data['verification'] === 'SUBSTANTIATED' ? now() : $row->substantiated_at, 'version' => $row->version + 1, 'updated_at' => now()]);
            $this->event($candidate, 'issue:'.$id.':version:'.($row->version + 1), 'issue_verification', ['title' => 'Verifikasi laporan: '.$data['verification'], 'issue_id' => $id, 'reason' => $data['reason'], 'verification' => $data['verification']], 'incident.read', $user, $row->application_id);
        });
    }

    public function monitorIssue(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $row = DB::table('care_issues')->where('id', $id)->lockForUpdate()->first();
            if (! $row || in_array($row->status, ['RESOLVED', 'CLOSED'], true)) {
                return;
            }
            $levels = [];
            if (! $row->acknowledged_at && Carbon::parse($row->ack_due_at)->lte(now())) {
                $levels[1] = 'Belum ada acknowledgement dalam SLA';
            }
            if ((! $row->action_at && Carbon::parse($row->action_due_at)->lte(now())) || $row->severity === 'S4') {
                $levels[2] = 'Perlu tindakan segera; SLA tindakan atau kondisi emergency';
            }
            foreach ($levels as $level => $reason) {
                $inserted = DB::table('care_escalations')->insertOrIgnore(['issue_id' => $row->id, 'level' => $level, 'recipient_roles' => json_encode(config('care.escalation_roles.'.$level)), 'reason' => $reason, 'created_at' => now()]);
                if ($inserted) {
                    $candidate = Candidate::withoutGlobalScopes()->find($row->candidate_id);
                    if ($candidate) {
                        $this->event($candidate, 'escalation:'.$row->id.':'.$level, 'issue_escalated', ['title' => 'Insiden dieskalasi · level '.$level, 'issue_id' => $row->id, 'reason' => $reason], 'incident.read', application: $row->application_id, automatic: true);
                    }
                }
            }
        });
    }

    private function authorizeApplication(User $user, ?int $applicationId, string $permission): void
    {
        if ($applicationId) {
            abort_unless(app(AccessPolicy::class)->applicationIds($user, $permission)->where('applications.id', $applicationId)->exists(), 404);
        }
    }

    private function version(object $row, int $version): void
    {
        if ($row->version !== $version) {
            throw ValidationException::withMessages(['version' => 'Data telah berubah. Muat ulang sebelum menyimpan.']);
        }
    }

    public function requestSupport(User $user, Candidate $candidate, array $data): int
    {
        $this->authorize($user, $candidate, 'process.update');
        if (! config('care.support_types.'.$data['type'].'.financial') && ($data['amount_minor'] ?? 0) > 0) {
            throw ValidationException::withMessages(['amount_rupiah' => 'Jenis dukungan ini tidak mencakup pencairan dana.']);
        }

        return DB::transaction(function () use ($user, $candidate, $data): int {
            Candidate::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('care_support_requests')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->candidate_id === $candidate->id && $existing->requested_by === $user->id, 409);

                return $existing->id;
            }
            $emergency = $data['type'] === 'emergency_medical';
            if ($emergency) {
                $this->authorize($user, $candidate, 'incident.create');
                abort_unless(DB::table('care_issues')->where('id', $data['issue_id'] ?? 0)->where('candidate_id', $candidate->id)->where('category', 'HEALTH')->whereNotIn('status', ['CLOSED', 'RESOLVED'])->exists(), 422, 'Bantuan darurat harus terkait insiden kesehatan aktif.');
            }
            $id = DB::table('care_support_requests')->insertGetId(['request_key' => $data['request_key'], 'candidate_id' => $candidate->id, 'issue_id' => $emergency ? $data['issue_id'] : null, 'requested_by' => $user->id, 'type' => $data['type'], 'stage' => $data['stage'], 'status' => 'REQUESTED', 'emergency' => $emergency, 'amount_minor' => $data['amount_minor'] ?? 0, 'currency' => 'IDR', 'details' => Crypt::encryptString(json_encode(['reason' => $data['reason'] ?? 'Kebutuhan dukungan teridentifikasi'])), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->event($candidate, 'support:'.$id.':request', 'support_requested', ['title' => 'Dukungan diajukan: '.config('care.support_types.'.$data['type'].'.action'), 'support_id' => $id], $emergency ? 'incident.read' : 'finance.cpmi_benefit.read', $user, stage: $data['stage']);

            return $id;
        });
    }

    public function transitionSupport(User $user, Candidate $candidate, int $id, array $data): void
    {
        DB::transaction(function () use ($user, $candidate, $id, $data): void {
            $row = DB::table('care_support_requests')->where('candidate_id', $candidate->id)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $this->version($row, $data['version']);
            $financial = config('care.support_types.'.$row->type.'.financial');
            $approval = in_array($data['status'], ['APPROVED', 'REJECTED', 'DISBURSED'], true);
            if ($row->emergency && $approval) {
                abort_unless(in_array($user->crmRole()?->code, config('care.emergency_approver_roles'), true), 403);
                $this->authorize($user, $candidate, 'incident.escalate');
            } else {
                $this->authorize($user, $candidate, $approval && $financial ? 'finance.cpmi_benefit.write' : 'process.update');
            }
            $states = ['REQUESTED' => ['APPROVED', 'REJECTED', 'CANCELLED'], 'APPROVED' => [$financial ? 'DISBURSED' : 'SCHEDULED'], 'DISBURSED' => ['CONFIRMED'], 'SCHEDULED' => ['CONFIRMED'], 'CONFIRMED' => ['OUTCOME_REVIEWED'], 'REJECTED' => [], 'CANCELLED' => [], 'OUTCOME_REVIEWED' => []];
            if (! in_array($data['status'], $states[$row->status], true)) {
                throw ValidationException::withMessages(['status' => 'Transisi bantuan tidak diizinkan.']);
            }
            if ($data['status'] === 'CANCELLED') {
                abort_unless(! $financial && $row->requested_by === $user->id, 403);
            }
            if ($approval) {
                abort_if($row->requested_by === $user->id, 403, 'Pemohon tidak boleh menyetujui atau mencairkan bantuannya sendiri.');
            }
            if ($data['status'] === 'DISBURSED') {
                abort_unless($row->amount_minor > 0, 422, 'Nominal bantuan wajib lebih dari nol.');
                abort_unless(filled($data['reference'] ?? null), 422, 'Referensi bukti pencairan wajib diisi.');
                if ($row->emergency) {
                    DB::table('care_journey_templates')->where('country_code', 'DEFAULT')->lockForUpdate()->firstOrFail();
                    $used = DB::table('care_reserve_transactions')->sum('amount_minor');
                    abort_if($used + $row->amount_minor > config('care.reserve_limit_minor'), 422, 'Cadangan darurat belum mencukupi. Insiden tetap aktif dan tereskalasi.');
                    DB::table('care_reserve_transactions')->insert(['support_id' => $id, 'approved_by' => $user->id, 'amount_minor' => $row->amount_minor, 'currency' => 'IDR', 'reference' => $data['reference'], 'created_at' => now()]);
                }
            }
            $details = $this->decode($row->details);
            $details['transitions'][] = ['status' => $data['status'], 'reason' => $data['reason'], 'reference' => $data['reference'] ?? null, 'actor_id' => $user->id, 'at' => now()->toIso8601String()];
            DB::table('care_support_requests')->where('id', $id)->update(['status' => $data['status'], 'approved_by' => $data['status'] === 'APPROVED' ? $user->id : $row->approved_by, 'details' => Crypt::encryptString(json_encode($details)), 'version' => $row->version + 1, 'updated_at' => now()]);
            $this->event($candidate, 'support:'.$id.':version:'.($row->version + 1), 'support_status', ['title' => 'Bantuan: '.$data['status'], 'support_id' => $id, 'reason' => $data['reason']], $row->emergency ? 'incident.read' : 'finance.cpmi_benefit.read', $user, stage: $row->stage);
        });
    }

    public function snapshot(Candidate $candidate, string $type, array $payload, ?int $partner = null): void
    {
        $fingerprint = hash('sha256', json_encode([$candidate->id, $partner, $type, config('care.rule_version'), $payload]));
        DB::table('care_snapshots')->insertOrIgnore(['candidate_id' => $candidate->id, 'partner_id' => $partner, 'type' => $type, 'rule_version' => config('care.rule_version'), 'payload' => Crypt::encryptString(json_encode($payload)), 'fingerprint' => $fingerprint, 'created_at' => now()]);
    }

    public function risk(User $user, Candidate $candidate, Collection $events, Collection $issues, ?Application $application): array
    {
        $signals = [];
        $active = $issues->whereNotIn('status', ['RESOLVED', 'CLOSED']);
        if ($active->contains(fn (object $issue): bool => in_array($issue->severity, ['S3', 'S4'], true))) {
            $signals[] = ['level' => 3, 'reason' => 'Insiden urgent / emergency masih aktif', 'action' => 'Prioritaskan keselamatan; tindak lanjuti jalur layanan medis dan eskalasi'];
        } elseif ($active->isNotEmpty()) {
            $signals[] = ['level' => 2, 'reason' => 'Insiden membutuhkan tindak lanjut', 'action' => 'Konfirmasi kondisi dan tindak lanjut Care PIC'];
        }
        $welfareDue = $events->firstWhere('event_type', 'welfare_due');
        $welfareCheck = $events->firstWhere('event_type', 'welfare_check');
        if ($welfareDue && (! $welfareCheck || Carbon::parse($welfareDue->occurred_at)->gt(Carbon::parse($welfareCheck->occurred_at)))) {
            $signals[] = ['level' => 1, 'reason' => 'Welfare check LPK belum dikonfirmasi', 'action' => 'Konfirmasi kondisi CPMI melalui welfare check'];
        }
        $lastManual = $events->first(fn (object $event): bool => in_array($event->event_type, ['touchpoint', 'PROTOCOL_OUTCOME'], true));
        if ($lastManual && in_array($lastManual->data['outcome'] ?? null, ['UNSURE', 'WITHDRAWAL', 'FAMILY', 'FINANCIAL', 'UNREACHABLE'], true)) {
            $outcome = $lastManual->data['outcome'];
            $signals[] = ['level' => $outcome === 'WITHDRAWAL' ? 2 : 1, 'reason' => config('care.outcomes.'.$outcome), 'action' => $outcome === 'FAMILY' ? 'Jadwalkan Family Assurance Call' : ($outcome === 'WITHDRAWAL' ? 'Dengarkan keputusan CPMI; lakukan exit review tanpa menghalangi hak berhenti' : 'Hubungi CPMI dan identifikasi dukungan yang diperlukan')];
        }
        if ($application && app(AccessPolicy::class)->applicationIds($user, 'health.mcu.summary.read')->where('applications.id', $application->id)->exists()) {
            $mcu = DB::table('medical_events')->where('application_id', $application->id)->where('status', 'completed')->where('clearance_status', 'fit')->orderByDesc('completed_at')->first();
            $policy = json_decode($this->template($application)->policy ?? '{}', true);
            $lpkDays = $policy['post_mcu_lpk_days'] ?? config('care.post_mcu_lpk_days');
            $enrolled = DB::table('training_enrollments')->where('application_id', $application->id)->whereNotNull('joined_at')->whereNotIn('status', ['cancelled', 'withdrawn'])->exists();
            if ($mcu && ! $enrolled && $mcu->completed_at && Carbon::parse($mcu->completed_at)->addDays($lpkDays)->isPast()) {
                $signals[] = ['level' => 2, 'reason' => 'MCU memenuhi syarat; onboarding LPK melewati SLA '.$lpkDays.' hari', 'action' => 'Prioritaskan onboarding LPK dan evaluasi kebutuhan transport'];
            }
        }
        $meaningful = $events->first(fn (object $event): bool => in_array($event->event_type, ['message_received', 'message_sent', 'touchpoint', 'followup_completed', 'PROTOCOL_OUTCOME'], true));
        if ($meaningful && Carbon::parse($meaningful->occurred_at)->addDays(config('care.silent_days'))->isPast()) {
            $signals[] = ['level' => 1, 'reason' => 'Belum ada touchpoint bermakna selama '.config('care.silent_days').' hari', 'action' => 'Hubungi CPMI dan konfirmasi kelanjutan proses'];
        }
        $level = collect($signals)->max('level') ?? 0;
        $result = ['status' => ['Stable', 'Watch', 'Elevated Risk', 'Critical Attention'][$level], 'level' => $level, 'signals' => $signals, 'next_action' => collect($signals)->sortByDesc('level')->first()['action'] ?? 'Lanjutkan proses sesuai tahapan dan persyaratan'];
        $this->snapshot($candidate, 'risk:user:'.$user->id, $result);

        return $result;
    }

    public function partnerQuality(User $user, Candidate $candidate, ?int $partnerId): array
    {
        if (! $partnerId || ! $this->allowed($user, $candidate, 'incident.read')) {
            return ['label' => 'Belum cukup data', 'sample' => 0, 'confidence' => 'Rendah', 'score' => null, 'explanation' => 'Ringkasan hanya memakai outcome yang terverifikasi dan cakupan akses Anda.'];
        }
        $ids = app(AccessPolicy::class)->candidateIds($user, 'incident.read');
        $query = DB::table('care_issues')->where('partner_id', $partnerId)->whereIn('candidate_id', $ids);
        $partnerCandidates = DB::table('applications as a')->join('vacancies as v', 'v.id', '=', 'a.vacancy_id')->join('job_orders as j', 'j.id', '=', 'v.job_order_id')->whereNull('a.deleted_at')->where('j.company_id', $partnerId)->whereIn('a.candidate_id', $ids)->pluck('a.candidate_id');
        $trainingCandidates = DB::table('training_enrollments as e')->join('training_batches as b', 'b.id', '=', 'e.batch_id')->join('applications as a', 'a.id', '=', 'e.application_id')->whereNull('a.deleted_at')->where('b.company_id', $partnerId)->whereIn('a.candidate_id', $ids)->pluck('a.candidate_id');
        $sample = $partnerCandidates->merge($trainingCandidates)->unique()->count();
        $verified = (clone $query)->whereNotNull('substantiated_at')->distinct()->count('candidate_id');
        $lateResponses = (clone $query)->where('ack_due_at', '<', now())->where(fn ($q) => $q->whereNull('acknowledged_at')->orWhereColumn('acknowledged_at', '>', 'ack_due_at'))->distinct()->count('candidate_id');
        $resolutionTimes = (clone $query)->whereNotNull('resolved_at')->get(['created_at', 'resolved_at'])->map(fn (object $row): float => Carbon::parse($row->created_at)->diffInMinutes(Carbon::parse($row->resolved_at)));
        $unverified = (clone $query)->whereIn('verification', ['REPORTED', 'UNDER_REVIEW'])->count();
        $ackTimes = (clone $query)->whereNotNull('acknowledged_at')->get(['created_at', 'acknowledged_at'])->map(fn (object $row): float => Carbon::parse($row->created_at)->diffInMinutes(Carbon::parse($row->acknowledged_at)));
        $enough = $sample >= config('care.minimum_partner_sample');
        $weights = config('care.partner_quality_weights');
        $score = $enough ? max(0, (int) round(100 - ($verified / max(1, $sample) * $weights['substantiated']) - ($lateResponses / max(1, $sample) * $weights['response_sla']))) : null;
        $label = $score === null ? 'Belum cukup data' : ($score >= 85 ? 'Baik' : ($score >= 70 ? 'Monitor' : ($score >= 50 ? 'Perlu Perhatian' : 'Perlu Evaluasi')));
        $result = ['label' => $label, 'sample' => $sample, 'confidence' => $enough ? 'Memadai untuk review' : 'Rendah', 'score' => $score, 'late_responses' => $lateResponses, 'median_resolution_minutes' => $resolutionTimes->median(), 'substantiated' => $verified, 'unverified' => $unverified, 'median_ack_minutes' => $ackTimes->median(), 'explanation' => 'Sampel '.$sample.' CPMI dalam cakupan akses. '.$verified.' laporan didukung verifikasi; '.$unverified.' laporan belum diputuskan. Ranking tidak diubah otomatis dari laporan tunggal.'];
        $this->snapshot($candidate, 'partner:user:'.$user->id, $result, $partnerId);

        return $result;
    }

    public function routes(User $user, Candidate $candidate): Collection
    {
        $canIdentity = $this->allowed($user, $candidate, 'candidate.read.identity');
        $vacancies = Vacancy::whereIn('id', app(AccessPolicy::class)->vacancyIds($user, 'job_order.read'))->whereIn('status', ['active', 'published', 'open'])->with(['jobOrder.country', 'jobOrder.company', 'currentRequirements.definition'])->orderByDesc('id')->limit(20)->get();
        $options = $vacancies->map(function (Vacancy $vacancy) use ($user, $candidate, $canIdentity): array {
            $fields = ['age' => $canIdentity ? $candidate->person?->birth_date?->age : null, 'age_years' => $canIdentity ? $candidate->person?->birth_date?->age : null, 'height_cm' => $canIdentity ? $candidate->person?->height_cm : null, 'weight_kg' => $canIdentity ? $candidate->person?->weight_kg : null, 'education' => $candidate->person?->education_level, 'education_level' => $candidate->person?->education_level, 'gender' => $canIdentity ? $candidate->person?->gender : null, 'languages' => $candidate->person?->languages];
            $checks = [];
            $failed = false;
            $gaps = false;
            foreach ($vacancy->currentRequirements as $requirement) {
                $code = strtolower($requirement->definition?->code ?? '');
                $value = $fields[$code] ?? null;
                $passed = null;
                if ($value !== null && $value !== '') {
                    $expected = $requirement->value;
                    $passed = match ($requirement->operator) {
                        'gte' => is_numeric($value) ? $value >= ($requirement->value_min ?? $expected) : null,
                        'lte' => is_numeric($value) ? $value <= ($requirement->value_max ?? $expected) : null,
                        'gt' => is_numeric($value) ? $value > $expected : null,
                        'lt' => is_numeric($value) ? $value < $expected : null,
                        'between' => is_numeric($value) ? $value >= $requirement->value_min && $value <= $requirement->value_max : null,
                        'eq' => mb_strtolower((string) $value) === mb_strtolower((string) $expected),
                        'neq' => mb_strtolower((string) $value) !== mb_strtolower((string) $expected),
                        'contains' => str_contains(mb_strtolower((string) $value), mb_strtolower((string) $expected)),
                        'in' => in_array(mb_strtolower((string) $value), array_map('trim', explode(',', mb_strtolower((string) $expected))), true),
                        default => null,
                    };
                }
                $checks[] = ['field' => $requirement->definition?->name ?? 'Persyaratan', 'result' => $passed === null ? 'Perlu verifikasi' : ($passed ? 'Sesuai' : 'Belum sesuai')];
                $failed = $failed || ($requirement->is_required && $passed === false);
                $gaps = $gaps || $passed === null;
            }
            $gaps = $gaps || $checks === [];
            $quality = $this->partnerQuality($user, $candidate, $vacancy->jobOrder?->company_id);
            $partnerPenalty = $quality['score'] === null ? 0 : min(10, (int) round((100 - $quality['score']) / 10));
            $preference = (int) ($candidate->preferences['target_country_id'] ?? 0) === (int) $vacancy->jobOrder?->country_id;
            $match = $failed ? 'NOT_ELIGIBLE' : ($gaps ? 'MATCH_WITH_GAPS' : 'MATCH');
            $score = $checks === [] ? 0 : (int) round(collect($checks)->where('result', 'Sesuai')->count() / count($checks) * 100);

            return ['vacancy_id' => $vacancy->id, 'title' => $vacancy->title, 'country' => $vacancy->jobOrder?->country?->name, 'partner' => $vacancy->jobOrder?->company?->name, 'match' => $match, 'score' => $score, 'preference' => $preference, 'partner_penalty' => $partnerPenalty, 'partner_quality' => $quality['label'], 'checks' => $checks, 'explanation' => ($preference ? 'Sesuai negara pilihan. ' : '').'Kecocokan persyaratan terstruktur; kapasitas LPK, dukungan dan hasil partner tetap perlu review manusia.', 'freshness' => $vacancy->updated_at?->toIso8601String()];
        })->sortByDesc(fn (array $row): int => ($row['match'] === 'NOT_ELIGIBLE' ? -1000 : 0) + $row['score'] + ($row['preference'] ? 5 : 0) - $row['partner_penalty'])->take(3)->values();
        $this->snapshot($candidate, 'routes:user:'.$user->id, $options->all());

        return $options;
    }

    public function dashboard(User $user, Candidate $candidate): array
    {
        $this->authorize($user, $candidate, 'candidate.read.timeline');
        $candidate->loadMissing(['person', 'contacts']);
        $application = Application::where('candidate_id', $candidate->id)->with(['vacancy.jobOrder.country', 'vacancy.jobOrder.company', 'currentStage'])->latest('id')->first();
        $partner = $application?->vacancy?->jobOrder?->company_id;
        $journeyEnabled = ! $partner || config('care.partner_flags.'.$partner) !== false;
        $this->sync($candidate);
        $template = $this->template($application);
        $stages = json_decode($template->stages, true);
        $events = $this->ledger($user, $candidate);
        $canIssues = $this->allowed($user, $candidate, 'incident.read');
        $canHealth = $this->allowed($user, $candidate, 'health.prescreen.read') || $this->allowed($user, $candidate, 'health.mcu.summary.read');
        $issues = $canIssues ? DB::table('care_issues')->where('candidate_id', $candidate->id)->where(fn ($q) => $q->whereNull('application_id')->orWhereIn('application_id', app(AccessPolicy::class)->applicationIds($user, 'incident.read')))->orderByDesc('id')->get()->map(function (object $row) use ($canHealth, $user): object {
            $applicationHealth = ! $row->application_id || app(AccessPolicy::class)->applicationIds($user, 'health.prescreen.read')->where('applications.id', $row->application_id)->exists() || app(AccessPolicy::class)->applicationIds($user, 'health.mcu.summary.read')->where('applications.id', $row->application_id)->exists();
            $row->data = $row->category === 'HEALTH' && (! $canHealth || ! $applicationHealth) ? ['description' => 'Rincian kesehatan terbatas sesuai izin akses.'] : $this->decode($row->details);
            $row->escalations = DB::table('care_escalations')->where('issue_id', $row->id)->get();

            return $row;
        }) : collect();
        $supports = DB::table('care_support_requests')->where('candidate_id', $candidate->id)->where(function ($query) use ($user, $candidate, $canIssues): void {
            $query->where('requested_by', $user->id);
            if ($this->allowed($user, $candidate, 'finance.cpmi_benefit.read')) {
                $query->orWhere('emergency', false);
            }
            if ($canIssues) {
                $query->orWhere('emergency', true);
            }
        })->orderByDesc('id')->get();
        $requestableIssues = DB::table('care_issues')->where('candidate_id', $candidate->id)->where('category', 'HEALTH')->whereNotIn('status', ['RESOLVED', 'CLOSED'])->when(! $canIssues, fn ($q) => $q->where('opened_by', $user->id))->get(['id', 'severity']);
        $risk = $this->risk($user, $candidate, $events, $issues, $application);
        $partnerQuality = $this->partnerQuality($user, $candidate, $partner);
        $routes = $journeyEnabled ? $this->routes($user, $candidate) : collect();
        $automaticRatio = $events->isEmpty() ? 0 : (int) round($events->where('auto_generated', true)->count() / $events->count() * 100);
        app(AccessPolicy::class)->audit($user, 'care.sensitive_view', ['candidate_id' => $candidate->id, 'incidents_visible' => $canIssues]);

        return compact('candidate', 'application', 'template', 'stages', 'events', 'issues', 'supports', 'risk', 'partnerQuality', 'routes', 'automaticRatio', 'requestableIssues', 'journeyEnabled');
    }
}
