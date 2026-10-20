<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Vacancy extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['job_order_id', 'code', 'title', 'slug', 'position_name', 'quota', 'salary', 'currency', 'contract_duration', 'description', 'status'];

    public function publicAsset(): BelongsTo
    {
        return $this->belongsTo(SiteAsset::class, 'site_asset_id');
    }

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    protected function casts(): array
    {
        return ['salary' => 'decimal:2', 'published_at' => 'datetime', 'publish_at' => 'datetime', 'valid_through' => 'datetime', 'featured' => 'boolean', 'public_enabled' => 'boolean', 'hiring_organization_approved' => 'boolean', 'salary_max' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::creating(function (Vacancy $vacancy): void {
            $vacancy->slug ??= Str::slug($vacancy->title).'-'.strtolower((string) Str::ulid());
        });
        static::saving(function (Vacancy $vacancy): void {
            if ($vacancy->status === 'published' && ! $vacancy->published_at) {
                $vacancy->published_at = now();
            }
        });
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(VacancyRequirement::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public static function statusOptions(): array
    {
        return static::query()->distinct()->orderBy('status')->pluck('status')->prepend('archived')->prepend('paused')->prepend('review')->prepend('closed')->prepend('published')->prepend('draft')->unique()->values()->all();
    }

    public function currentRequirements(): HasMany
    {
        return $this->requirements()->where('version', function ($query): void {
            $query->selectRaw('MAX(latest_requirement.version)')->from('vacancy_requirements as latest_requirement')->whereColumn('latest_requirement.vacancy_id', 'vacancy_requirements.vacancy_id')->whereColumn('latest_requirement.requirement_definition_id', 'vacancy_requirements.requirement_definition_id');
        });
    }

    public function documentRequirements(): HasMany
    {
        return $this->hasMany(VacancyDocumentRequirement::class);
    }

    public function interviewQuestions(): HasMany
    {
        return $this->hasMany(InterviewQuestion::class);
    }
}
