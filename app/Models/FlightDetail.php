<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightDetail extends Model
{
    use HasFactory;

    public const TIMEZONE = 'Asia/Jakarta';

    protected $fillable = ['application_id', 'airline', 'flight_number', 'departure_airport', 'arrival_airport', 'departure_at', 'arrival_at'];

    protected function casts(): array
    {
        return ['departure_at' => 'datetime', 'arrival_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }
}
