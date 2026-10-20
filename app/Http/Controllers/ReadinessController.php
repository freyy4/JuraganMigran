<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveReadinessRequest;
use App\Models\Application;
use App\Models\ReadinessChecklist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReadinessController extends Controller
{
    private function relations(): array
    {
        return ['candidate.person' => fn ($query) => $query->withTrashed(), 'vacancy.jobOrder.company', 'vacancy.jobOrder.country', 'readinessChecklists' => fn ($query) => $query->where('type', 'departure')->with('items.verifier')];
    }

    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'readiness' => ['nullable', Rule::in(['ready', 'pending', 'empty'])]], ['in' => 'Filter kesiapan tidak valid.', 'max' => 'Pencarian maksimal 255 karakter.', 'string' => 'Pencarian harus berupa teks.']);
        $query = Application::with($this->relations());
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('application_code', 'like', '%'.$search.'%')->orWhereHas('candidate.person', fn ($person) => $person->withTrashed()->where('full_name', 'like', '%'.$search.'%')));
        }
        $departure = fn ($q) => $q->where('type', 'departure');
        $incomplete = fn ($q) => $q->where('type', 'departure')->where(fn ($q) => $q->whereDoesntHave('items', fn ($i) => $i->where('is_required', true))->orWhereHas('items', fn ($i) => $i->where('is_required', true)->where('status', '!=', 'completed')));
        if (($filters['readiness'] ?? '') === 'empty') {
            $query->whereDoesntHave('readinessChecklists', $departure);
        }
        if (($filters['readiness'] ?? '') === 'ready') {
            $query->whereHas('readinessChecklists', $departure)->whereDoesntHave('readinessChecklists', $incomplete);
        }
        if (($filters['readiness'] ?? '') === 'pending') {
            $query->whereHas('readinessChecklists', $incomplete);
        }

        return view('readiness.index', ['applications' => $query->orderByDesc('id')->paginate(15)->withQueryString()]);
    }

    public function show(Application $application): View
    {
        return view('readiness.show', ['application' => $application->load($this->relations())]);
    }

    public function store(SaveReadinessRequest $request, Application $application): RedirectResponse
    {
        DB::transaction(function () use ($request, $application): void {
            $application = $application->newQuery()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            if ($application->readinessChecklists()->where('type', 'departure')->exists()) {
                throw ValidationException::withMessages(['items' => 'Checklist keberangkatan sudah tersedia.']);
            }
            $checklist = $application->readinessChecklists()->create(['type' => 'departure', 'status' => 'pending', 'completion_percentage' => 0]);
            $checklist->items()->createMany($request->validated('items'));
        });

        return to_route('readiness.show', $application)->with('success', 'Checklist keberangkatan berhasil dibuat.');
    }

    public function update(Request $request, Application $application, ReadinessChecklist $checklist): RedirectResponse
    {
        abort_unless($checklist->application_id === $application->id && $checklist->type === 'departure', 404);
        DB::transaction(function () use ($request, $application, $checklist): void {
            $application = $application->newQuery()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            $checklist = ReadinessChecklist::whereKey($checklist->id)->lockForUpdate()->firstOrFail();
            $items = $checklist->items()->lockForUpdate()->get();
            $rules = ['items' => ['required', 'array', 'size:'.$items->count()]];
            foreach ($items as $item) {
                $rules['items.'.$item->id] = ['required', Rule::in(['pending', 'completed'])];
            }
            $data = $request->validate($rules, ['required' => 'Status setiap item wajib diisi.', 'in' => 'Status item tidak valid.', 'array' => 'Checklist tidak valid.', 'size' => 'Seluruh item checklist harus dikirim.']);
            if (array_diff(array_map('strval', array_keys($data['items'])), $items->modelKeys())) {
                throw ValidationException::withMessages(['items' => 'Item checklist tidak valid.']);
            }
            foreach ($items as $item) {
                $status = $data['items'][$item->id];
                if ($item->status !== $status) {
                    $item->update(['status' => $status, 'verified_by' => $status === 'completed' ? $request->user()->id : null, 'verified_at' => $status === 'completed' ? now() : null]);
                }
            }
            $checklist->synchronize();
            if (in_array($application->status, ['ready', 'ready_for_departure', 'departure_ready'], true) && ! $application->isReadyForDeparture()) {
                $application->update(['status' => 'active']);
            }
        });

        return to_route('readiness.show', $application)->with('success', 'Checklist diperbarui. Status siap berangkat dibatalkan jika item wajib belum selesai.');
    }
}
