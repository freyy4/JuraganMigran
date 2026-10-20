<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCompanyRequest;
use App\Models\Company;
use App\Models\Country;
use App\Services\AccessPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'type' => ['nullable', Rule::in(array_keys(Company::TYPES))], 'status' => ['nullable', 'string', 'max:255']], ['string' => ':attribute harus berupa teks.', 'max' => ':attribute maksimal :max karakter.', 'in' => 'Pilihan :attribute tidak valid.'], ['search' => 'Pencarian', 'type' => 'Jenis', 'status' => 'Status']);
        $query = Company::with(['country', 'countries', 'primaryContact'])->withCount(['jobOrders', 'trainingBatches']);
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(function ($query) use ($search): void {
                $query->where('code', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhereHas('contacts', fn ($contacts) => $contacts->where('name', 'like', '%'.$search.'%'));
            });
        }
        foreach (['type', 'status'] as $field) {
            if ($filters[$field] ?? null) {
                $query->where($field, $filters[$field]);
            }
        }

        return view('companies.index', ['companies' => $query->orderBy('name')->orderBy('id')->paginate(25)->withQueryString(), 'statuses' => Company::statusOptions()]);
    }

    private function form(Company $company): View
    {
        return view('companies.form', ['company' => $company, 'countries' => Country::where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $company->selectedCountryIds()))->orderBy('name')->get(), 'statuses' => Company::statusOptions()]);
    }

    public function create(Request $request): View
    {
        $data = $request->validate(['type' => ['nullable', Rule::in(array_keys(Company::TYPES))]]);

        return $this->form(new Company(['status' => 'active', 'type' => $data['type'] ?? null]));
    }

    public function edit(Company $company): View
    {
        return $this->form($company->load('contacts'));
    }

    public function store(SaveCompanyRequest $request): RedirectResponse
    {
        $company = DB::transaction(function () use ($request): Company {
            $company = Company::create(collect($request->validated())->except(['contacts', 'country_ids'])->all());
            $this->syncCountries($company, $request->validated('country_ids'));
            $this->syncContacts($company, $request->validated('contacts', []));

            return $company;
        });

        return to_route('companies.show', $company)->with('success', 'Mitra / Sponsor berhasil ditambahkan.');
    }

    public function update(SaveCompanyRequest $request, Company $company): RedirectResponse
    {
        DB::transaction(function () use ($request, $company): void {
            $company = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            if ($company->code !== $request->validated('code') && ($company->jobOrders()->exists() || $company->trainingBatches()->exists() || DB::table('commercial_agreements')->where('company_id', $company->id)->exists() || DB::table('health_screening_templates')->where('scope_type', 'SPONSOR')->where('scope_id', $company->id)->exists() || DB::table('document_requirement_sets')->where('scope_type', 'SPONSOR')->where('scope_id', $company->id)->exists())) {
                throw ValidationException::withMessages(['code' => 'Kode perusahaan yang sudah digunakan tidak dapat diubah.']);
            }
            $company->update(collect($request->validated())->except(['contacts', 'country_ids'])->all());
            $this->syncCountries($company, $request->validated('country_ids'));
            $this->syncContacts($company, $request->validated('contacts', []));
        });

        return to_route('companies.show', $company)->with('success', 'Data Mitra / Sponsor berhasil diperbarui.');
    }

    private function syncCountries(Company $company, array $countryIds): void
    {
        $countryIds = array_map('intval', $countryIds);
        $company->countries()->sync($countryIds);
        $company->update(['country_id' => in_array($company->country_id, $countryIds, true) ? $company->country_id : ($countryIds[0] ?? null)]);
        $company->unsetRelation('countries');
    }

    private function syncContacts(Company $company, array $contacts): void
    {
        $ids = [];
        foreach ($contacts as $data) {
            $id = $data['id'] ?? null;
            unset($data['id']);
            if ($id) {
                $contact = $company->contacts()->findOrFail($id);
                $contact->update($data);
            } else {
                $contact = $company->contacts()->create($data);
            }
            $ids[] = $contact->id;
        }
        $company->contacts()->whereNotIn('id', $ids)->delete();
    }

    public function show(Company $company): View
    {
        return view('companies.show', ['company' => $company->load(['country', 'countries', 'contacts']), 'jobOrders' => $company->jobOrders()->with('country')->orderByDesc('id')->paginate(10, ['*'], 'job_orders_page')->withQueryString(), 'vacancies' => $company->vacancies()->with('jobOrder')->orderByDesc('id')->paginate(10, ['vacancies.*'], 'vacancies_page')->withQueryString()]);
    }

    public function destroy(Company $company): RedirectResponse
    {
        DB::transaction(function () use ($company): void {
            $company = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            if ($company->status !== 'draft' || $company->jobOrders()->exists() || $company->trainingBatches()->exists() || DB::table('commercial_agreements')->where('company_id', $company->id)->exists() || DB::table('health_screening_templates')->where('scope_type', 'SPONSOR')->where('scope_id', $company->id)->exists() || DB::table('document_requirement_sets')->where('scope_type', 'SPONSOR')->where('scope_id', $company->id)->exists()) {
                throw ValidationException::withMessages(['company' => 'Perusahaan aktif atau yang pernah digunakan tidak dapat dihapus. Ubah status menjadi Nonaktif.']);
            }
            $company->delete();
            app(AccessPolicy::class)->audit(request()->user(), 'master.partner.deleted', ['company_id' => $company->id]);
        });

        return to_route('companies.index')->with('success', 'Mitra / Sponsor berhasil dihapus.');
    }
}
