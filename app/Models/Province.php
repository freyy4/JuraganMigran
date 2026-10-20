<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    use HasFactory;

    protected $fillable = ['country_id', 'code', 'name'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function regencies(): HasMany
    {
        return $this->hasMany(Regency::class, 'province_id');
    }
}
