<?php

namespace App\Models;

use App\Services\DocumentRequirementEngine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Application extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['application_code', 'candidate_id', 'vacancy_id', 'status', 'applied_at', 'assigned_cs_id', 'current_stage_id', 'process_priority', 'next_action', 'next_action_due_at', 'waiting_status'];

    public function vacancy(): BelongsTo
    {
        return $this->belongsTo(Vacancy::class)->withTrashed();
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class)->withTrashed();
    }

    protected function casts(): array
    {
        return ['applied_at' => 'datetime', 'closed_at' => 'datetime', 'next_action_due_at' => 'datetime'];
    }

    public function assignedCs(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_cs_id');
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'current_stage_id');
    }

    public static function statusOptions(): array
    {
        return static::query()->distinct()->orderBy('status')->pluck('status')->prepend('ready_for_departure')->prepend('cancelled')->prepend('closed')->prepend('active')->unique()->values()->all();
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'ready_for_departure' => 'Siap Berangkat', 'ready' => 'Siap Berangkat', 'departure_ready' => 'Siap Berangkat', 'active' => 'Aktif','closed' => 'Ditutup','cancelled' => 'Dibatalkan','rejected' => 'Ditolak','accepted' => 'Diterima','withdrawn' => 'Ditarik','pending' => 'Menunggu',default => ucfirst(str_replace('_', ' ', $status))
        };
    }

    public function stageHistories(): HasMany
    {
        return $this->hasMany(ApplicationStageHistory::class);
    }

    public function latestStageHistory(): HasOne
    {
        return $this->hasOne(ApplicationStageHistory::class)->latestOfMany();
    }

    public function stageDuration(): string
    {
        if (! $this->current_stage_id) {
            return 'Belum ada tahap';
        }
        $visit = $this->latestStageHistory;
        if (! $visit || $visit->to_stage_id !== $this->current_stage_id || ! $visit->entered_at) {
            return 'Belum tercatat';
        }
        $minutes = max(0, (int) $visit->entered_at->diffInMinutes(now()));
        if ($minutes < 60) {
            return $minutes.' menit';
        }
        if ($minutes < 1440) {
            return intdiv($minutes, 60).' jam';
        }

        return intdiv($minutes, 1440).' hari';
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function blockers(): HasMany
    {
        return $this->hasMany(ApplicationBlocker::class);
    }

    public function qualificationResults(): HasMany
    {
        return $this->hasMany(ApplicationQualificationResult::class);
    }

    public function latestHealthInterview(): HasOne
    {
        return $this->hasOne(HealthInterview::class)->latestOfMany();
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class);
    }

    public function flights(): HasMany
    {
        return $this->hasMany(FlightDetail::class);
    }

    public function readinessChecklists(): HasMany
    {
        return $this->hasMany(ReadinessChecklist::class);
    }

    public function isReadyForDeparture(): bool
    {
        $checklists = $this->readinessChecklists()->where('type', 'departure')->with('items')->get();

        return $checklists->isNotEmpty() && $checklists->every(fn ($checklist) => $checklist->isReady()) && app(DocumentRequirementEngine::class)->isReady($this);
    }

    protected static function booted(): void
    {
        static::saving(function (Application $application): void {
            if ($application->isDirty('current_stage_id')) {
                $application->unsetRelation('currentStage');
            }
            if (in_array($application->status, ['ready', 'ready_for_departure', 'departure_ready'], true) && ! $application->isReadyForDeparture()) {
                throw ValidationException::withMessages(['status' => 'Lamaran belum siap berangkat. Selesaikan seluruh item wajib pada checklist keberangkatan dan verifikasi dokumen wajib tahap ini.']);
            }
        });
    }

    public function medicalEvents(): HasMany
    {
        return $this->hasMany(MedicalEvent::class);
    }

    public function trainingEnrollments(): HasMany
    {
        return $this->hasMany(TrainingEnrollment::class);
    }
}
