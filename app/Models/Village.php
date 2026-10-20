<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Village extends Model
{
    use HasFactory;

    protected $fillable = ['district_id', 'code', 'name', 'postal_code'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id');
    }
}
