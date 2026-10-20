<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['lpk_ids' => 'array', 'country_ids' => 'array', 'start_date' => 'date', 'end_date' => 'date', 'return_deadline' => 'datetime'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(HolidayPolicy::class);
    }
}
