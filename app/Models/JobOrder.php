<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class JobOrder extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'company_id', 'country_id', 'reference_number', 'quota', 'valid_from', 'valid_until', 'status'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function vacancies(): HasMany
    {
        return $this->hasMany(Vacancy::class);
    }

    protected static function booted(): void
    {
        static::creating(function (JobOrder $order): void {
            $order->code ??= 'JO-'.(string) Str::ulid();
        });
    }

    public static function statusOptions(): array
    {
        return static::query()->distinct()->orderBy('status')->pluck('status')->prepend('cancelled')->prepend('closed')->prepend('approved')->prepend('draft')->unique()->values()->all();
    }
}
