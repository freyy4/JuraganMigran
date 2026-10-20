<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveTrainingBatchRequest;
use App\Models\Company;
use App\Models\TrainingBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TrainingBatchController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')->where('type', 'lpk')], 'status' => ['nullable', Rule::in(array_keys(TrainingBatch::STATUSES))]], ['exists' => 'LPK tidak tersedia.', 'in' => 'Status angkatan tidak valid.', 'integer' => 'LPK tidak valid.', 'string' => 'Pencarian harus berupa teks.', 'max' => 'Pencarian maksimal 255 karakter.']);
        $query = TrainingBatch::with('company')->withCount(['enrollments' => fn ($q) => $q->where('status', '!=', 'withdrawn')]);
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'));
        }
        foreach (['company_id', 'status'] as $field) {
            if ($filters[$field] ?? null) {
                $query->where($field, $filters[$field]);
            }
        }

        return view('training.batches.index', ['batches' => $query->orderByDesc('id')->paginate(15)->withQueryString(), 'companies' => Company::withTrashed()->where('type', 'lpk')->orderBy('name')->get()]);
    }

    private function form(TrainingBatch $batch): View
    {
        return view('training.batches.form', ['batch' => $batch, 'companies' => Company::withTrashed()->where(function ($query) use ($batch): void {
            $query->where('type', 'lpk')->whereNull('deleted_at');
            if ($batch->exists) {
                $query->orWhere('id', $batch->company_id);
            }
        })->orderBy('name')->get()]);
    }

    public function create(Request $request): View
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')->where('type', 'lpk')->whereNull('deleted_at')]]);

        return $this->form(new TrainingBatch(['status' => 'planned', 'company_id' => isset($data['company_id']) ? (int) $data['company_id'] : null]));
    }

    public function edit(TrainingBatch $batch): View
    {
        return $this->form($batch);
    }

    public function store(SaveTrainingBatchRequest $request): RedirectResponse
    {
        $batch = TrainingBatch::create($request->validated());

        return to_route('training-batches.show', $batch)->with('success', 'Angkatan pelatihan berhasil dibuat.');
    }

    public function update(SaveTrainingBatchRequest $request, TrainingBatch $batch): RedirectResponse
    {
        DB::transaction(function () use ($request, $batch): void {
            $locked = TrainingBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $data = $request->validated();
            if (($data['capacity'] ?? null) !== null && $locked->enrollments()->where('status', '!=', 'withdrawn')->count() > $data['capacity']) {
                throw ValidationException::withMessages(['capacity' => 'Kapasitas tidak boleh lebih kecil dari jumlah peserta yang terdaftar.']);
            }
            $locked->update($data);
        });

        return to_route('training-batches.show', $batch)->with('success', 'Angkatan pelatihan berhasil diperbarui.');
    }

    public function show(TrainingBatch $batch): View
    {
        return view('training.batches.show', ['batch' => $batch->load('company'), 'enrollments' => $batch->enrollments()->with(['application.candidate.person' => fn ($q) => $q->withTrashed(), 'batch.company'])->orderByDesc('id')->paginate(15)->withQueryString()]);
    }
}
