<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadinessItem extends Model
{
    use HasFactory;

    protected $fillable = ['readiness_checklist_id', 'code', 'name', 'is_required', 'status', 'verified_by', 'verified_at'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'verified_at' => 'datetime'];
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(ReadinessChecklist::class, 'readiness_checklist_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
