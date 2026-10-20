<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VacancyDocumentRequirement extends Model
{
    protected $fillable = ['document_type_id', 'requirement_type', 'is_required', 'notes', 'stage_id', 'must_verify_before_exit', 'original_required', 'minimum_validity_days', 'condition_rule', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'condition_rule' => 'array'];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class)->withTrashed();
    }
}
