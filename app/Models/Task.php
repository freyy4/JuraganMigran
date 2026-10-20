<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use HasFactory;

    public const TIMEZONE = 'Asia/Jakarta';

    public const TABS = ['today' => 'Hari Ini', 'overdue' => 'Terlambat', 'upcoming' => 'Akan Datang', 'completed' => 'Selesai'];

    public const COMPLETED_STATUSES = ['completed', 'done'];

    protected $fillable = ['application_id', 'assigned_to', 'task_type', 'title', 'description', 'priority', 'due_at', 'status', 'completed_at', 'created_by'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, self::COMPLETED_STATUSES, true);
    }

    public static function priorities(): array
    {
        return static::distinct()->orderBy('priority')->pluck('priority')->prepend('urgent')->prepend('high')->prepend('normal')->prepend('low')->unique()->values()->all();
    }

    public static function statuses(): array
    {
        return static::distinct()->orderBy('status')->pluck('status')->prepend('completed')->prepend('in_progress')->prepend('open')->unique()->values()->all();
    }

    public static function label(string $value): string
    {
        return match ($value) {
            'follow_up' => 'Tindak Lanjut', 'low' => 'Rendah','normal' => 'Normal','high' => 'Tinggi','urgent' => 'Mendesak','open' => 'Terbuka','in_progress' => 'Dikerjakan','completed','done' => 'Selesai',default => ucfirst(str_replace('_', ' ', $value))
        };
    }

    public function scopeForTab(Builder $query, string $tab): Builder
    {
        $now = now();
        $tomorrow = now(self::TIMEZONE)->startOfDay()->addDay()->setTimezone(config('app.timezone'));
        if ($tab === 'completed') {
            return $query->whereIn('status', self::COMPLETED_STATUSES);
        }
        $query->whereNotIn('status', self::COMPLETED_STATUSES);

        return match ($tab) {
            'overdue' => $query->where('due_at', '<', $now),
            'upcoming' => $query->where(fn ($query) => $query->where('due_at', '>=', $tomorrow)->orWhereNull('due_at')),
            default => $query->where('due_at', '>=', $now)->where('due_at', '<', $tomorrow)
        };
    }
}
