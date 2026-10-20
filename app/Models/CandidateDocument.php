<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CandidateDocument extends Model
{
    use SoftDeletes;

    public const STATUSES = ['uploaded' => 'Diunggah', 'reviewed' => 'Ditinjau', 'verified' => 'Terverifikasi', 'rejected' => 'Ditolak', 'expired' => 'Kedaluwarsa', 'needs_correction' => 'Perlu diperbaiki', 'superseded' => 'Sudah diganti dokumen terbaru'];

    protected $fillable = ['candidate_id', 'application_id', 'document_type_id', 'file_path', 'document_number', 'issued_at', 'expires_at', 'version', 'status', 'uploaded_by', 'verified_by', 'verified_at', 'rejection_reason', 'correction_code', 'action_owner', 'action_assigned_to', 'action_due_at'];

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'expires_at' => 'date', 'verified_at' => 'datetime', 'action_due_at' => 'datetime'];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class)->withTrashed();
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function effectiveStatus(): string
    {
        if ($this->status === 'superseded') {
            return 'superseded';
        }

        return $this->expires_at && $this->expires_at->format('Y-m-d') < now('Asia/Jakarta')->toDateString() ? 'expired' : $this->status;
    }

    public function scopeLatestVersions(Builder $query): Builder
    {
        return $query->whereNotExists(function ($newer): void {
            $newer->selectRaw('1')->from('candidate_documents as newer_document')->whereNull('newer_document.deleted_at')->whereColumn('newer_document.candidate_id', 'candidate_documents.candidate_id')->whereColumn('newer_document.document_type_id', 'candidate_documents.document_type_id')->where(function ($scope): void {
                $scope->whereColumn('newer_document.application_id', 'candidate_documents.application_id')->orWhere(fn ($null) => $null->whereNull('newer_document.application_id')->whereNull('candidate_documents.application_id'));
            })->where(function ($version): void {
                $version->whereColumn('newer_document.version', '>', 'candidate_documents.version')->orWhere(fn ($tie) => $tie->whereColumn('newer_document.version', 'candidate_documents.version')->whereColumn('newer_document.id', '>', 'candidate_documents.id'));
            });
        });
    }
}
