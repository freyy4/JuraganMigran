<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationBlocker;
use App\Models\CandidateDocument;
use App\Models\Interview;
use App\Models\MedicalEvent;
use App\Models\Task;
use App\Models\TrainingEnrollment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TrustCommitmentProtocol
{
    public function available(): bool
    {
        return config('protocol.enabled') && Schema::hasTable('protocol_runs');
    }

    public function policy(): array
    {
        return json_decode(DB::table('protocol_settings')->where('id', 1)->value('policy'), true);
    }

    public function authorize(User $actor, Application $application, string $permission): void
    {
        abort_unless(app(AccessPolicy::class)->applicationIds($actor, $permission)->where('applications.id', $application->id)->exists(), 403);
    }

    public function initialize(Application $application, bool $legacy = false): object
    {
        return DB::transaction(function () use ($application, $legacy): object {
            Application::withoutGlobalScopes()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            if ($run = DB::table('protocol_runs')->where('application_id', $application->id)->first()) {
                return $run;
            }
            $policy = $this->policy();
            $stage = 'opt_in';
            if ($legacy) {
                $stage = match (true) {
                    $application->trainingEnrollments()->whereIn('status', ['ongoing', 'completed'])->exists() => 'country',
                    $application->medicalEvents()->where('status', 'completed')->where('clearance_status', 'fit')->exists() => 'golden',
                    $application->medicalEvents()->where('status', 'completed')->exists() => 'result',
                    $application->medicalEvents()->where('status', 'scheduled')->exists() => 'custody_mcu',
                    $application->interviews()->where('status', 'completed')->where('outcome', 'continue')->exists() => 'family',
                    $application->interviews()->exists() => 'interview',
                    default => 'opt_in',
                };
            }
            $id = DB::table('protocol_runs')->insertGetId(['application_id' => $application->id, 'mode' => $legacy ? 'SHADOW' : $policy['new_application_mode'], 'stage' => $stage, 'definition' => json_encode($policy), 'legacy_stage' => $legacy ? ($application->currentStage?->code ?? 'UNMAPPED') : null, 'created_at' => now(), 'updated_at' => now()]);
            $run = DB::table('protocol_runs')->find($id);
            $this->openStage($application, $run, $stage);
            $this->ensureConsent($application, $run);
            $this->event($application, 'protocol-init:'.$id, 'PROTOCOL_STARTED', ['title' => 'Protokol dimulai: '.$run->mode, 'stage' => $stage]);

            return $run;
        });
    }

    private function definition(object $run): array
    {
        return json_decode($run->definition, true);
    }

    private function openStage(Application $application, object $run, string $stage): void
    {
        $definition = $this->definition($run)['stages'][$stage];
        $owner = $this->routeOwner($application, $definition);
        $due = now()->addHours($definition['hours']);
        $task = null;
        if ($owner || $stage === 'golden') {
            $task = Task::create(['application_id' => $application->id, 'assigned_to' => $owner, 'task_type' => 'protocol', 'title' => $definition['action'], 'due_at' => $due, 'status' => 'open', 'priority' => $stage === 'golden' ? 'high' : 'normal', 'created_by' => auth()->id()])->id;
        }
        DB::table('protocol_stage_instances')->insert(['run_id' => $run->id, 'stage' => $stage, 'queue' => $definition['queue'], 'owner_id' => $owner, 'next_action' => $definition['action'], 'due_at' => $due, 'task_id' => $task, 'entered_at' => now()]);
        $this->event($application, 'protocol-handoff:'.$run->id.':'.$stage, 'STAGE_HANDOFF', ['title' => $definition['label'].' → '.$definition['queue'], 'owner_id' => $owner, 'next_action' => $definition['action'], 'due_at' => $due->toIso8601String()]);
    }

    private function routeOwner(Application $application, array $definition, ?int $exclude = null): ?int
    {
        $country = strtoupper($application->vacancy?->jobOrder?->country?->code ?? '');
        $hour = now('Asia/Jakarta')->hour;
        $profiles = DB::table('protocol_routing_profiles')->where('available', true)->where('start_hour', '<=', $hour)->where('end_hour', '>', $hour)->when($exclude, fn ($q) => $q->where('user_id', '!=', $exclude))->lockForUpdate()->get();
        return $profiles->filter(function ($profile) use ($application, $definition, $country): bool {
            $queues = json_decode($profile->queues, true);
            $countries = json_decode($profile->country_codes, true);
            $user = User::find($profile->user_id);
            return $user && in_array($definition['queue'], $queues, true) && ($countries === [] || in_array($country, $countries, true)) && app(AccessPolicy::class)->applicationIds($user, $definition['permission'])->where('applications.id', $application->id)->exists();
        })->map(function ($profile): object {
            $profile->load = DB::table('protocol_stage_instances')->whereNull('exited_at')->where('owner_id', $profile->user_id)->count();
            return $profile;
        })->filter(fn ($p) => $p->load < $p->capacity)->sortBy(fn ($p) => [$p->load / max(1, $p->capacity), $p->user_id])->first()?->user_id;
    }

    private function event(Application $application, string $key, string $type, array $payload, string $permission = 'candidate.read.timeline'): void
    {
        app(CareJourney::class)->event($application->candidate, $key, $type, $payload, $permission, auth()->user(), $application->id, true, $this->careStage($type));
    }

    private function careStage(string $type): string
    {
        return match ($type) {
            'MCU_FIT', 'GOLDEN_WINDOW_BREACHED', 'LPK_ADMITTED' => 'lpk',
            'FAMILY_CONSENT_VERIFIED', 'FAMILY_CONSENT_UPDATED' => 'family',
            'APPOINTMENT_CONFIRMED', 'APPOINTMENT_REMINDER', 'MCU_RESULT_RECORDED' => 'mcu',
            'INTERVIEW_COMPLETED' => 'screening',
            default => 'trust',
        };
    }

    private function lastAction(object $run, string $stage): array
    {
        $payload = DB::table('protocol_actions')->where('run_id', $run->id)->where('stage', $stage)->where('action', 'outcome')->latest('id')->value('payload');
        return $payload ? json_decode(Crypt::decryptString($payload), true) : [];
    }

    public function gate(Application $application, object $run): array
    {
        $action = $this->lastAction($run, $run->stage);
        $medical = $application->medicalEvents()->latest('id')->first();
        $consent = DB::table('protocol_family_consents')->where('run_id', $run->id)->first();
        $appointment = DB::table('protocol_appointments')->where('run_id', $run->id)->first();
        $result = match ($run->stage) {
            'opt_in' => [filled($action['interest'] ?? null) && ($action['inbound_confirmed'] ?? false), 'Konfirmasi percakapan masuk dan minat nyata CPMI.'],
            'trust' => [($action['response'] ?? '') === 'UNDERSTOOD' && ($action['checklist_complete'] ?? false) && isset($action['content_id']), 'Trust Pack Approved dan checklist pemahaman harus selesai.'],
            'value' => [($action['wants_continue'] ?? false) && isset($action['vacancy_snapshot']), 'Jelaskan snapshot lowongan aktif dan konfirmasi keinginan lanjut.'],
            'qualification' => [$this->qualified($application), 'Lengkapi persyaratan lowongan: Sesuai atau Perlu Tinjauan dengan alasan.'],
            'health' => [$application->latestHealthInterview()->where('status', 'completed')->exists(), 'Screening awal wajib diselesaikan melalui modul kesehatan; bukan diagnosis.'],
            'documents' => [$this->documentsDiscovered($application, $action), 'Review daftar dokumen dan keluarga; setiap dokumen kurang harus memiliki kendala ber-PIC.'],
            'interview' => [Interview::where('application_id', $application->id)->where('type', 'senior_protocol')->where('status', 'completed')->where('outcome', 'continue')->whereNotNull('protocol_rubric')->exists(), 'Wawancara senior dengan tujuh rubric dan outcome Lanjut diperlukan.'],
            'family' => [$this->familyReady($application, $consent), 'Selesaikan izin keluarga, bukti dokumen dan verifikasi sesuai aturan program.'],
            'appointment' => [$appointment && $appointment->status === 'CONFIRMED' && $appointment->medical_event_id !== null, 'Konfirmasi paket kunjungan dengan MCU, PIC, lokasi dan transport.'],
            'custody_mcu' => [$appointment && $appointment->status === 'ATTENDED' && $medical && $this->originalsReady($application), 'Konfirmasi hadir, MCU terhubung dan custody semua dokumen asli wajib.'],
            'waiting' => [$medical?->status === 'completed' && filled($medical?->clearance_status), 'Menunggu hasil resmi dari petugas medis berwenang.'],
            'result' => [$medical?->status === 'completed' && $medical?->clearance_status === 'fit', 'Hanya hasil resmi FIT dapat menuju Golden Window; UNFIT/PENDING/RETEST perlu tindak lanjut.'],
            'golden' => [DB::table('protocol_golden_windows')->where('run_id', $run->id)->where('status', 'OPEN')->exists() && filled($this->lastAction($run, 'golden')['handover'] ?? null) && $application->trainingEnrollments()->whereIn('status', ['scheduled', 'ongoing', 'completed'])->exists(), 'Tentukan batch LPK aktual, transport dan handover.'],
            'onboarding' => [$application->trainingEnrollments()->whereIn('status', ['ongoing', 'completed'])->whereNotNull('joined_at')->exists(), 'Konfirmasi admission aktual melalui pelatihan / check-in LPK.'],
            'country' => [false, 'Lanjutkan proses negara melalui template workflow penempatan yang berlaku.'],
        };
        $blocked = DB::table('protocol_stage_instances')->where('run_id', $run->id)->whereNull('exited_at')->where('blocker_status', 'BLOCKED')->exists() || $application->blockers()->where('priority', 'urgent')->whereNotIn('status', [...ApplicationBlocker::RESOLVED, 'closed', 'cancelled'])->exists();
        return ['passed' => (bool) $result[0] && ! $blocked, 'reason' => $blocked ? 'Selesaikan kendala aktif terlebih dahulu. '.$result[1] : $result[1], 'blocked' => $blocked];
    }

    private function familyReady(Application $application, ?object $consent): bool
    {
        $policy = json_decode(app(CareJourney::class)->template($application)->policy ?? '{}', true);
        if (! ($policy['consent_required'] ?? false)) {
            return true;
        }
        if (! $consent || $consent->status !== 'COMPLETE' || ! $consent->verified_at || ! $consent->document_id) {
            return false;
        }
        return app(DocumentRequirementEngine::class)->rows($application)->contains(fn ($row) => (int) $row['rule']->document_type_id === (int) ($policy['consent_document_type_id'] ?? 0) && $row['verified']);
    }

    private function qualified(Application $application): bool
    {
        $requirements = $application->vacancy->currentRequirements()->where('is_required', true)->get();
        if ($requirements->isEmpty()) {
            return false;
        }
        return $requirements->every(fn ($r) => $application->qualificationResults()->where('vacancy_requirement_id', $r->id)->where(fn ($q) => $q->where('result', 'passed')->orWhere(fn ($review) => $review->where('result', 'review')->whereNotNull('reason')->where('reason', '!=', '')))->exists());
    }

    public function checklist(Application $application): array
    {
        return app(DocumentRequirementEngine::class)->rows($application)->map(fn ($row) => ['type_id' => (int) $row['rule']->document_type_id, 'name' => $row['rule']->documentType?->name, 'required' => (bool) $row['rule']->is_required, 'original' => (bool) $row['rule']->original_required, 'status' => $row['status'], 'verified' => (bool) $row['verified']])->values()->all();
    }

    private function documentsDiscovered(Application $application, array $action): bool
    {
        if (! ($action['reviewed'] ?? false)) {
            return false;
        }
        $missing = collect($this->checklist($application))->where('required', true)->where('verified', false);
        return $missing->isEmpty() || $application->blockers()->where('category', 'DOCUMENT')->whereNotIn('status', [...ApplicationBlocker::RESOLVED, 'closed', 'cancelled'])->whereNotNull('owner_id')->whereNotNull('due_at')->whereNotNull('next_action')->exists();
    }

    private function originalsReady(Application $application): bool
    {
        $requirements = collect($this->checklist($application))->where('required', true)->where('original', true);
        return $requirements->every(fn ($row) => DB::table('original_document_custodies as custody')->join('candidate_documents as d', 'd.id', '=', 'custody.candidate_document_id')->where('d.candidate_id', $application->candidate_id)->where('d.document_type_id', $row['type_id'])->where(fn ($q) => $q->where('custody.application_id', $application->id)->orWhereNull('custody.application_id'))->whereIn('custody.custody_state', ['RECEIVED_BY_JM', 'HELD_BY_JM', 'HANDED_TO_PT', 'HELD_BY_PT'])->exists());
    }

    private function ensureConsent(Application $application, object $run): void
    {
        $policy = json_decode(app(CareJourney::class)->template($application)->policy ?? '{}', true);
        if (! ($policy['consent_required'] ?? false)) {
            return;
        }
        $marital = $application->candidate->person?->marital_status ?? 'single';
        $signatory = $policy['signatory_roles'][$marital] ?? ($marital === 'married' ? 'SPOUSE' : 'PARENT_GUARDIAN');
        DB::table('protocol_family_consents')->insertOrIgnore(['run_id' => $run->id, 'signatory_role' => $signatory, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Serialize every mutation on the application, then its run. */
    public function act(User $actor, Application $application, array $data): void
    {
        $this->authorize($actor, $application, 'process.update');
        DB::transaction(function () use ($actor, $application, $data): void {
            $application = Application::withoutGlobalScopes()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $run = DB::table('protocol_runs')->where('application_id', $application->id)->lockForUpdate()->first() ?? $this->initialize($application);
            $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if ($previous = DB::table('protocol_actions')->where('request_key', $data['request_key'])->first()) {
                abort_unless($previous->run_id === $run->id && $previous->actor_id === $actor->id && hash_equals($previous->fingerprint, $fingerprint), 409, 'Kunci permintaan sudah digunakan untuk aksi berbeda.');
                return;
            }
            abort_unless($run->version === (int) $data['version'] && $run->stage === $data['stage'], 409, 'Proses sudah berubah. Muat ulang untuk melihat PIC dan gate terbaru.');
            $definition = $this->definition($run)['stages'][$run->stage];
            $this->authorize($actor, $application, in_array($data['action'], ['mode', 'override'], true) ? 'protocol.override' : $definition['permission']);
            $payload = $data;
            switch ($data['action']) {
                case 'advance':
                case 'override':
                    $gate = $this->gate($application, $run);
                    $payload['gate_evaluation'] = $gate;
                    if ($data['action'] === 'override') {
                        if (blank($data['reason_code'] ?? null) || blank($data['reason'] ?? null) || ! ($data['risk_acknowledged'] ?? false)) {
                            throw ValidationException::withMessages(['reason' => 'Override memerlukan reason code, alasan dan konfirmasi risiko.']);
                        }
                        if ($run->stage === 'result' || ($data['target'] ?? '') === 'golden') {
                            throw ValidationException::withMessages(['target' => 'Override tidak dapat menyatakan FIT. Gunakan hasil MCU resmi.']);
                        }
                        $payload['skipped_gate'] = $run->stage;
                    } elseif (! $gate['passed'] && $run->mode === 'ENFORCED') {
                        throw ValidationException::withMessages(['gate' => $gate['reason']]);
                    }
                    $this->advance($application, $run, $data['target'] ?? null, $data['action'] === 'override');
                    break;
                case 'outcome':
                    if (! in_array($run->stage, ['opt_in', 'trust', 'value', 'documents', 'interview', 'golden'], true)) {
                        throw ValidationException::withMessages(['stage' => 'Gunakan modul sumber untuk tahap ini.']);
                    }
                    $payload = $this->outcome($actor, $application, $run, $data);
                    break;
                case 'block':
                    $this->block($actor, $application, $run, $data);
                    break;
                case 'unblock':
                    $stage = DB::table('protocol_stage_instances')->where('run_id', $run->id)->whereNull('exited_at')->firstOrFail();
                    if ($stage->blocker_id) {
                        ApplicationBlocker::withoutGlobalScopes()->whereKey($stage->blocker_id)->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $actor->id]);
                    }
                    DB::table('protocol_stage_instances')->where('id', $stage->id)->update(['blocker_status' => 'CLEAR', 'blocker_id' => null]);
                    $this->event($application, 'protocol-unblocked:'.$data['request_key'], 'PROCESS_UNBLOCKED', ['title' => 'Kendala proses diselesaikan']);
                    break;
                case 'consent':
                    $this->consent($actor, $application, $run, $data);
                    break;
                case 'appointment':
                    $this->appointment($actor, $application, $run, $data);
                    break;
                case 'mode':
                    if (blank($data['reason'] ?? null)) {
                        throw ValidationException::withMessages(['reason' => 'Alasan review migrasi wajib diisi.']);
                    }
                    $order = ['SHADOW' => 0, 'GUIDED' => 1, 'ENFORCED' => 2];
                    if (abs($order[$data['mode']] - $order[$run->mode]) > 1) {
                        throw ValidationException::withMessages(['mode' => 'Terapkan bertahap: Shadow → Guided → Enforced.']);
                    }
                    DB::table('protocol_runs')->where('id', $run->id)->update(['mode' => $data['mode'], 'reviewed_at' => now()]);
                    break;
            }
            DB::table('protocol_actions')->insert(['run_id' => $run->id, 'request_key' => $data['request_key'], 'stage' => $run->stage, 'action' => $data['action'], 'actor_id' => $actor->id, 'fingerprint' => $fingerprint, 'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), 'created_at' => now()]);
            DB::table('protocol_runs')->where('id', $run->id)->increment('version', 1, ['updated_at' => now()]);
            app(AccessPolicy::class)->audit($actor, 'protocol.'.$data['action'], ['application_id' => $application->id, 'stage' => $run->stage, 'version' => $run->version, 'request_key' => $data['request_key']]);
        });
    }

    private function advance(Application $application, object $run, ?string $target = null, bool $override = false): void
    {
        $keys = array_keys($this->definition($run)['stages']);
        $index = array_search($run->stage, $keys, true);
        $next = $keys[$index + 1] ?? null;
        $target ??= $next;
        if (! $target || ! in_array($target, $keys, true) || (! $override && $target !== $next) || array_search($target, $keys, true) <= $index) {
            throw ValidationException::withMessages(['target' => 'Urutan protokol wajib dipertahankan.']);
        }
        $old = DB::table('protocol_stage_instances')->where('run_id', $run->id)->whereNull('exited_at')->firstOrFail();
        DB::table('protocol_stage_instances')->where('id', $old->id)->update(['exited_at' => now()]);
        if ($old->task_id) {
            Task::withoutGlobalScopes()->whereKey($old->task_id)->whereNotIn('status', [...Task::COMPLETED_STATUSES, 'cancelled'])->update(['status' => 'completed', 'completed_at' => now()]);
        }
        DB::table('protocol_runs')->where('id', $run->id)->update(['stage' => $target]);
        $this->openStage($application, $run, $target);
        if ($old->blocker_status === 'BLOCKED') {
            DB::table('protocol_stage_instances')->where('run_id', $run->id)->where('stage', $target)->update(['blocker_status' => 'BLOCKED', 'blocker_id' => $old->blocker_id]);
        }
        $this->ensureConsent($application, $run);
        $this->event($application, 'protocol-transition:'.$run->id.':'.$run->version.':'.$target, $override ? 'PROTOCOL_OVERRIDE' : 'PROTOCOL_TRANSITION', ['title' => $run->stage.' → '.$target, 'from' => $run->stage, 'to' => $target, 'override' => $override]);
    }

    private function outcome(User $actor, Application $application, object $run, array $data): array
    {
        switch ($run->stage) {
            case 'trust':
                $content = DB::table('protocol_content_versions')->where('id', $data['content_id'] ?? 0)->where('status', 'Approved')->where('effective_at', '<=', now())->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->where(fn ($q) => $q->whereNull('vacancy_id')->orWhere('vacancy_id', $application->vacancy_id))->first();
                if (! $content) {
                    throw ValidationException::withMessages(['content_id' => 'Pilih Trust Pack Approved yang sedang berlaku untuk program ini.']);
                }
                $data['content_snapshot'] = ['id' => $content->id, 'version' => $content->version, 'source' => $content->source, 'body' => $content->body];
                break;
            case 'value':
                $vacancy = $application->vacancy;
                if (! in_array($vacancy->status, ['active', 'published', 'open'], true) || ($vacancy->valid_through && $vacancy->valid_through->isPast())) {
                    throw ValidationException::withMessages(['gate' => 'Lowongan belum aktif atau sudah berakhir.']);
                }
                $data['vacancy_snapshot'] = ['id' => $vacancy->id, 'title' => $vacancy->title, 'salary' => $vacancy->salary, 'currency' => $vacancy->currency, 'country' => $vacancy->jobOrder?->country?->name, 'contract' => $vacancy->contract_duration, 'terms' => $vacancy->description, 'updated_at' => $vacancy->updated_at->toIso8601String()];
                break;
            case 'interview':
                $rubric = $data['rubric'] ?? [];
                if (array_keys($rubric) !== array_keys(config('protocol.rubric')) || ! isset($data['interview_outcome'])) {
                    throw ValidationException::withMessages(['rubric' => 'Isi tujuh rubric dan satu outcome wawancara.']);
                }
                if ($data['interview_outcome'] !== 'continue' && blank($data['reason_code'] ?? null)) {
                    throw ValidationException::withMessages(['reason_code' => 'Reason code wajib untuk outcome selain Lanjut.']);
                }
                Interview::create(['application_id' => $application->id, 'type' => 'senior_protocol', 'scheduled_at' => now(), 'interviewer_id' => $actor->id, 'status' => 'completed', 'outcome' => $data['interview_outcome'], 'notes' => $data['note'] ?? null, 'protocol_rubric' => $rubric, 'protocol_reason_code' => $data['reason_code'] ?? null]);
                $this->event($application, 'protocol-interview:'.$data['request_key'], 'INTERVIEW_COMPLETED', ['title' => 'Wawancara senior selesai', 'outcome' => $data['interview_outcome']]);
                break;
            case 'golden':
                if (blank($data['handover'] ?? null) || blank($data['transport'] ?? null) || ! ($data['support_reviewed'] ?? false)) {
                    throw ValidationException::withMessages(['handover' => 'Pilih pelatihan aktual dan lengkapi handover serta transport.']);
                }
                $enrollment = TrainingEnrollment::where('application_id', $application->id)->whereKey($data['enrollment_id'] ?? 0)->with('batch.enrollments')->firstOrFail();
                if (in_array($enrollment->batch->status, ['cancelled', 'completed'], true) || ($enrollment->batch->capacity !== null && $enrollment->batch->enrollments->whereIn('status', ['scheduled', 'ongoing'])->count() > $enrollment->batch->capacity)) {
                    throw ValidationException::withMessages(['enrollment_id' => 'Batch tidak aktif atau kapasitas LPK sudah terlampaui.']);
                }
                $data['batch_id'] = $enrollment->batch_id;
                $data['target_admission_at'] = $enrollment->joined_at?->toIso8601String() ?? $enrollment->batch->start_date?->toIso8601String();
                $data['checkin_days'] = [1, 3, 7];
                break;
        }
        $careOutcome = match ($data['response'] ?? '') {
            'FAMILY_DOUBT' => 'FAMILY', 'DISTRUST', 'CLARIFY' => 'UNSURE', default => match ($data['reason_code'] ?? '') { 'FAMILY' => 'FAMILY', 'TRANSPORT', 'SUPPORT' => 'FINANCIAL', 'NO_RESPONSE' => 'UNREACHABLE', default => 'CONTINUE' },
        };
        $this->event($application, 'protocol-outcome:'.$data['request_key'], 'PROTOCOL_OUTCOME', ['title' => 'Hasil percakapan '.$run->stage.' dicatat', 'stage' => $run->stage, 'outcome' => $careOutcome]);
        if ($careOutcome !== 'CONTINUE' || (($data['interview_outcome'] ?? 'continue') !== 'continue')) {
            $next = config('care.outcomes.'.$careOutcome, 'Tindak lanjuti hasil wawancara');
            DB::table('protocol_stage_instances')->where('run_id', $run->id)->whereNull('exited_at')->update(['next_action' => 'Follow-up: '.$next]);
        }
        return $data;
    }

    private function block(User $actor, Application $application, object $run, array $data): void
    {
        foreach (['reason_code', 'reason', 'owner_id', 'next_action', 'due_at'] as $field) {
            if (blank($data[$field] ?? null)) {
                throw ValidationException::withMessages([$field => 'Kendala wajib memiliki alasan, PIC penyelesaian, tindakan dan tenggat.']);
            }
        }
        $owner = User::findOrFail($data['owner_id']);
        $this->authorize($owner, $application, 'process.update');
        $stage = DB::table('protocol_stage_instances')->where('run_id', $run->id)->whereNull('exited_at')->firstOrFail();
        if ($stage->blocker_id) {
            throw ValidationException::withMessages(['gate' => 'Selesaikan kendala tahap ini sebelum mencatat kendala lain.']);
        }
        $blocker = ApplicationBlocker::create(['application_id' => $application->id, 'stage_id' => $application->current_stage_id, 'category' => $data['reason_code'], 'description' => $data['reason'], 'priority' => 'high', 'owner_id' => $owner->id, 'due_at' => $data['due_at'], 'next_action' => $data['next_action'], 'status' => 'open']);
        DB::table('protocol_stage_instances')->where('id', $stage->id)->update(['blocker_status' => 'BLOCKED', 'blocker_id' => $blocker->id]);
        $this->event($application, 'protocol-blocked:'.$data['request_key'], 'PROCESS_BLOCKED', ['title' => 'Proses terkendala: '.config('protocol.blockers.'.$data['reason_code']), 'blocker_id' => $blocker->id, 'owner_id' => $owner->id]);
    }

    private function consent(User $actor, Application $application, object $run, array $data): void
    {
        if ($run->stage !== 'family') {
            throw ValidationException::withMessages(['stage' => 'Komitmen keluarga dicatat pada tahap Family Commitment.']);
        }
        $this->ensureConsent($application, $run);
        $case = DB::table('protocol_family_consents')->where('run_id', $run->id)->firstOrFail();
        $states = ['NOT_CONTACTED', 'EXPLAINED', 'HESITANT', 'AGREED', 'SENT', 'SIGNED', 'VERIFIED', 'COMPLETE'];
        $transitions = ['NOT_CONTACTED' => ['EXPLAINED'], 'EXPLAINED' => ['HESITANT', 'AGREED'], 'HESITANT' => ['EXPLAINED', 'AGREED'], 'AGREED' => ['SENT'], 'SENT' => ['SIGNED'], 'SIGNED' => ['VERIFIED'], 'VERIFIED' => ['COMPLETE'], 'COMPLETE' => []];
        if (! in_array($data['consent_status'] ?? '', $transitions[$case->status], true)) {
            throw ValidationException::withMessages(['consent_status' => 'Ikuti urutan status izin keluarga.']);
        }
        $changes = ['status' => $data['consent_status'], 'updated_at' => now(), 'version' => $case->version + 1];
        $contactId = $data['contact_id'] ?? $case->contact_id;
        if (! $contactId || ! $application->candidate->contacts()->whereKey($contactId)->exists()) {
            throw ValidationException::withMessages(['contact_id' => 'Pilih kontak keluarga dari profil CPMI.']);
        }
        $changes['contact_id'] = $contactId;
        if ($changes['status'] === 'SENT') {
            if (blank($data['template_version'] ?? null)) {
                throw ValidationException::withMessages(['template_version' => 'Versi surat izin wajib diisi.']);
            }
            $changes += ['template_version' => $data['template_version'], 'sent_at' => now()];
        }
        if (in_array($changes['status'], ['SIGNED', 'VERIFIED'], true)) {
            $document = CandidateDocument::whereKey($data['document_id'] ?? $case->document_id)->where('candidate_id', $application->candidate_id)->where(fn ($q) => $q->whereNull('application_id')->orWhere('application_id', $application->id))->first();
            $policy = json_decode(app(CareJourney::class)->template($application)->policy ?? '{}', true);
            if (! $document || (int) $document->document_type_id !== (int) ($policy['consent_document_type_id'] ?? 0)) {
                throw ValidationException::withMessages(['document_id' => 'Gunakan bukti izin keluarga sesuai tipe dokumen pada rule program.']);
            }
            $changes['document_id'] = $document->id;
            if ($changes['status'] === 'SIGNED') {
                $changes['signed_at'] = now();
            } else {
                $this->authorize($actor, $application, 'document.verify');
                if ($document->effectiveStatus() !== 'verified' || blank($data['verification_method'] ?? null) || (($policy['consent_endorsement_required'] ?? false) && blank($data['endorsement'] ?? null))) {
                    throw ValidationException::withMessages(['document_id' => 'Bukti terverifikasi, metode dan pengesahan sesuai rule wajib tersedia.']);
                }
                $changes += ['verified_at' => now(), 'verification_method' => $data['verification_method'], 'endorsement' => $data['endorsement'] ?? null];
            }
        }
        DB::table('protocol_family_consents')->where('id', $case->id)->update($changes);
        $this->event($application, 'protocol-consent:'.$data['request_key'], $changes['status'] === 'VERIFIED' ? 'FAMILY_CONSENT_VERIFIED' : 'FAMILY_CONSENT_UPDATED', ['title' => 'Izin keluarga: '.$changes['status']], 'document.read.metadata');
    }

    private function appointment(User $actor, Application $application, object $run, array $data): void
    {
        if (! in_array($run->stage, ['appointment', 'custody_mcu'], true)) {
            throw ValidationException::withMessages(['stage' => 'Kunjungan diatur setelah komitmen keluarga.']);
        }
        foreach (['pic_id', 'office_location', 'scheduled_at', 'transport', 'medical_event_id', 'appointment_status'] as $field) {
            if (blank($data[$field] ?? null)) {
                throw ValidationException::withMessages([$field => 'Lengkapi paket kunjungan, jadwal MCU, transport dan PIC.']);
            }
        }
        $pic = User::findOrFail($data['pic_id']);
        $this->authorize($pic, $application, 'protocol.ops');
        $medical = MedicalEvent::where('application_id', $application->id)->whereKey($data['medical_event_id'])->firstOrFail();
        $existing = DB::table('protocol_appointments')->where('run_id', $run->id)->first();
        if ($data['appointment_status'] === 'ATTENDED' && (! $existing || $run->stage !== 'custody_mcu')) {
            throw ValidationException::withMessages(['appointment_status' => 'Konfirmasi booking terlebih dahulu; hadir dicatat pada tahap Dokumen asli + MCU.']);
        }
        DB::table('protocol_appointments')->updateOrInsert(['run_id' => $run->id], ['medical_event_id' => $medical->id, 'pic_id' => $pic->id, 'office_location' => $data['office_location'], 'scheduled_at' => $data['scheduled_at'], 'transport' => $data['transport'], 'status' => $data['appointment_status'], 'checklist' => json_encode($this->checklist($application)), 'version' => ($existing->version ?? 0) + 1, 'created_at' => $existing->created_at ?? now(), 'updated_at' => now()]);
        $this->event($application, 'protocol-appointment:'.$data['request_key'], 'APPOINTMENT_CONFIRMED', ['title' => 'Paket kunjungan: '.$data['appointment_status'], 'scheduled_at' => $data['scheduled_at']]);
    }

    public function medicalSaved(MedicalEvent $medical): void
    {
        if (! $this->available()) {
            return;
        }
        $application = Application::withoutGlobalScopes()->find($medical->application_id);
        if (! $application || ! DB::table('protocol_runs')->where('application_id', $application->id)->exists()) {
            return;
        }
        DB::transaction(function () use ($application, $medical): void {
            Application::withoutGlobalScopes()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $run = DB::table('protocol_runs')->where('application_id', $application->id)->lockForUpdate()->firstOrFail();
            $this->event($application, 'protocol-medical:'.$medical->id.':'.$medical->updated_at->format('Uu').':'.$medical->clearance_status, 'MCU_RESULT_RECORDED', ['title' => 'Hasil MCU diperbarui oleh sumber medis', 'medical_event_id' => $medical->id], 'health.mcu.summary.read');
            if ((int) DB::table('medical_events')->where('application_id', $application->id)->max('id') !== $medical->id) {
                return;
            }
            $golden = DB::table('protocol_golden_windows')->where('run_id', $run->id)->first();
            if ($medical->status !== 'completed' || $medical->clearance_status !== 'fit') {
                if ($golden && $golden->status === 'OPEN') {
                    DB::table('protocol_golden_windows')->where('id', $golden->id)->update(['status' => 'MEDICAL_REVIEW', 'updated_at' => now()]);
                    Task::withoutGlobalScopes()->whereKey($golden->task_id)->update(['status' => 'cancelled']);
                }
                return;
            }
            if ($golden && in_array($golden->status, ['OPEN', 'CLOSED'], true)) {
                return;
            }
            if ($medical->expires_at && $medical->expires_at->isBefore(now()->startOfDay())) {
                return;
            }
            $definition = $this->definition($run);
            $admitted = TrainingEnrollment::withoutGlobalScopes()->where('application_id', $application->id)->whereIn('status', ['ongoing', 'completed'])->whereNotNull('joined_at')->where('joined_at', '<=', now())->first();
            if ($admitted) {
                return;
            }
            $taskId = $run->stage === 'golden' ? DB::table('protocol_stage_instances')->where('run_id', $run->id)->where('stage', 'golden')->value('task_id') : null;
            if (in_array($run->stage, ['waiting', 'result'], true)) {
                while ($run->stage !== 'golden') {
                    $this->advance($application, $run);
                    DB::table('protocol_runs')->where('id', $run->id)->increment('version');
                    $run = DB::table('protocol_runs')->find($run->id);
                }
                $taskId = DB::table('protocol_stage_instances')->where('run_id', $run->id)->where('stage', 'golden')->value('task_id');
            }
            DB::table('protocol_golden_windows')->updateOrInsert(['run_id' => $run->id], ['medical_event_id' => $medical->id, 'task_id' => $taskId, 'fit_at' => $medical->completed_at ?? now(), 'sla_hours' => json_encode($definition['golden_hours']), 'status' => 'OPEN', 'escalation_level' => 0, 'closed_at' => null, 'created_at' => $golden->created_at ?? now(), 'updated_at' => now()]);
            $this->event($application, 'protocol-fit:'.$run->id.':'.$medical->id, 'MCU_FIT', ['title' => 'MCU FIT: antrean prioritas Placement / LPK', 'queue' => 'FIT_TO_LPK'], 'health.mcu.summary.read');
        });
    }

    public function trainingSaved(TrainingEnrollment $enrollment): void
    {
        if (! $this->available() || ! in_array($enrollment->status, ['ongoing', 'completed'], true) || ! $enrollment->joined_at || $enrollment->joined_at->isFuture()) {
            return;
        }
        DB::transaction(function () use ($enrollment): void {
            $application = Application::withoutGlobalScopes()->whereKey($enrollment->application_id)->lockForUpdate()->firstOrFail();
            $run = DB::table('protocol_runs')->where('application_id', $application->id)->lockForUpdate()->first();
            if (! $run) {
                return;
            }
            $window = DB::table('protocol_golden_windows')->where('run_id', $run->id)->where('status', 'OPEN')->first();
            if ($window) {
                DB::table('protocol_golden_windows')->where('id', $window->id)->update(['status' => 'CLOSED', 'closed_at' => $enrollment->joined_at, 'enrollment_id' => $enrollment->id, 'updated_at' => now()]);
                if ($window->task_id) {
                    Task::withoutGlobalScopes()->whereKey($window->task_id)->update(['status' => 'completed', 'completed_at' => now()]);
                }
            }
            $this->event($application, 'protocol-admitted:'.$enrollment->id, 'LPK_ADMITTED', ['title' => 'Masuk LPK terkonfirmasi; check-in Care terjadwal', 'enrollment_id' => $enrollment->id]);
            app(CareJourney::class)->sync($application->candidate);
        });
    }

    public function assertPipelineTransition(Application $application, int $destination): void
    {
        if (! $this->available()) {
            return;
        }
        $run = DB::table('protocol_runs')->where('application_id', $application->id)->first();
        if ($run && $run->mode === 'ENFORCED' && $run->stage !== 'country') {
            $target = app(ProcessWorkflow::class)->stage($application, $destination);
            $current = $application->current_stage_id ? app(ProcessWorkflow::class)->stage($application, $application->current_stage_id) : null;
            if (($target['sequence'] ?? 0) > ($current['sequence'] ?? -1)) {
                throw ValidationException::withMessages(['to_stage_id' => 'Selesaikan gate Trust → Commitment sebelum melanjutkan pipeline penempatan.']);
            }
        }
    }

    public function assertMcuAllowed(Application $application): void
    {
        if (! $this->available()) {
            return;
        }
        $run = DB::table('protocol_runs')->where('application_id', $application->id)->first();
        if ($run && $run->mode === 'ENFORCED' && array_search($run->stage, array_keys($this->definition($run)['stages']), true) < 8) {
            throw ValidationException::withMessages(['application_id' => 'Selesaikan protokol hingga Office Appointment sebelum menjadwalkan MCU.']);
        }
    }

    public function monitor(): void
    {
        if (! $this->available()) {
            return;
        }
        DB::table('protocol_runs')->orderBy('id')->chunkById(100, function ($runs): void {
            foreach ($runs as $item) {
                DB::transaction(function () use ($item): void {
                    $application = Application::withoutGlobalScopes()->whereKey($item->application_id)->lockForUpdate()->first();
                    if (! $application) {
                        return;
                    }
                    $run = DB::table('protocol_runs')->where('id', $item->id)->lockForUpdate()->firstOrFail();
                    $stage = DB::table('protocol_stage_instances')->where('run_id', $run->id)->whereNull('exited_at')->firstOrFail();
                    $definition = $this->definition($run)['stages'][$stage->stage];
                    $profile = $stage->owner_id ? DB::table('protocol_routing_profiles')->where('user_id', $stage->owner_id)->first() : null;
                    $owner = $stage->owner_id ? User::find($stage->owner_id) : null;
                    $hour = now('Asia/Jakarta')->hour;
                    $unavailable = ! $owner || ! $owner->is_active || ! $profile || ! $profile->available || $hour < $profile->start_hour || $hour >= $profile->end_hour || ! app(AccessPolicy::class)->applicationIds($owner, $definition['permission'])->where('applications.id', $application->id)->exists();
                    if ($unavailable) {
                        $newOwner = $this->routeOwner($application, $definition, $stage->owner_id);
                        if ($newOwner !== $stage->owner_id) {
                            $taskId = $stage->task_id;
                            if ($taskId) {
                                Task::withoutGlobalScopes()->whereKey($taskId)->update(['assigned_to' => $newOwner]);
                            } elseif ($newOwner) {
                                $taskId = Task::create(['application_id' => $application->id, 'assigned_to' => $newOwner, 'task_type' => 'protocol', 'title' => $stage->next_action, 'due_at' => $stage->due_at, 'status' => 'open', 'priority' => $run->stage === 'golden' ? 'high' : 'normal'])->id;
                            }
                            DB::table('protocol_stage_instances')->where('id', $stage->id)->update(['owner_id' => $newOwner, 'task_id' => $taskId]);
                            DB::table('protocol_runs')->where('id', $run->id)->increment('version');
                            $this->event($application, 'protocol-reassign:'.$stage->id.':'.$run->version, 'PROTOCOL_REASSIGNED', ['title' => 'Handoff PIC; konteks dan kendala dipertahankan', 'from' => $stage->owner_id, 'to' => $newOwner, 'queue' => $stage->queue]);
                        }
                    }
                    if (Carbon::parse($stage->due_at)->isPast()) {
                        $this->event($application, 'protocol-overdue:'.$stage->id.':'.now('Asia/Jakarta')->toDateString(), 'PROTOCOL_SLA_BREACHED', ['title' => 'SLA tahap terlewati: '.$definition['label'], 'queue' => $stage->queue, 'next_action' => $stage->next_action]);
                    }
                    $golden = DB::table('protocol_golden_windows')->where('run_id', $run->id)->where('status', 'OPEN')->first();
                    if ($golden) {
                        $levels = json_decode($golden->sla_hours, true);
                        foreach ($levels as $index => $hours) {
                            if (Carbon::parse($golden->fit_at)->addHours($hours)->lte(now())) {
                                $this->event($application, 'protocol-golden-escalation:'.$golden->id.':'.($index + 1), 'GOLDEN_WINDOW_BREACHED', ['title' => ['Pengingat coordinator FIT → LPK', 'Eskalasi supervisor FIT → LPK', 'Watchlist management FIT → LPK'][$index], 'level' => $index + 1, 'queue' => 'FIT_TO_LPK']);
                                DB::table('protocol_golden_windows')->where('id', $golden->id)->update(['escalation_level' => max($golden->escalation_level, $index + 1), 'updated_at' => now()]);
                            }
                        }
                    }
                    $appointment = DB::table('protocol_appointments')->where('run_id', $run->id)->where('status', 'CONFIRMED')->first();
                    if ($appointment) {
                        $date = Carbon::parse($appointment->scheduled_at)->timezone('Asia/Jakarta')->startOfDay();
                        $day = (int) now('Asia/Jakarta')->startOfDay()->diffInDays($date, false);
                        if (in_array($day, [0, 1], true)) {
                            $this->event($application, 'protocol-appointment-reminder:'.$appointment->id.':'.$appointment->version.':'.$day, 'APPOINTMENT_REMINDER', ['title' => 'Pengingat kunjungan H-'.$day, 'scheduled_at' => $appointment->scheduled_at]);
                        }
                    }
                });
            }
        });
    }

    public function queue(User $actor): \Illuminate\Support\Collection
    {
        return DB::table('protocol_stage_instances as s')->join('protocol_runs as r', 'r.id', '=', 's.run_id')->join('applications as a', 'a.id', '=', 'r.application_id')->join('candidates as c', 'c.id', '=', 'a.candidate_id')->join('persons as p', 'p.id', '=', 'c.person_id')->leftJoin('users as u', 'u.id', '=', 's.owner_id')->leftJoin('protocol_golden_windows as g', 'g.run_id', '=', 'r.id')->whereNull('s.exited_at')->whereNull('a.deleted_at')->whereIn('a.id', app(AccessPolicy::class)->applicationIds($actor, 'process.read')->select('applications.id'))->select('s.*', 'r.application_id', 'r.mode', 'r.version', 'p.full_name', 'c.id as candidate_id', 'u.name as owner_name', 'g.fit_at', 'g.closed_at', 'g.escalation_level', 'g.status as golden_status')->orderBy('s.due_at')->get();
    }
}
