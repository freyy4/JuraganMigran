<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveMedicalEventRequest;
use App\Models\Application;
use App\Models\Company;
use App\Models\MedicalEvent;
use App\Services\AccessPolicy;
use App\Services\CareJourney;
use App\Services\DocumentRequirementEngine;
use App\Services\TrustCommitmentProtocol;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MedicalEventController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(array_keys(MedicalEvent::STATUSES))]]);
        $query = MedicalEvent::with(['application.candidate.person' => fn ($query) => $query->withTrashed(), 'application.vacancy', 'clinic']);
        if ($filters['status'] ?? null) {
            $query->where('status', $filters['status']);
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->whereHas('application', fn ($query) => $query->where('application_code', 'like', '%'.$search.'%')->orWhereHas('candidate.person', fn ($person) => $person->withTrashed()->where('full_name', 'like', '%'.$search.'%')));
        }

        return view('medical-events.index', ['events' => $query->orderByDesc('scheduled_at')->paginate(15)->withQueryString()]);
    }

    private function form(MedicalEvent $event): View
    {
        $applications = Application::withTrashed()->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('id', $event->application_id))->with(['candidate.person' => fn ($query) => $query->withTrashed()])->latest('id')->get();
        $clinics = Company::withTrashed()->where('type', 'clinic')->where(fn ($query) => $query->where(fn ($query) => $query->whereNull('deleted_at')->where('status', 'active'))->orWhere('id', $event->clinic_id))->orderBy('name')->get();

        return view('medical-events.form', ['event' => $event, 'applications' => $applications, 'clinics' => $clinics]);
    }

    public function create(): View
    {
        return $this->form(new MedicalEvent(['status' => 'scheduled']));
    }

    public function edit(MedicalEvent $medical_event): View
    {
        return $this->form($medical_event);
    }

    private function data(SaveMedicalEventRequest $request): array
    {
        $data = $request->validated();
        $application = Application::whereKey($data['application_id'])->firstOrFail();
        app(TrustCommitmentProtocol::class)->assertMcuAllowed($application, $data['status'] === 'completed');
        $existing = $request->route('medical_event');
        if ($existing?->status === 'completed' || ($data['clearance_status'] ?? null) !== $existing?->clearance_status || (($data['status'] === 'completed') && $existing?->status !== 'completed')) {
            app(TrustCommitmentProtocol::class)->authorize($request->user(), $application, 'health.mcu.result.write');
        }
        foreach (['scheduled_at', 'completed_at'] as $field) {
            $data[$field] = empty($data[$field]) ? null : Carbon::createFromFormat('Y-m-d\TH:i', $data[$field], 'Asia/Jakarta')->setTimezone(config('app.timezone'))->startOfMinute();
        }
        if ($data['status'] !== 'completed') {
            $data['completed_at'] = null;
            $data['clearance_status'] = null;
            $data['expires_at'] = null;
        }

        if (config('care.enabled') && Schema::hasColumn('care_journey_templates', 'policy')) {
            $application = Application::whereKey($data['application_id'])->firstOrFail();
            $policy = json_decode(app(CareJourney::class)->template($application)->policy ?? '{}', true);
            if ($policy['consent_required'] ?? false) {
                $typeId = $policy['consent_document_type_id'] ?? null;
                $documents = app(DocumentRequirementEngine::class)->rows($application);
                $consent = $documents->first(fn (array $row): bool => (int) $row['rule']->document_type_id === (int) $typeId);
                if (! $typeId || ! $consent || ! $consent['verified']) {
                    throw ValidationException::withMessages(['application_id' => 'Izin keluarga terverifikasi wajib sebelum MCU untuk program ini.']);
                }
            }
        }

        return $data;
    }

    public function store(SaveMedicalEventRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $event = MedicalEvent::create([...$this->data($request), 'created_by' => $request->user()->id]);
            app(AccessPolicy::class)->audit($request->user(), 'medical.result_create', ['medical_event_id' => $event->id, 'application_id' => $event->application_id]);
        });

        return to_route('medical-events.index')->with('success', 'Jadwal MCU berhasil disimpan.');
    }

    public function update(SaveMedicalEventRequest $request, MedicalEvent $medical_event): RedirectResponse
    {
        DB::transaction(function () use ($request, $medical_event): void {
            $data = $this->data($request);
            $medical_event->update($data);
        });
        app(AccessPolicy::class)->audit($request->user(), 'medical.result_update', ['medical_event_id' => $medical_event->id, 'application_id' => $medical_event->application_id]);

        return to_route('medical-events.index')->with('success', 'Data MCU berhasil diperbarui.');
    }
}
