<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveCountryRequest;
use App\Models\Country;
use App\Services\AccessPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CountryController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'is_active' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'in:10,25,50,100']], ['string' => 'Pencarian harus berupa teks.', 'max' => 'Pencarian maksimal 255 karakter.', 'boolean' => 'Filter status tidak valid.']);
        $query = Country::query()->withCount(['jobOrders', 'companies', 'partnerCompanies']);
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('code', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'));
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return view('countries.index', ['countries' => $query->orderBy('name')->orderBy('id')->paginate($request->integer('per_page', 25))->withQueryString()]);
    }

    public function create(): View
    {
        return view('countries.form', ['country' => new Country(['is_active' => true])]);
    }

    public function edit(Country $country): View
    {
        return view('countries.form', compact('country'));
    }

    public function store(SaveCountryRequest $request): RedirectResponse
    {
        $country = Country::create($request->validated());
        app(AccessPolicy::class)->audit($request->user(), 'master.country.created', ['country_id' => $country->id]);

        return to_route('countries.index')->with('success', 'Negara berhasil ditambahkan.');
    }

    public function update(SaveCountryRequest $request, Country $country): RedirectResponse
    {
        DB::transaction(function () use ($request, $country): void {
            $country = Country::whereKey($country->id)->lockForUpdate()->firstOrFail();
            $used = $country->jobOrders()->exists() || $country->companies()->exists() || $country->partnerCompanies()->exists() || $country->provinces()->exists() || DB::table('health_screening_templates')->where('scope_type', 'COUNTRY')->where('scope_id', $country->id)->exists() || DB::table('document_requirement_sets')->where('scope_type', 'COUNTRY')->where('scope_id', $country->id)->exists();
            if ($used && $country->code !== $request->validated('code')) {
                throw ValidationException::withMessages(['code' => 'Kode negara sudah digunakan dan tidak dapat diubah.']);
            }
            $country->update($request->validated());
            app(AccessPolicy::class)->audit($request->user(), 'master.country.updated', ['country_id' => $country->id]);
        });

        return to_route('countries.index')->with('success', 'Negara berhasil diperbarui.');
    }

    public function status(Request $request, Country $country): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']], ['required' => 'Status wajib dipilih.', 'boolean' => 'Status negara tidak valid.']);
        $country->update($data);
        app(AccessPolicy::class)->audit($request->user(), 'master.country.status_changed', ['country_id' => $country->id, 'is_active' => (bool) $data['is_active']]);

        return to_route('countries.index', $request->only(['search']))->with('success', $data['is_active'] ? 'Negara berhasil diaktifkan.' : 'Negara berhasil dinonaktifkan.');
    }
}
