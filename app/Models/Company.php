<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPES = ['sponsor' => 'Sponsor', 'employer' => 'Employer', 'placement_partner' => 'Mitra Penempatan', 'lpk' => 'LPK', 'clinic' => 'Klinik', 'agency' => 'Agency', 'other' => 'Lainnya'];

    protected $fillable = ['code', 'name', 'type', 'country_id', 'address', 'phone', 'email', 'status'];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CompanyContact::class);
    }

    public function primaryContact(): HasOne
    {
        return $this->hasOne(CompanyContact::class)->ofMany(['is_primary' => 'max', 'id' => 'min']);
    }

    public function jobOrders(): HasMany
    {
        return $this->hasMany(JobOrder::class);
    }

    public function vacancies(): HasManyThrough
    {
        return $this->hasManyThrough(Vacancy::class, JobOrder::class);
    }

    public static function typeLabels(): array
    {
        return Cache::remember('master.company-type-labels', 300, fn () => array_replace(self::TYPES, DB::table('master_reference_entries')->where('resource', 'jenis-perusahaan')->pluck('name', 'code')->all()));
    }

    public static function statusOptions(): array
    {
        return static::query()->distinct()->orderBy('status')->pluck('status')->prepend('draft')->prepend('inactive')->prepend('active')->unique()->values()->all();
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Aktif','inactive' => 'Tidak aktif','draft' => 'Draf','published' => 'Dipublikasikan','closed' => 'Ditutup','approved' => 'Disetujui','pending' => 'Menunggu','cancelled' => 'Dibatalkan',default => ucfirst(str_replace('_', ' ', $status))
        };
    }

    public function trainingBatches(): HasMany
    {
        return $this->hasMany(TrainingBatch::class);
    }

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'company_countries')->orderBy('name');
    }

    public function selectedCountryIds(): array
    {
        return $this->countries->isNotEmpty() ? $this->countries->modelKeys() : ($this->country_id ? [$this->country_id] : []);
    }

    public function countryNames(): string
    {
        return $this->countries->isNotEmpty() ? $this->countries->pluck('name')->implode(', ') : ($this->country?->name ?? '');
    }
}
