<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class HealthInterview extends Model
{
    use HasFactory;

    protected $fillable = ['application_id', 'status', 'completeness_percentage', 'completed_by', 'completed_at', 'source', 'health_template_version_id', 'outcome', 'clarification_count'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'completeness_percentage' => 'integer'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(HealthTemplateVersion::class, 'health_template_version_id');
    }

    public static function questionsFor(Application $application): Builder
    {
        return DB::table('health_questions')->where('answer_type', 'LEGACY_TEXT')->where('is_active', true)->where(fn (Builder $query) => $query->whereNull('vacancy_id')->orWhere('vacancy_id', $application->vacancy_id))->orderBy('sequence')->orderBy('id');
    }
}
