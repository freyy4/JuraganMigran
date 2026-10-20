<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateContact extends Model
{
    protected $fillable = ['name', 'relationship', 'phone', 'whatsapp', 'address', 'contact_type', 'is_emergency', 'is_primary'];

    protected function casts(): array
    {
        return ['is_emergency' => 'boolean', 'is_primary' => 'boolean'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }
}
