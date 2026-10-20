<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalEvent extends Model
{
    use HasFactory;

    public const STATUSES = ['scheduled' => 'Dijadwalkan', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'];

    public const CLEARANCES = ['fit' => 'Fit', 'unfit' => 'Unfit', 'pending' => 'Menunggu Hasil'];

    protected $fillable = ['application_id', 'clinic_id', 'scheduled_at', 'completed_at', 'status', 'clearance_status', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'date'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'clinic_id')->withTrashed();
    }
}
