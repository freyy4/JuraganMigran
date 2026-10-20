<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingBatch extends Model
{
    use HasFactory;

    public const STATUSES = ['planned' => 'Direncanakan', 'active' => 'Berlangsung', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'];

    protected $fillable = ['company_id', 'code', 'name', 'start_date', 'end_date', 'capacity', 'status'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'capacity' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(TrainingEnrollment::class, 'batch_id');
    }
}
