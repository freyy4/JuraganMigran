<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveJobOrderRequest;
use App\Models\Company;
use App\Models\Country;
use App\Models\JobOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class JobOrderController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')], 'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')], 'status' => ['nullable', 'string', 'max:255']], ['string' => ':attribute harus berupa teks.', 'max' => ':attribute maksimal :max karakter.', 'integer' => ':attribute tidak valid.', 'exists' => ':attribute tidak tersedia.'], ['search' => 'Pencarian', 'company_id' => 'Sponsor / Mitra', 'country_id' => 'Negara', 'status' => 'Status']);
        $query = JobOrder::with(['company', 'country']);
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(function ($query) use ($search): void {
                $query->where('code', 'like', '%'.$search.'%')->orWhere('reference_number', 'like', '%'.$search.'%')->orWhereHas('company', fn ($company) => $company->where('name', 'like', '%'.$search.'%'));
            });
        }
        foreach (['company_id', 'country_id', 'status'] as $field) {
            if ($filters[$field] ?? null) {
                $query->where($field, $filters[$field]);
            }
        }

        return view('job-orders.index', ['orders' => $query->orderByDesc('id')->paginate(15)->withQueryString(), 'companies' => Company::withTrashed()->orderBy('name')->get(), 'countries' => Country::orderBy('name')->get(), 'statuses' => JobOrder::statusOptions()]);
    }

    private function form(JobOrder $order): View
    {
        $companies = Company::withTrashed()->where(function ($query) use ($order): void {
            $query->whereNull('deleted_at');
            if ($order->exists) {
                $query->orWhere('id', $order->company_id);
            }
        })->orderBy('name')->get();

        return view('job-orders.form', ['order' => $order, 'companies' => $companies, 'countries' => Country::availableForSelection($order->country_id)->orderBy('name')->get(), 'statuses' => JobOrder::statusOptions()]);
    }

    public function create(Request $request): View
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')]]);

        return $this->form(new JobOrder(['quota' => 0, 'status' => 'draft', 'company_id' => isset($data['company_id']) ? (int) $data['company_id'] : null]));
    }

    public function edit(JobOrder $job_order): View
    {
        return $this->form($job_order);
    }

    public function store(SaveJobOrderRequest $request): RedirectResponse
    {
        $order = JobOrder::create($request->validated());

        return to_route('job-orders.show', $order)->with('success', 'Permintaan tenaga kerja berhasil ditambahkan.');
    }

    public function update(SaveJobOrderRequest $request, JobOrder $job_order): RedirectResponse
    {
        $job_order->update($request->validated());

        return to_route('job-orders.show', $job_order)->with('success', 'Permintaan tenaga kerja berhasil diperbarui.');
    }

    public function show(JobOrder $job_order): View
    {
        return view('job-orders.show', ['order' => $job_order->load(['company', 'country']), 'vacancies' => $job_order->vacancies()->orderByDesc('id')->paginate(15)->withQueryString()]);
    }

    public function destroy(JobOrder $job_order): RedirectResponse
    {
        $deleted = DB::transaction(function () use ($job_order): bool {
            $order = JobOrder::whereKey($job_order->id)->lockForUpdate()->firstOrFail();
            if ($order->vacancies()->withTrashed()->exists()) {
                return false;
            }
            $order->delete();

            return true;
        });
        if (! $deleted) {
            return back()->withErrors(['delete' => 'Permintaan tenaga kerja tidak dapat dihapus karena memiliki lowongan terkait.']);
        }

        return to_route('job-orders.index')->with('success', 'Permintaan tenaga kerja berhasil dihapus.');
    }
}
