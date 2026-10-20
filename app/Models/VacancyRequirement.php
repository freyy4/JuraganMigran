<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VacancyRequirement extends Model
{
    protected $fillable = ['requirement_definition_id', 'operator', 'value', 'value_min', 'value_max', 'is_required', 'source_reference', 'version'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'value_min' => 'decimal:4', 'value_max' => 'decimal:4'];
    }

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(RequirementDefinition::class, 'requirement_definition_id');
    }
}
