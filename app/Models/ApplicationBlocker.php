<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApplicationBlocker extends Model
{
    use HasFactory;

    public const TIMEZONE = 'Asia/Jakarta';

    public const RESOLVED = ['resolved', 'completed', 'done'];

    protected $fillable = ['application_id', 'stage_id', 'category', 'description', 'priority', 'owner_id', 'due_at', 'next_action', 'status', 'resolved_at', 'resolved_by'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (ApplicationBlocker $blocker): void {
            $blocker->code ??= 'KDL-'.(string) Str::ulid();
        });
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return in_array($this->status, self::RESOLVED, true);
    }

    public function isOverdue(): bool
    {
        return ! $this->isResolved() && $this->due_at && $this->due_at->lt(now());
    }

    public static function categories(): array
    {
        return static::distinct()->orderBy('category')->pluck('category')->all();
    }

    public static function priorities(): array
    {
        return static::distinct()->orderBy('priority')->pluck('priority')->prepend('urgent')->prepend('high')->prepend('normal')->prepend('low')->unique()->values()->all();
    }

    public static function statuses(): array
    {
        return static::distinct()->orderBy('status')->pluck('status')->prepend('resolved')->prepend('in_progress')->prepend('open')->unique()->values()->all();
    }

    public static function label(string $value): string
    {
        return match ($value) {
            'low' => 'Rendah','normal' => 'Normal','high' => 'Tinggi','urgent' => 'Mendesak','open' => 'Terbuka','in_progress' => 'Dikerjakan','resolved','completed','done' => 'Selesai',default => ucfirst(str_replace('_', ' ', $value))
        };
    }
}
