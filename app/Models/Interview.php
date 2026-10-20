<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Interview extends Model
{
    use HasFactory;

    public const OUTCOMES = ['continue' => 'Lanjut', 'hold' => 'Tahan', 'reinterview' => 'Wawancara Ulang', 'rejected' => 'Tidak Dilanjutkan'];

    public const TIMEZONE = 'Asia/Jakarta';

    protected $fillable = ['application_id', 'type', 'scheduled_at', 'interviewer_id', 'meeting_link', 'status', 'outcome', 'notes'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(InterviewAnswer::class);
    }

    public function questions(): Builder
    {
        return InterviewQuestion::where(fn ($q) => $q->whereNull('vacancy_id')->orWhere('vacancy_id', $this->application->vacancy_id))->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $this->answers()->select('question_id')))->orderBy('sequence')->orderBy('id');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'scheduled' => 'Dijadwalkan','in_progress' => 'Berlangsung','completed' => 'Selesai','cancelled' => 'Dibatalkan',default => ucfirst($status)
        };
    }
}
