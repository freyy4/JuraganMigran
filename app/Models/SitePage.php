<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SitePage extends Model
{
    protected $fillable = ['slug', 'title', 'type', 'status', 'system', 'in_navigation', 'sequence', 'owner_id', 'active_version_id', 'draft_schema'];

    protected function casts(): array
    {
        return ['draft_schema' => 'array', 'system' => 'boolean', 'in_navigation' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SitePageVersion::class);
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(SitePageVersion::class, 'active_version_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
