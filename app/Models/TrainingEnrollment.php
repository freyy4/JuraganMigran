<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingEnrollment extends Model
{
    use HasFactory;

    public const TIMEZONE = 'Asia/Jakarta';

    public const STATUSES = ['scheduled' => 'Terjadwal', 'ongoing' => 'Berlangsung', 'on_hold' => 'Ditunda', 'completed' => 'Selesai', 'withdrawn' => 'Mengundurkan Diri'];

    protected $fillable = ['application_id', 'batch_id', 'joined_at', 'completed_at', 'status'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TrainingBatch::class, 'batch_id');
    }
}
