<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveVacancyRequest;
use App\Models\Country;
use App\Models\JobOrder;
use App\Models\RequirementDefinition;
use App\Models\Vacancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class VacancyController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'string', 'max:255']], ['string' => ':attribute harus berupa teks.', 'max' => ':attribute maksimal :max karakter.'], ['search' => 'Pencarian', 'status' => 'Status']);
        $query = Vacancy::with(['jobOrder.company', 'jobOrder.country']);
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(function ($query) use ($search): void {
                $query->where('code', 'like', '%'.$search.'%')->orWhere('title', 'like', '%'.$search.'%')->orWhere('position_name', 'like', '%'.$search.'%')->orWhereHas('jobOrder.company', fn ($company) => $company->where('name', 'like', '%'.$search.'%'));
            });
        }
        if ($filters['status'] ?? null) {
            $query->where('status', $filters['status']);
        }

        return view('vacancies.index', ['vacancies' => $query->orderByDesc('id')->paginate(15)->withQueryString(), 'statuses' => Vacancy::statusOptions()]);
    }

    private function form(Vacancy $vacancy): View
    {
        $requirements = $vacancy->exists ? $vacancy->requirements()->orderByDesc('version')->get()->unique('requirement_definition_id')->keyBy('requirement_definition_id') : collect();

        return view('vacancies.form', ['vacancy' => $vacancy, 'requirements' => $requirements, 'definitions' => RequirementDefinition::where(function ($query) use ($requirements): void {
            $query->where('is_active', true)->orWhereIn('id', $requirements->keys());
        })->orderBy('category')->orderBy('name')->get(), 'countries' => Country::availableForSelection($vacancy->jobOrder?->country_id)->orderBy('name')->get(), 'orders' => JobOrder::with(['company', 'country'])->where(function ($query) use ($vacancy): void {
            $query->whereHas('country', fn ($country) => $country->where('is_active', true));
            if ($vacancy->exists) {
                $query->orWhere('id', $vacancy->job_order_id);
            }
        })->orderByDesc('id')->get(), 'statuses' => Vacancy::statusOptions()]);
    }

    public function create(Request $request): View
    {
        $data = $request->validate(['job_order_id' => ['nullable', 'integer', Rule::exists('job_orders', 'id')]]);

        return $this->form(new Vacancy(['quota' => 0, 'status' => 'draft', 'job_order_id' => isset($data['job_order_id']) ? (int) $data['job_order_id'] : null]));
    }

    public function edit(Vacancy $vacancy): View
    {
        return $this->form($vacancy);
    }

    public function store(SaveVacancyRequest $request): RedirectResponse
    {
        $vacancy = DB::transaction(function () use ($request): Vacancy {
            $vacancy = Vacancy::create(collect($request->validated())->except('requirements')->all());
            $this->syncRequirements($vacancy, $request->validated('requirements', []));

            return $vacancy;
        });

        return to_route('vacancies.show', $vacancy)->with('success', 'Lowongan berhasil ditambahkan.');
    }

    public function update(SaveVacancyRequest $request, Vacancy $vacancy): RedirectResponse
    {
        DB::transaction(function () use ($request, $vacancy): void {
            $locked = Vacancy::whereKey($vacancy->id)->lockForUpdate()->firstOrFail();
            $locked->update(collect($request->validated())->except('requirements')->all());
            $this->syncRequirements($locked, $request->validated('requirements', []));
        });

        return to_route('vacancies.show', $vacancy)->with('success', 'Lowongan berhasil diperbarui.');
    }

    private function syncRequirements(Vacancy $vacancy, array $rows): void
    {
        $selected = collect($rows)->filter(fn ($row) => (bool) $row['selected']);
        $removed = $vacancy->requirements()->whereNotIn('requirement_definition_id', $selected->pluck('definition_id'))->pluck('id');
        if (DB::table('application_qualification_results')->whereIn('vacancy_requirement_id', $removed)->exists()) {
            throw ValidationException::withMessages(['requirements' => 'Persyaratan yang sudah digunakan dalam hasil kualifikasi tidak dapat dihapus.']);
        }
        $vacancy->requirements()->whereIn('id', $removed)->delete();
        foreach ($selected as $row) {
            $existing = $vacancy->requirements()->where('requirement_definition_id', $row['definition_id'])->orderByDesc('version')->first();
            $data = ['requirement_definition_id' => $row['definition_id'], 'operator' => $row['operator'], 'value' => $row['operator'] === 'between' ? null : (is_bool($row['value']) ? ($row['value'] ? '1' : '0') : (string) $row['value']), 'value_min' => $row['operator'] === 'between' ? $row['value_min'] : null, 'value_max' => $row['operator'] === 'between' ? $row['value_max'] : null, 'is_required' => $row['is_required'], 'source_reference' => $row['source_reference'] ?? null];
            if ($existing) {
                $existing->fill($data);
                if ($existing->isDirty()) {
                    $vacancy->requirements()->create([...$data, 'version' => $existing->version + 1]);
                }
            } else {
                $vacancy->requirements()->create($data);
            }
        }
    }

    public function show(Vacancy $vacancy): View
    {
        return view('vacancies.show', ['vacancy' => $vacancy->load(['jobOrder.company', 'jobOrder.country'])->loadCount(['applications' => fn ($query) => $query->select(DB::raw('count(distinct candidate_id)'))]), 'requirements' => $vacancy->requirements()->with('definition')->orderByDesc('version')->get()->unique('requirement_definition_id')]);
    }

    public function destroy(Vacancy $vacancy): RedirectResponse
    {
        $vacancy->delete();

        return to_route('vacancies.index')->with('success', 'Lowongan berhasil dihapus.');
    }
}
