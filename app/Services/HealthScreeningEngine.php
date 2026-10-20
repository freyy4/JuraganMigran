<?php

namespace App\Services;

use App\Models\Application;
use App\Models\HealthInterview;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HealthScreeningEngine
{
    public const TYPES = ['YES_NO_UNKNOWN', 'YES_NO_UNKNOWN_NOT_APPLICABLE', 'SINGLE_SELECT', 'MULTI_SELECT', 'NUMBER', 'DATE', 'SHORT_TEXT_CONDITIONAL'];

    public const OUTCOMES = ['NOT_STARTED' => 'Belum dimulai', 'IN_PROGRESS' => 'Belum selesai', 'COMPLETED_NO_IMMEDIATE_FOLLOWUP' => 'Selesai, tanpa tindak lanjut segera', 'NEEDS_CLARIFICATION' => 'Perlu klarifikasi', 'REFER_TO_MCU' => 'Rujuk MCU', 'MEDICAL_REVIEW_REQUIRED' => 'Perlu review medis', 'CLOSED' => 'Ditutup'];

    public const NOTICE = 'Informasi ini merupakan screening awal berdasarkan jawaban CPMI dan bukan hasil diagnosis medis. Pemeriksaan/keputusan medis resmi mengikuti MCU atau tenaga/fasilitas yang berwenang.';

    public function resolved(Application $application): ?object
    {
        return app(ScopedTemplateResolver::class)->resolve($application, 'health_screening_templates', 'health_template_versions', 'health_screening_template_id');
    }

    public function questions(HealthInterview $session, Application $application): Collection
    {
        $version = $session->templateVersion ?? abort(404);

        return collect($version->snapshot['questions'])->filter(fn ($q) => isset($q['applicability_rule']['question_code']) || app(ScopedTemplateResolver::class)->applies($q['applicability_rule'] ?? null, $application))->values();
    }

    public function answers(HealthInterview $session): Collection
    {
        return DB::table('health_answers')->where('health_interview_id', $session->id)->get()->mapWithKeys(fn ($row) => [$row->question_id => $row->structured_value ? json_decode(Crypt::decryptString($row->structured_value), true) : []]);
    }

    public function start(Application $application, User $user): HealthInterview
    {
        return DB::transaction(function () use ($application, $user): HealthInterview {
            Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            $current = HealthInterview::where('application_id', $application->id)->latest('id')->first();
            if ($current?->source === 'STRUCTURED' && ! $current->completed_at) {
                return $current;
            }
            if ($current?->source === 'LEGACY_GENERIC' && ! $user->hasPermission('health.prescreen.review')) {
                abort(403, 'Supervisor perlu menyetujui pergantian pemeriksaan lama.');
            }
            $version = $this->resolved($application);
            if (! $version) {
                throw ValidationException::withMessages(['template' => 'Belum ada template terbit untuk konteks lamaran ini. Hubungi administrator.']);
            }
            $snapshot = json_decode($version->snapshot, true);
            if (collect($snapshot['questions'])->contains('restricted', true)) {
                abort_unless($user->hasPermission('health.sensitive.reveal'), 403);
            }
            $session = HealthInterview::create(['application_id' => $application->id, 'source' => 'STRUCTURED', 'health_template_version_id' => $version->id, 'status' => 'pending', 'outcome' => 'IN_PROGRESS']);
            app(AccessPolicy::class)->audit($user, 'health.session.started', ['session_id' => $session->id, 'version_id' => $version->id, 'previous_session_id' => $current?->id]);

            return $session;
        });
    }

    public function save(Application $application, User $user, array $input): void
    {
        DB::transaction(function () use ($application, $user, $input): void {
            Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            $session = HealthInterview::where('application_id', $application->id)->whereKey($input['session_id'] ?? 0)->lockForUpdate()->firstOrFail();
            if ($session->source !== 'STRUCTURED' || $session->completed_at) {
                throw ValidationException::withMessages(['session_id' => 'Sesi sudah selesai atau merupakan catatan lama. Mulai sesi baru bila perlu koreksi.']);
            }
            $questions = $this->questions($session, $application);
            if ($questions->contains('restricted', true)) {
                abort_unless($user->hasPermission('health.sensitive.reveal'), 403);
            }
            $complete = ($input['intent'] ?? '') === 'complete';
            if ($complete) {
                abort_unless($user->hasPermission('health.prescreen.complete'), 403);
            }
            $rules = ['intent' => ['required', Rule::in(['draft', 'complete'])], 'answers' => ['nullable', 'array', Rule::array($questions->pluck('id')->map(fn ($id) => (string) $id)->all())]];
            $saved = $this->answers($session);
            $incoming = array_replace($saved->all(), $input['answers'] ?? []);
            $activeQuestions = $questions->filter(function ($q) use ($questions, $incoming) {
                $dependency = $q['applicability_rule']['question_code'] ?? null;
                if (! $dependency) {
                    return true;
                }
                $parent = $questions->firstWhere('code', $dependency);

                return $parent && in_array($incoming[$parent['id']]['value'] ?? null, $q['applicability_rule']['values'] ?? [], true);
            });
            foreach ($questions as $q) {
                $id = $q['id'];
                $prefix = 'answers.'.$id;
                $rules[$prefix] = ['sometimes', 'array:value,detail,details'];
                $active = $activeQuestions->contains('id', $id);
                if (! $active) {
                    $incoming[$id] = ['value' => null, 'detail' => null, 'details' => []];
                }
                $required = $complete && $active && $q['is_required'];
                $type = $q['answer_type'];
                $options = array_keys($q['options'] ?? []);
                $rules[$prefix.'.value'] = [$required ? 'required' : 'nullable'];
                if ($type === 'MULTI_SELECT') {
                    $rules[$prefix.'.value'][] = 'array';
                    $rules[$prefix.'.value.*'] = [Rule::in($options)];
                } elseif ($type === 'NUMBER') {
                    $rules[$prefix.'.value'][] = 'numeric';
                } elseif ($type === 'DATE') {
                    $rules[$prefix.'.value'][] = 'date_format:Y-m-d';
                } elseif ($type === 'SHORT_TEXT_CONDITIONAL') {
                    $rules[$prefix.'.value'][] = 'string';
                    $rules[$prefix.'.value'][] = 'max:500';
                } else {
                    $rules[$prefix.'.value'][] = Rule::in($options);
                }
                $rules[$prefix.'.detail'] = ['nullable', 'string', 'max:2000'];
                $row = $incoming[$id] ?? [];
                $trigger = $q['clarification_rule']['when'] ?? [];
                $triggered = in_array($row['value'] ?? null, $trigger, true);
                $fields = $q['clarification_rule']['fields'] ?? [];
                if ($complete && $triggered && ! $fields) {
                    $rules[$prefix.'.detail'][0] = 'required';
                }
                $rules[$prefix.'.details'] = ['nullable', 'array', Rule::array(array_column($fields, 'key'))];
                foreach ($fields as $field) {
                    $key = $prefix.'.details.'.$field['key'];
                    $rules[$key] = [$complete && $triggered && ($field['required'] ?? false) ? 'required' : 'nullable'];
                    if ($field['type'] === 'MULTI_SELECT') {
                        $rules[$key][] = 'array';
                        $rules[$key.'.*'] = [Rule::in(array_keys($field['options']))];
                    } elseif ($field['type'] === 'NUMBER') {
                        $rules[$key][] = 'numeric';
                    } elseif ($field['type'] === 'DATE') {
                        $rules[$key][] = 'date_format:Y-m-d';
                    } elseif ($field['type'] === 'SINGLE_SELECT') {
                        $rules[$key][] = Rule::in(array_keys($field['options']));
                    } else {
                        $rules[$key][] = 'string';
                        $rules[$key][] = 'max:500';
                    }
                }
            }
            // Drafts may omit answers; completion validates a merged copy of all previously saved answers.
            $merged = [...$input, 'answers' => $incoming];
            $data = Validator::make($merged, $rules, ['required' => 'Lengkapi jawaban atau klarifikasi wajib sebelum menyelesaikan screening.', 'in' => 'Pilihan jawaban tidak sesuai template.', 'array' => 'Format jawaban tidak sesuai template.', 'numeric' => 'Jawaban harus berupa angka.', 'date_format' => 'Tanggal tidak valid.'])->validate();
            if ($complete && $activeQuestions->isEmpty()) {
                throw ValidationException::withMessages(['answers' => 'Template tidak memiliki pertanyaan yang berlaku.']);
            }
            $count = 0;
            $clarifications = 0;
            $outcome = $complete ? 'COMPLETED_NO_IMMEDIATE_FOLLOWUP' : 'IN_PROGRESS';
            $priority = ['NEEDS_CLARIFICATION' => 1, 'REFER_TO_MCU' => 2, 'MEDICAL_REVIEW_REQUIRED' => 3];
            foreach ($questions as $q) {
                $row = $data['answers'][$q['id']] ?? [];
                $value = $row['value'] ?? null;
                if ($value !== null && $value !== '' && $value !== []) {
                    $count++;
                }
                $needs = in_array($value, $q['clarification_rule']['when'] ?? [], true);
                if ($needs) {
                    $clarifications++;
                } else {
                    $row['detail'] = null;
                    $row['details'] = [];
                }
                $recommendation = in_array($value, $q['followup_rule']['when'] ?? [], true) ? ($q['followup_rule']['outcome'] ?? 'NEEDS_CLARIFICATION') : null;
                if ($complete && isset($priority[$recommendation]) && ($priority[$recommendation] > ($priority[$outcome] ?? 0))) {
                    $outcome = $recommendation;
                }
                DB::table('health_answers')->updateOrInsert(['health_interview_id' => $session->id, 'question_id' => $q['id']], ['structured_value' => Crypt::encryptString(json_encode($row)), 'answered_by' => $user->id, 'answered_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
            $session->update(['status' => $complete ? 'completed' : 'pending', 'outcome' => $outcome, 'clarification_count' => $clarifications, 'completeness_percentage' => $activeQuestions->count() ? (int) round($count / $activeQuestions->count() * 100) : 0, 'completed_by' => $complete ? $user->id : null, 'completed_at' => $complete ? now() : null]);
            if ($complete && isset($priority[$outcome])) {
                Task::create(['application_id' => $application->id, 'assigned_to' => $application->assigned_cs_id, 'title' => 'Tindak lanjut pemeriksaan awal kesehatan', 'description' => 'Buka pemeriksaan dengan hak akses yang sesuai untuk melihat langkah berikutnya.', 'task_type' => 'health_follow_up', 'priority' => 'high', 'status' => 'open', 'created_by' => $user->id]);
                $this->notify($application, 'Pemeriksaan awal selesai. Hubungi CS untuk langkah berikutnya.');
            }
            app(AccessPolicy::class)->audit($user, $complete ? 'health.session.completed' : 'health.session.draft', ['session_id' => $session->id, 'outcome' => $outcome]);
        });
    }

    public function notify(Application $application, string $message): void
    {
        if (DB::table('portal_accounts')->where('candidate_id', $application->candidate_id)->where('status', 'active')->where('in_app_notifications', true)->exists()) {
            DB::table('portal_notifications')->insert(['candidate_id' => $application->candidate_id, 'category' => 'HEALTH_SCREENING_UPDATE', 'title' => 'Pemeriksaan awal kesehatan', 'body_safe' => $message, 'deep_link' => '/portal/process', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
