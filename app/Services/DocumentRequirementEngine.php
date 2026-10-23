<?php

namespace App\Services;

use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\DocumentType;
use App\Models\PipelineStage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentRequirementEngine
{
    public const OWNERS = ['CPMI_ACTION' => 'CPMI', 'INTERNAL_REVIEW' => 'Tim Verifikasi', 'EXTERNAL_DEPENDENCY' => 'Pihak Eksternal', 'SUPERVISOR_REVIEW' => 'Supervisor'];

    public const REASONS = ['PHOTO_BLURRY' => 'Foto buram', 'DOCUMENT_CROPPED' => 'Dokumen terpotong', 'QR_NOT_READABLE' => 'QR tidak terbaca', 'EXPIRED' => 'Dokumen kedaluwarsa', 'WRONG_DOCUMENT' => 'Jenis dokumen tidak sesuai', 'DATA_MISMATCH' => 'Data tidak cocok', 'MISSING_PAGE' => 'Halaman belum lengkap', 'UNREADABLE_TEXT' => 'Tulisan tidak terbaca', 'OTHER' => 'Lainnya'];

    public const LABELS = ['not_required_yet' => 'Belum diperlukan', 'missing' => 'Belum diunggah', 'uploaded' => 'Diunggah', 'reviewed' => 'Ditinjau', 'needs_correction' => 'Perlu diperbaiki', 'rejected' => 'Dokumen tidak sesuai', 'verified' => 'Terverifikasi', 'expiring_soon' => 'Akan kedaluwarsa', 'expired' => 'Perlu diperbarui', 'superseded' => 'Sudah diganti dokumen terbaru'];

    public function requirements(Application $application): Collection
    {
        $version = app(ScopedTemplateResolver::class)->resolve($application, 'document_requirement_sets', 'document_requirement_versions', 'document_requirement_set_id');
        if ($version) {
            $requirements = DB::table('document_requirement_rules')->where('document_requirement_version_id', $version->id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->map(function ($row): object {
                $row->source_key = 'rule-'.$row->id;
                $row->condition_rule = $row->condition_rule ? json_decode($row->condition_rule, true) : null;
                $row->is_required = $row->required_level !== 'OPTIONAL';
                $row->notes = null;
                $row->documentType = DocumentType::find($row->document_type_id);

                return $row;
            });
        } else {
            $requirements = $application->vacancy->documentRequirements()->with('documentType')->where('is_active', true)->orderBy('sort_order')->get()->map(function ($row): object {
                $row->source_key = (string) $row->id;
                $row->required_level = $row->condition_rule ? 'CONDITIONAL' : ($row->is_required ? 'REQUIRED' : 'OPTIONAL');
                $row->expiry_policy = 'VALID';

                return $row;
            });
        }

        if (config('care.enabled') && Schema::hasColumn('care_journey_templates', 'policy')) {
            $policy = json_decode(app(CareJourney::class)->template($application)->policy ?? '{}', true);
            $ids = collect();
            if ($policy['consent_required'] ?? false) {
                $ids->push($policy['consent_document_type_id'] ?? null);
            }
            $status = $application->candidate->person?->marital_status;
            $ids->push($policy['marital_documents'][$status ?? ''] ?? null);
            foreach ($ids->filter()->unique() as $typeId) {
                if ($requirements->contains('document_type_id', (int) $typeId)) {
                    continue;
                }
                $type = DocumentType::find($typeId);
                if ($type) {
                    $requirements->push((object) ['id' => 'care-'.$typeId, 'source_key' => 'care-'.$typeId, 'document_type_id' => $typeId, 'documentType' => $type, 'stage_id' => null, 'condition_rule' => null, 'is_required' => true, 'required_level' => 'REQUIRED', 'expiry_policy' => 'NONE', 'minimum_validity_days' => 0, 'original_required' => false, 'must_verify_before_exit' => true, 'notes' => 'Persyaratan izin / status keluarga dari template negara.']);
                }
            }
        }

        return $requirements;
    }

    public function documents(Application $application): Collection
    {
        return CandidateDocument::where('candidate_id', $application->candidate_id)->where(fn ($q) => $q->where('application_id', $application->id)->orWhereNull('application_id'))->with(['documentType', 'verifier'])->orderByRaw('application_id IS NULL')->orderByDesc('version')->orderByDesc('id')->get()->unique('document_type_id')->keyBy('document_type_id');
    }

    public function applicable(object $rule, Application $application): bool
    {
        $stage = $rule->stage_id ? PipelineStage::find($rule->stage_id) : null;
        $current = $application->currentStage;
        if ($rule->stage_id && (! $stage || ! $current || (app(ProcessWorkflow::class)->stage($application, $stage->id)['sequence'] ?? $stage->sequence) > (app(ProcessWorkflow::class)->stage($application, $current->id)['sequence'] ?? $current->sequence))) {
            return false;
        }

        return app(ScopedTemplateResolver::class)->applies($rule->condition_rule, $application);
    }

    public function rows(Application $application): Collection
    {
        $documents = $this->documents($application);
        $rules = $this->requirements($application)->groupBy('document_type_id')->map(function ($group) use ($application) {
            $applicable = $group->filter(fn ($rule) => $this->applicable($rule, $application));

            return $applicable->isNotEmpty() ? $applicable->sortByDesc(fn ($rule) => $rule->stage_id ? (PipelineStage::find($rule->stage_id)?->sequence ?? 0) : -1)->first() : $group->first();
        })->values();

        return $rules->map(function ($rule) use ($application, $documents): array {
            $document = $documents->get($rule->document_type_id);
            $applies = $this->applicable($rule, $application);
            $status = $applies ? ($document?->effectiveStatus() ?? 'missing') : 'not_required_yet';
            $today = now('Asia/Jakarta')->startOfDay();
            $minimum = (int) $rule->minimum_validity_days;
            $minimumInvalid = $applies && $rule->expiry_policy !== 'NONE' && ($minimum > 0 || (str_starts_with($rule->source_key, 'rule-') && $rule->documentType?->has_expiry)) && (! $document?->expires_at || $document->expires_at->lt($today->copy()->addDays($minimum)));
            if ($minimumInvalid && $document && ! in_array($status, ['needs_correction', 'rejected', 'expired'], true)) {
                $status = 'expiring_soon';
            } elseif ($status === 'verified' && $document?->expires_at && $document->expires_at->lte($today->copy()->addDays(30))) {
                $status = 'expiring_soon';
            }
            $valid = $document && $document->file_path && Storage::disk('documents')->exists($document->file_path) && ! in_array($status, ['needs_correction', 'rejected', 'expired'], true) && ! $minimumInvalid;
            $verified = $valid && $document->status === 'verified';
            if ($verified && config('care.enabled') && Schema::hasTable('care_document_checks')) {
                $policy = json_decode(app(CareJourney::class)->template($application)->policy ?? '{}', true);
                if (in_array((int) $rule->document_type_id, $policy['teto_document_type_ids'] ?? [], true)) {
                    $check = DB::table('care_document_checks')->where('candidate_document_id', $document->id)->first();
                    $checks = $check ? json_decode($check->checks, true) : [];
                    $verified = count(array_filter($checks)) === 5;
                }
            }
            $owner = match ($status) {
                'missing', 'needs_correction', 'rejected', 'expired' => 'CPMI_ACTION', default => $document?->action_owner ?? 'INTERNAL_REVIEW'
            };
            if ($document && $document->action_owner === 'EXTERNAL_DEPENDENCY') {
                $owner = 'EXTERNAL_DEPENDENCY';
            }
            $action = DB::table('document_actions')->where('application_id', $application->id)->where('document_type_id', $rule->document_type_id)->first();
            if ($action) {
                $owner = $action->owner;
            }
            $due = $action?->due_at ? Carbon::parse($action->due_at) : $document?->action_due_at;
            $overdue = $due && $due->isPast() && $owner === 'INTERNAL_REVIEW' && ! $verified;
            $reason = match ($status) {
                'missing' => 'Dokumen wajib belum diunggah.', 'not_required_yet' => 'Belum diperlukan pada tahap ini.', 'expiring_soon' => $minimumInvalid ? 'Validitas minimum '.$minimum.' hari belum terpenuhi.' : 'Akan kedaluwarsa dalam 30 hari.', 'needs_correction', 'rejected' => $document?->rejection_reason, 'expired' => 'Masa berlaku telah habis.', default => $verified ? 'Dokumen telah diverifikasi.' : 'Menunggu pemeriksaan petugas.'
            };

            return compact('rule', 'document', 'applies', 'status', 'valid', 'verified', 'owner', 'due', 'overdue', 'reason', 'minimumInvalid');
        });
    }

    public function summary(Application $application): array
    {
        $required = $this->rows($application)->filter(fn ($row) => $row['applies'] && $row['rule']->is_required);
        $verified = $required->where('verified', true)->count();

        return ['total' => $required->count(), 'verified' => $verified, 'percentage' => $required->isEmpty() ? 0 : (int) round($verified * 100 / $required->count()), 'correction' => $required->where('status', 'needs_correction')->count(), 'missing' => $required->where('status', 'missing')->count(), 'stage' => $application->currentStage?->name ?? 'Belum ditetapkan'];
    }

    public function isReady(Application $application): bool
    {
        return $this->rows($application)->filter(fn ($row) => $row['applies'] && $row['rule']->is_required)->every(fn ($row) => ($row['verified'] && $this->originalHeld($application, $row['rule'])) || $this->hasException($application, $row['rule']));
    }

    private function hasException(Application $application, object $rule): bool
    {
        return DB::table('document_exceptions')->where('application_id', $application->id)->where('document_type_id', $rule->document_type_id)->where('stage_id', $application->current_stage_id)->where('status', 'approved')->whereNotNull('approved_by')->where('valid_until', '>', now())->exists();
    }

    private function originalHeld(Application $application, object $rule): bool
    {
        return ! $rule->original_required || DB::table('original_document_custodies as custody')->join('candidate_documents as document', 'document.id', '=', 'custody.candidate_document_id')->where('document.candidate_id', $application->candidate_id)->where('document.document_type_id', $rule->document_type_id)->where(fn ($q) => $q->where('custody.application_id', $application->id)->orWhereNull('custody.application_id'))->whereIn('custody.custody_state', ['RECEIVED_BY_JM', 'HELD_BY_JM', 'HANDED_TO_PT', 'HELD_BY_PT'])->exists();
    }

    public function assertGate(Application $application): void
    {
        $failures = $this->rows($application)->filter(function ($row) use ($application): bool {
            if (! $row['applies'] || ! $row['rule']->must_verify_before_exit || ! $row['rule']->is_required) {
                return false;
            }
            if ($this->hasException($application, $row['rule'])) {
                return false;
            }
            $original = $this->originalHeld($application, $row['rule']);

            return ! $row['verified'] || ! $original;
        });
        if ($failures->isNotEmpty()) {
            throw ValidationException::withMessages(['to_stage_id' => 'Dokumen pengaman tahap belum terpenuhi: '.$failures->map(fn ($row) => $row['rule']->documentType?->name)->unique()->implode(', ').'. Verifikasi/lengkapi dokumen atau minta pengecualian yang disetujui.']);
        }
    }
}
