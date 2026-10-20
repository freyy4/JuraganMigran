<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitePageVersion extends Model
{
    protected $fillable = ['version', 'schema', 'created_by', 'reviewed_by', 'reviewed_at', 'published_at'];

    protected function casts(): array
    {
        return ['schema' => 'array', 'reviewed_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(SitePage::class, 'site_page_id');
    }
}
