<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveTrainingEnrollmentRequest;
use App\Models\Application;
use App\Models\Company;
use App\Models\TrainingBatch;
use App\Models\TrainingEnrollment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TrainingEnrollmentController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')->where('type', 'lpk')], 'batch_id' => ['nullable', 'integer', Rule::exists('training_batches', 'id')], 'status' => ['nullable', Rule::in(array_keys(TrainingEnrollment::STATUSES))]], ['exists' => ':attribute tidak tersedia.', 'in' => 'Status peserta tidak valid.', 'integer' => ':attribute tidak valid.', 'string' => 'Pencarian harus berupa teks.', 'max' => 'Pencarian maksimal 255 karakter.'], ['company_id' => 'LPK', 'batch_id' => 'Angkatan']);
        $query = TrainingEnrollment::with(['application.candidate.person' => fn ($q) => $q->withTrashed(), 'batch.company']);
        if ($search = trim($filters['search'] ?? '')) {
            $query->whereHas('application', fn ($q) => $q->where('application_code', 'like', '%'.$search.'%')->orWhereHas('candidate', fn ($q) => $q->where('candidate_code', 'like', '%'.$search.'%')->orWhereHas('person', fn ($q) => $q->withTrashed()->where('full_name', 'like', '%'.$search.'%'))));
        }
        foreach (['batch_id', 'status'] as $field) {
            if ($filters[$field] ?? null) {
                $query->where($field, $filters[$field]);
            }
        }
        if ($company = $filters['company_id'] ?? null) {
            $query->whereHas('batch', fn ($q) => $q->where('company_id', $company));
        }

        return view('training.enrollments.index', ['enrollments' => $query->orderByDesc('id')->paginate(15)->withQueryString(), 'companies' => Company::withTrashed()->where('type', 'lpk')->orderBy('name')->get(), 'batches' => TrainingBatch::with('company')->orderBy('name')->get()]);
    }

    private function form(TrainingEnrollment $enrollment): View
    {
        return view('training.enrollments.form', ['enrollment' => $enrollment, 'applications' => Application::withTrashed()->where(function ($q) use ($enrollment): void {
            $q->whereNull('deleted_at');
            if ($enrollment->exists) {
                $q->orWhere('id', $enrollment->application_id);
            }
        })->with(['candidate.person' => fn ($q) => $q->withTrashed(), 'vacancy'])->orderByDesc('id')->get(), 'batches' => TrainingBatch::with('company')->where(function ($q) use ($enrollment): void {
            $q->whereIn('status', ['planned', 'active'])->whereHas('company', fn ($q) => $q->where('type', 'lpk')->whereNull('deleted_at'));
            if ($enrollment->exists) {
                $q->orWhere('id', $enrollment->batch_id);
            }
        })->orderBy('name')->get()]);
    }

    public function create(Request $request): View
    {
        $data = $request->validate(['batch_id' => ['nullable', 'integer', Rule::exists('training_batches', 'id')]], ['exists' => 'Angkatan tidak tersedia.', 'integer' => 'Angkatan tidak valid.']);

        return $this->form(new TrainingEnrollment(['status' => 'scheduled', 'batch_id' => $data['batch_id'] ?? null]));
    }

    public function edit(TrainingEnrollment $enrollment): View
    {
        return $this->form($enrollment);
    }

    public function store(SaveTrainingEnrollmentRequest $request): RedirectResponse
    {
        $this->save($request, new TrainingEnrollment);

        return to_route('training.index')->with('success', 'CPMI berhasil didaftarkan ke angkatan.');
    }

    public function update(SaveTrainingEnrollmentRequest $request, TrainingEnrollment $enrollment): RedirectResponse
    {
        $this->save($request, $enrollment);

        return to_route('training.index')->with('success', 'Data peserta pelatihan berhasil diperbarui.');
    }

    private function save(SaveTrainingEnrollmentRequest $request, TrainingEnrollment $enrollment): void
    {
        DB::transaction(function () use ($request, $enrollment): void {
            $data = $request->validated();
            $batch = TrainingBatch::whereKey($data['batch_id'])->lockForUpdate()->firstOrFail();
            $locked = $enrollment->exists ? TrainingEnrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail() : $enrollment;
            $sameBatch = $locked->exists && $locked->batch_id === $batch->id;
            $sameApplication = $locked->exists && $locked->application_id === (int) $data['application_id'];
            if ($locked->exists && (! $sameBatch || ! $sameApplication) && DB::table('training_leave_requests')->where('training_enrollment_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['application_id' => 'Peserta dan angkatan tidak dapat diganti setelah memiliki riwayat izin.']);
            }
            if ((! $sameBatch || ! $sameApplication) && (! in_array($batch->status, ['planned', 'active'], true) || ! $batch->company || $batch->company->trashed() || $batch->company->type !== 'lpk')) {
                throw ValidationException::withMessages(['batch_id' => 'Pendaftaran hanya tersedia untuk angkatan direncanakan atau berlangsung pada LPK yang tersedia.']);
            }
            $application = Application::withTrashed()->findOrFail($data['application_id']);
            if ($application->trashed() && ! $sameApplication) {
                throw ValidationException::withMessages(['application_id' => 'Lamaran sudah dihapus.']);
            }
            $others = $batch->enrollments()->when($locked->exists, fn ($q) => $q->where('id', '!=', $locked->id));
            if ((clone $others)->where('application_id', $application->id)->exists() || (clone $others)->where('status', '!=', 'withdrawn')->whereHas('application', fn ($q) => $q->where('candidate_id', $application->candidate_id))->exists()) {
                throw ValidationException::withMessages(['application_id' => 'CPMI atau lamaran ini sudah terdaftar pada angkatan tersebut.']);
            }
            if ($data['status'] !== 'withdrawn' && $batch->capacity !== null && (clone $others)->where('status', '!=', 'withdrawn')->count() >= $batch->capacity) {
                throw ValidationException::withMessages(['batch_id' => 'Kapasitas angkatan sudah penuh.']);
            }
            foreach (['joined_at', 'completed_at'] as $field) {
                $data[$field] = empty($data[$field]) ? null : Carbon::parse($data[$field], TrainingEnrollment::TIMEZONE)->startOfDay()->setTimezone(config('app.timezone'));
            }
            if ($data['status'] !== 'completed' && $data['completed_at'] !== null) {
                throw ValidationException::withMessages(['completed_at' => 'Tanggal selesai hanya diisi untuk peserta berstatus Selesai.']);
            }
            $locked->fill($data)->save();
        });
    }
}
