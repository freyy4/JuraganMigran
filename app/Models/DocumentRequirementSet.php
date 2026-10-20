<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentRequirementSet extends Model
{
    protected $fillable = ['name', 'scope_type', 'scope_id', 'status', 'draft_schema', 'created_by'];

    protected function casts(): array
    {
        return ['draft_schema' => 'array'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentRequirementVersion::class, 'document_requirement_set_id');
    }
}
