<?php

namespace App\Services;

use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PublicVacancies
{
    public function query(bool $active = true): Builder
    {
        $query = Vacancy::with(['publicAsset', 'jobOrder.country', 'currentRequirements.definition'])->where('public_enabled', true)->whereNotNull('published_at')->whereNotNull('site_asset_id')->whereHas('jobOrder.country', fn ($q) => $q->where('is_active', true));
        $query->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()));
        if ($active) {
            $query->where('status', 'published')->where(fn ($q) => $q->whereNull('valid_through')->orWhere('valid_through', '>', now()));
            $query->whereExists(function ($q): void {
                $q->selectRaw('1')->from('application_funnels as f')->join('funnel_versions as v', 'v.id', '=', 'f.active_version_id')->whereColumn('f.id', 'vacancies.funnel_id')->where('f.status', 'published')->whereColumn('v.funnel_id', 'f.id')->whereColumn('v.vacancy_id', 'vacancies.id');
            });
        } else {
            $query->whereIn('status', ['published', 'paused', 'closed']);
        }

        return $query;
    }

    public function applyPath(Vacancy $vacancy): ?string
    {
        if ($vacancy->status !== 'published' || ($vacancy->valid_through && $vacancy->valid_through->isPast()) || ($vacancy->publish_at && $vacancy->publish_at->isFuture())) {
            return null;
        }
        $slug = DB::table('application_funnels as f')->join('funnel_versions as v', 'v.id', '=', 'f.active_version_id')->where('f.id', $vacancy->funnel_id)->where('f.status', 'published')->whereColumn('v.funnel_id', 'f.id')->where('v.vacancy_id', $vacancy->id)->value('f.slug');

        return $slug ? route('apply.show', $slug) : null;
    }

    public function display(Vacancy $vacancy): array
    {
        return ['slug' => $vacancy->slug, 'title' => $vacancy->title, 'country' => ['code' => $vacancy->jobOrder?->country?->code, 'name' => $vacancy->jobOrder?->country?->name], 'sector' => $vacancy->sector, 'employment_class' => $vacancy->employment_class, 'minimum_education' => $vacancy->minimum_education, 'salary' => ['min' => $vacancy->salary, 'max' => $vacancy->salary_max, 'currency' => $vacancy->currency, 'period' => $vacancy->salary_period], 'description' => strip_tags($vacancy->description ?? ''), 'responsibilities' => $vacancy->responsibilities, 'notes' => $vacancy->public_notes, 'hiring_organization' => $vacancy->hiring_organization_approved ? $vacancy->hiring_organization : null, 'updated_at' => $vacancy->updated_at?->toIso8601String(), 'contract_duration' => $vacancy->contract_duration, 'image_alt' => $vacancy->publicAsset?->alt ?? $vacancy->title, 'image_position' => [(float) ($vacancy->publicAsset?->variants['_focal']['x'] ?? 50), (float) ($vacancy->publicAsset?->variants['_focal']['y'] ?? 50)], 'image' => route('site.media', $vacancy->site_asset_id), 'featured' => $vacancy->featured, 'published_at' => $vacancy->published_at?->toIso8601String(), 'valid_through' => $vacancy->valid_through?->toIso8601String(), 'application_path' => $this->applyPath($vacancy), 'status' => $this->applyPath($vacancy) ? 'Terbuka' : ($vacancy->status === 'paused' ? 'Pendaftaran dijeda' : 'Lowongan telah ditutup'), 'requirements' => $vacancy->currentRequirements->map(fn ($r) => ['name' => $r->definition?->name, 'operator' => $r->definition?->operators()[$r->operator] ?? $r->operator, 'value' => $r->operator === 'between' ? $r->value_min.' – '.$r->value_max : $r->value, 'unit' => $r->definition?->unit, 'required' => $r->is_required])->all()];
    }
}
