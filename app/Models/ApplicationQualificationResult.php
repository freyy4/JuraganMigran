<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationQualificationResult extends Model
{
    use HasFactory;

    public const RESULTS = ['not_checked' => 'Belum Dicek', 'passed' => 'Sesuai', 'review' => 'Perlu Tinjauan', 'rejected' => 'Tidak Dilanjutkan'];

    protected $fillable = ['vacancy_requirement_id', 'value', 'result', 'reason', 'checked_by', 'checked_at'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(VacancyRequirement::class, 'vacancy_requirement_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
