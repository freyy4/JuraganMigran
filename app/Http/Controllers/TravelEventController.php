<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Services\TrackingHub;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TravelEventController extends Controller
{
    public const LABELS = ['OPP' => 'OPP', 'departure' => 'Keberangkatan', 'arrival' => 'Kedatangan'];

    public const ROUTES = ['OPP' => 'opp', 'departure' => 'departures', 'arrival' => 'arrivals'];

    private function type(Request $request): string
    {
        return $request->route()->defaults['event_type'];
    }

    public function index(Request $request): View
    {
        $type = $this->type($request);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(['scheduled', 'completed', 'cancelled'])]]);
        $query = DB::table('travel_events as event')->join('applications as application', 'application.id', '=', 'event.application_id')->join('candidates as candidate', 'candidate.id', '=', 'application.candidate_id')->join('persons as person', 'person.id', '=', 'candidate.person_id')->where('event.event_type', $type)->select('event.*', 'application.application_code', 'person.full_name');
        if ($filters['status'] ?? null) {
            $query->where('event.status', $filters['status']);
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($query) => $query->where('application.application_code', 'like', '%'.$search.'%')->orWhere('candidate.candidate_code', 'like', '%'.$search.'%')->orWhere('person.full_name', 'like', '%'.$search.'%'));
        }

        return view('travel.index', ['type' => $type, 'label' => self::LABELS[$type], 'prefix' => self::ROUTES[$type], 'events' => $query->orderByDesc('event.scheduled_at')->orderByDesc('event.id')->paginate(15)->withQueryString()]);
    }

    private function form(Request $request, ?object $event): View
    {
        $type = $this->type($request);
        $applications = Application::withTrashed()->where(fn ($query) => $query->whereNull('deleted_at')->when($event, fn ($query) => $query->orWhere('id', $event->application_id)))->with(['candidate.person' => fn ($query) => $query->withTrashed(), 'vacancy'])->latest('id')->get();

        return view('travel.form', ['type' => $type, 'label' => self::LABELS[$type], 'prefix' => self::ROUTES[$type], 'event' => $event, 'applications' => $applications]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, null);
    }

    public function edit(Request $request, int $event): View
    {
        $record = DB::table('travel_events')->where('id', $event)->where('event_type', $this->type($request))->first();
        abort_unless($record, 404);

        return $this->form($request, $record);
    }

    private function save(Request $request, ?int $event): RedirectResponse
    {
        $type = $this->type($request);
        $record = $event ? DB::table('travel_events')->where('id', $event)->where('event_type', $type)->first() : null;
        abort_if($event && ! $record, 404);
        $data = $request->validate([
            'application_id' => ['required', 'integer', $record ? Rule::in([$record->application_id]) : Rule::exists('applications', 'id')->whereNull('deleted_at')],
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'completed_at' => ['nullable', 'required_if:status,completed', 'date_format:Y-m-d\TH:i', 'before_or_equal:'.now('Asia/Jakarta')->format('Y-m-d\TH:i')],
            'status' => ['required', Rule::in(['scheduled', 'completed', 'cancelled'])],
            'notes' => ['nullable', 'string', 'max:10000'],
        ], ['required' => ':attribute wajib diisi.', 'required_if' => 'Waktu aktual wajib diisi untuk status Selesai.', 'in' => ':attribute tidak valid atau lamaran tidak dapat diganti.', 'exists' => 'Lamaran tidak tersedia.', 'integer' => 'Lamaran tidak valid.', 'date_format' => ':attribute harus berupa tanggal dan waktu yang valid.', 'before_or_equal' => 'Waktu aktual tidak boleh di masa depan.', 'string' => 'Catatan harus berupa teks.', 'max' => 'Catatan maksimal 10000 karakter.'], ['application_id' => 'Lamaran', 'scheduled_at' => 'Jadwal', 'completed_at' => 'Waktu aktual', 'status' => 'Status']);
        foreach (['scheduled_at', 'completed_at'] as $field) {
            $data[$field] = empty($data[$field]) ? null : Carbon::createFromFormat('Y-m-d\TH:i', $data[$field], 'Asia/Jakarta')->setTimezone(config('app.timezone'))->startOfMinute();
        }
        if ($data['status'] !== 'completed') {
            $data['completed_at'] = null;
        }
        DB::transaction(function () use ($data, $type, $event): void {
            $application = Application::withTrashed()->whereKey($data['application_id'])->lockForUpdate()->firstOrFail();
            if ($event) {
                $locked = DB::table('travel_events')->where('id', $event)->where('event_type', $type)->lockForUpdate()->first();
                abort_unless($locked, 404);
                if ((int) $locked->application_id !== (int) $application->id) {
                    throw ValidationException::withMessages(['application_id' => 'Lamaran tidak dapat diganti.']);
                }
            } elseif ($application->trashed()) {
                throw ValidationException::withMessages(['application_id' => 'Lamaran sudah dihapus.']);
            }
            if ($data['status'] !== 'cancelled' && DB::table('travel_events')->where('application_id', $application->id)->where('event_type', $type)->where('status', '!=', 'cancelled')->when($event, fn ($query) => $query->where('id', '!=', $event))->exists()) {
                throw ValidationException::withMessages(['application_id' => 'Lamaran sudah memiliki catatan aktif untuk tahap ini. Edit catatan tersebut.']);
            }
            if ($type === 'departure' && $data['status'] === 'completed' && ! $application->isReadyForDeparture()) {
                throw ValidationException::withMessages(['status' => 'Selesaikan checklist kesiapan keberangkatan sebelum mencatat keberangkatan selesai.']);
            }
            if ($type === 'arrival' && $data['status'] === 'completed') {
                $departure = DB::table('travel_events')->where('application_id', $application->id)->where('event_type', 'departure')->where('status', 'completed')->whereNotNull('completed_at')->first();
                if (! $departure || $data['completed_at']->lt(Carbon::parse($departure->completed_at, config('app.timezone')))) {
                    throw ValidationException::withMessages(['completed_at' => 'Kedatangan harus setelah keberangkatan yang sudah tercatat selesai.']);
                }
            }
            if ($type === 'departure') {
                $arrival = DB::table('travel_events')->where('application_id', $application->id)->where('event_type', 'arrival')->where('status', 'completed')->first();
                if ($arrival && ($data['status'] !== 'completed' || ! $data['completed_at'] || $data['completed_at']->gt(Carbon::parse($arrival->completed_at, config('app.timezone'))))) {
                    throw ValidationException::withMessages(['status' => 'Keberangkatan tidak dapat dibatalkan atau dipindahkan setelah kedatangan yang sudah selesai.']);
                }
            }
            if ($event) {
                DB::table('travel_events')->where('id', $event)->update([...$data, 'updated_at' => now()]);
            } else {
                DB::table('travel_events')->insert([...$data, 'event_type' => $type, 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($type === 'departure' && $data['status'] === 'completed') {
                app(TrackingHub::class)->milestone($application->id, 'DEPARTED', 'departure-'.$application->id);
            }
        });

        return to_route(self::ROUTES[$type].'.index')->with('success', self::LABELS[$type].' berhasil disimpan.');
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, int $event): RedirectResponse
    {
        return $this->save($request, $event);
    }
}
