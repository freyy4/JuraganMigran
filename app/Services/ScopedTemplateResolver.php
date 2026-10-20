<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Company;
use App\Models\Country;
use App\Models\JobOrder;
use App\Models\Vacancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScopedTemplateResolver
{
    public const SCOPES = ['GLOBAL' => 'Global', 'COUNTRY' => 'Negara', 'SPONSOR' => 'Mitra', 'VACANCY' => 'Lowongan', 'JOB_ORDER' => 'Job Order'];

    public function options(?string $selectedScope = null, ?int $selectedId = null): array
    {
        $result = [];
        foreach (['COUNTRY', 'SPONSOR', 'VACANCY', 'JOB_ORDER'] as $scope) {
            $result[$scope] = $this->references($scope, '', $scope === $selectedScope ? $selectedId : null);
        }

        return $result;
    }

    public function references(string $scope, string $search = '', ?int $selectedId = null, array $selectedIds = []): Collection
    {
        [$model, $label] = match ($scope) {
            'COUNTRY' => [Country::class, 'name'],
            'SPONSOR' => [Company::class, 'name'],
            'VACANCY' => [Vacancy::class, 'title'],
            'JOB_ORDER' => [JobOrder::class, 'code'],
            default => [null, null],
        };
        if (! $model) {
            return collect();
        }
        $rows = $model::query()
            ->when($scope === 'COUNTRY', fn ($query) => $query->where('is_active', true))
            ->when($scope === 'SPONSOR', fn ($query) => $query->where('status', 'active'))
            ->when($search !== '', fn ($query) => $query->where($label, 'like', '%'.$search.'%'))->orderBy($label)->limit(20)->get(['id', $label.' as name']);
        if ($selectedId && ! $rows->contains('id', $selectedId)) {
            $selected = $model::query()->whereKey($selectedId)->first(['id', $label.' as name']);
            if ($selected) {
                $rows->push($selected);
            }
        }

        if ($selectedIds) {
            $rows = $rows->concat($model::query()->whereIn('id', array_slice($selectedIds, 0, 100))->get(['id', $label.' as name']))->unique('id')->values();
        }

        return $rows;
    }

    public function validateScope(array $data, ?string $existingScope = null, ?int $existingId = null): void
    {
        $scope = $data['scope_type'];
        $table = ['COUNTRY' => 'countries', 'SPONSOR' => 'companies', 'VACANCY' => 'vacancies', 'JOB_ORDER' => 'job_orders'][$scope] ?? null;
        if (! $table) {
            return;
        }
        $query = DB::table($table)->where('id', $data['scope_id'] ?? 0);
        $unchanged = $scope === $existingScope && $existingId !== null && $existingId === (int) ($data['scope_id'] ?? 0);
        if (! $unchanged) {
            $query->when($scope === 'COUNTRY', fn ($query) => $query->where('is_active', true))
                ->when($scope === 'SPONSOR', fn ($query) => $query->where('status', 'active')->whereNull('deleted_at'))
                ->when($scope === 'VACANCY', fn ($query) => $query->whereNull('deleted_at'));
        }
        if (! $query->exists()) {
            throw ValidationException::withMessages(['scope_id' => 'Pilih acuan cakupan yang tersedia dan aktif. Acuan nonaktif hanya dapat dipertahankan pada konfigurasi yang sudah menggunakannya.']);
        }
    }

    public function resolve(Application $application, string $table, string $versions, string $key): ?object
    {
        $order = $application->vacancy->jobOrder;
        $targets = ['JOB_ORDER' => $order->id, 'VACANCY' => $application->vacancy_id, 'SPONSOR' => $order->company_id, 'COUNTRY' => $order->country_id, 'GLOBAL' => null];
        $today = now('Asia/Jakarta')->toDateString();
        foreach ($targets as $scope => $id) {
            $version = DB::table($versions.' as version')->join($table.' as configuration', 'configuration.id', '=', 'version.'.$key)
                ->where('configuration.scope_type', $scope)->where('configuration.scope_id', $id)->where('configuration.status', '!=', 'archived')
                ->where(fn ($q) => $q->whereNull('version.effective_from')->orWhere('version.effective_from', '<=', $today))
                ->where(fn ($q) => $q->whereNull('version.effective_until')->orWhere('version.effective_until', '>=', $today))
                ->orderByDesc('version.published_at')->orderByDesc('version.id')->select('version.*', 'configuration.name')->first();
            if ($version) {
                return $version;
            }
        }

        return null;
    }

    public function applies(?array $rule, Application $application): bool
    {
        return app(VisualConditionCompiler::class)->matches($rule, $application);
    }
}
