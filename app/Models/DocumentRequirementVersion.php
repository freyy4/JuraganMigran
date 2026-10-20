<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequirementVersion extends Model
{
    public $timestamps = false;

    protected $fillable = ['document_requirement_set_id', 'version', 'snapshot', 'effective_from', 'effective_until', 'published_by', 'published_at', 'policy_approved_by', 'policy_reference'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'published_at' => 'datetime'];
    }

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(DocumentRequirementSet::class, 'document_requirement_set_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => abort(403, 'Versi terbit tidak dapat diubah.'));
        static::deleting(fn () => abort(403, 'Versi terbit tidak dapat dihapus.'));
    }
}
