<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveFlightRequest;
use App\Models\Application;
use App\Models\Company;
use App\Models\Country;
use App\Models\FlightDetail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FlightController extends Controller
{
    private function relations(): array
    {
        return ['application.candidate.person' => fn ($q) => $q->withTrashed(), 'application.vacancy.jobOrder.company', 'application.vacancy.jobOrder.country'];
    }

    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])], 'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')], 'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')]], ['string' => 'Pencarian harus berupa teks.', 'max' => 'Pencarian maksimal :max karakter.', 'date_format' => 'Filter tanggal tidak valid.', 'after_or_equal' => 'Tanggal sampai tidak boleh sebelum tanggal dari.', 'integer' => 'Pilihan filter tidak valid.', 'exists' => 'Pilihan filter tidak tersedia.']);
        $query = FlightDetail::with($this->relations());
        if ($filters['date_from'] ?? null) {
            $query->where('departure_at', '>=', Carbon::parse($filters['date_from'], FlightDetail::TIMEZONE)->startOfDay()->setTimezone(config('app.timezone')));
        }
        if ($filters['date_to'] ?? null) {
            $query->where('departure_at', '<', Carbon::parse($filters['date_to'], FlightDetail::TIMEZONE)->startOfDay()->addDay()->setTimezone(config('app.timezone')));
        }
        foreach (['country_id', 'company_id'] as $field) {
            if ($value = $filters[$field] ?? null) {
                $query->whereHas('application.vacancy.jobOrder', fn ($q) => $q->where($field, $value));
            }
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(function ($q) use ($search): void {
                $q->where('airline', 'like', '%'.$search.'%')->orWhere('flight_number', 'like', '%'.$search.'%')->orWhereHas('application.candidate.person', fn ($person) => $person->withTrashed()->where('full_name', 'like', '%'.$search.'%'));
            });
        }

        return view('flights.index', ['flights' => $query->orderBy('departure_at')->orderBy('id')->paginate(15)->withQueryString(), 'companies' => Company::withTrashed()->orderBy('name')->get(), 'countries' => Country::orderBy('name')->get()]);
    }

    private function form(FlightDetail $flight): View
    {
        return view('flights.form', ['flight' => $flight, 'applications' => Application::withTrashed()->where(function ($q) use ($flight): void {
            $q->whereNull('deleted_at');
            if ($flight->exists) {
                $q->orWhere('id', $flight->application_id);
            }
        })->with(['candidate.person' => fn ($q) => $q->withTrashed(), 'vacancy'])->orderByDesc('id')->get()]);
    }

    public function create(): View
    {
        return $this->form(new FlightDetail);
    }

    public function edit(FlightDetail $flight): View
    {
        return $this->form($flight);
    }

    public function show(FlightDetail $flight): View
    {
        return view('flights.show', ['flight' => $flight->load($this->relations())]);
    }

    private function data(SaveFlightRequest $request): array
    {
        $data = $request->validated();
        foreach (['departure_at', 'arrival_at'] as $field) {
            $data[$field] = Carbon::createFromFormat('Y-m-d\TH:i', $data[$field], FlightDetail::TIMEZONE)->setTimezone(config('app.timezone'))->startOfMinute();
        }

        return $data;
    }

    public function store(SaveFlightRequest $request): RedirectResponse
    {
        $flight = FlightDetail::create($this->data($request));

        return to_route('flights.show', $flight)->with('success', 'Jadwal penerbangan berhasil ditambahkan.');
    }

    public function update(SaveFlightRequest $request, FlightDetail $flight): RedirectResponse
    {
        $flight->update($this->data($request));

        return to_route('flights.show', $flight)->with('success', 'Jadwal penerbangan berhasil diperbarui.');
    }

    public function destroy(FlightDetail $flight): RedirectResponse
    {
        $flight->delete();

        return to_route('flights.index')->with('success', 'Jadwal penerbangan berhasil dihapus.');
    }
}
