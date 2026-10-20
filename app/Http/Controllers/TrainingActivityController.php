<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\TrainingBatch;
use App\Models\TrainingEnrollment;
use App\Services\AccessPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrainingActivityController extends Controller
{
    public function leaves(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])], 'enrollment_id' => ['nullable', 'integer', Rule::exists('training_enrollments', 'id')]]);
        $query = DB::table('training_leave_requests as leave')->join('training_enrollments as enrollment', 'enrollment.id', '=', 'leave.training_enrollment_id')->join('training_batches as batch', 'batch.id', '=', 'enrollment.batch_id')->join('applications as application', 'application.id', '=', 'enrollment.application_id')->join('candidates as candidate', 'candidate.id', '=', 'application.candidate_id')->join('persons as person', 'person.id', '=', 'candidate.person_id')->leftJoin('users', 'users.id', '=', 'leave.approved_by')->select('leave.*', 'batch.name as batch_name', 'person.full_name', 'application.application_code', 'users.name as reviewer');
        $query->whereIn('application.id', app(AccessPolicy::class)->applicationIds($request->user(), $request->attributes->get('access_permission')));
        if ($filters['status'] ?? null) {
            $query->where('leave.approval_status', $filters['status']);
        }
        if ($filters['enrollment_id'] ?? null) {
            $query->where('enrollment.id', $filters['enrollment_id']);
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($query) => $query->where('person.full_name', 'like', '%'.$search.'%')->orWhere('candidate.candidate_code', 'like', '%'.$search.'%')->orWhere('application.application_code', 'like', '%'.$search.'%')->orWhere('batch.name', 'like', '%'.$search.'%'));
        }
        $enrollments = TrainingEnrollment::with(['application.candidate.person' => fn ($query) => $query->withTrashed(), 'batch.company'])->whereIn('status', ['scheduled', 'ongoing', 'on_hold'])->whereHas('application')->orderByDesc('id')->get();

        return view('training.leaves', ['leaves' => $query->orderByDesc('leave.id')->paginate(15)->withQueryString(), 'enrollments' => $enrollments]);
    }

    public function storeLeave(Request $request): RedirectResponse
    {
        $data = $request->validate(['training_enrollment_id' => ['required', 'integer', Rule::exists('training_enrollments', 'id')], 'leave_type' => ['required', Rule::in(['permission', 'sick', 'absence'])], 'start_at' => ['required', 'date_format:Y-m-d\TH:i'], 'end_at' => ['required', 'date_format:Y-m-d\TH:i', 'after_or_equal:start_at'], 'reason' => ['required', 'string', 'max:5000'], 'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']], ['required' => ':attribute wajib diisi.', 'exists' => 'Peserta tidak tersedia.', 'integer' => 'Peserta tidak valid.', 'in' => 'Jenis izin tidak valid.', 'date_format' => ':attribute harus berupa tanggal dan waktu yang valid.', 'after_or_equal' => 'Waktu selesai tidak boleh sebelum waktu mulai.', 'string' => 'Alasan harus berupa teks.', 'max' => ':attribute melebihi batas :max.', 'mimes' => 'Bukti harus berupa PDF, JPG, atau PNG.'], ['training_enrollment_id' => 'Peserta', 'start_at' => 'Waktu mulai', 'end_at' => 'Waktu selesai', 'reason' => 'Alasan', 'evidence' => 'Bukti']);
        $path = $request->file('evidence')?->store('', 'documents');
        try {
            DB::transaction(function () use ($data, $path): void {
                $enrollment = TrainingEnrollment::whereKey($data['training_enrollment_id'])->lockForUpdate()->firstOrFail();
                if (! in_array($enrollment->status, ['scheduled', 'ongoing', 'on_hold'], true) || ! $enrollment->application || $enrollment->application->trashed()) {
                    throw ValidationException::withMessages(['training_enrollment_id' => 'Izin hanya dapat dicatat untuk peserta yang belum selesai atau mengundurkan diri dan lamaran yang tersedia.']);
                }
                $start = Carbon::createFromFormat('Y-m-d\TH:i', $data['start_at'], TrainingEnrollment::TIMEZONE)->setTimezone(config('app.timezone'))->startOfMinute();
                $end = Carbon::createFromFormat('Y-m-d\TH:i', $data['end_at'], TrainingEnrollment::TIMEZONE)->setTimezone(config('app.timezone'))->startOfMinute();
                $overlap = DB::table('training_leave_requests')->where('training_enrollment_id', $enrollment->id)->whereIn('approval_status', ['pending', 'approved'])->whereNull('return_confirmed_at')->where('start_at', '<=', $end)->where(fn ($query) => $query->whereNull('end_at')->orWhere('end_at', '>=', $start))->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['start_at' => 'Periode izin bertumpang tindih dengan pengajuan yang masih aktif.']);
                }
                DB::table('training_leave_requests')->insert(['training_enrollment_id' => $enrollment->id, 'leave_type' => $data['leave_type'], 'start_at' => $start, 'end_at' => $end, 'reason' => $data['reason'], 'evidence' => $path, 'approval_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('documents')->delete($path);
            }
            throw $exception;
        }

        return to_route('training-leaves.index')->with('success', 'Izin / ketidakhadiran berhasil dicatat.');
    }

    public function action(Request $request, int $leave): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'reject', 'return'])]]);
        DB::transaction(function () use ($request, $data, $leave): void {
            $record = DB::table('training_leave_requests')->where('id', $leave)->first();
            abort_unless($record, 404);
            $enrollment = TrainingEnrollment::whereKey($record->training_enrollment_id)->lockForUpdate()->firstOrFail();
            $record = DB::table('training_leave_requests')->where('id', $leave)->lockForUpdate()->first();
            if ($data['action'] === 'return') {
                if ($record->approval_status !== 'approved' || $record->return_confirmed_at || Carbon::parse($record->start_at, config('app.timezone'))->isFuture()) {
                    throw ValidationException::withMessages(['action' => 'Konfirmasi kembali hanya tersedia untuk izin disetujui yang sudah dimulai dan belum dikonfirmasi.']);
                }
                DB::table('training_leave_requests')->where('id', $leave)->update(['return_confirmed_at' => now(), 'updated_at' => now()]);
            } else {
                if ($record->approval_status !== 'pending') {
                    throw ValidationException::withMessages(['action' => 'Pengajuan sudah diputuskan. Muat ulang halaman.']);
                }
                if ($data['action'] === 'approve' && (in_array($enrollment->status, ['completed', 'withdrawn'], true) || $enrollment->application->trashed())) {
                    throw ValidationException::withMessages(['action' => 'Peserta tidak lagi tersedia untuk persetujuan izin.']);
                }
                DB::table('training_leave_requests')->where('id', $leave)->update(['approval_status' => $data['action'] === 'approve' ? 'approved' : 'rejected', 'approved_by' => $request->user()->id, 'updated_at' => now()]);
            }
        });

        return back()->with('success', 'Status izin berhasil diperbarui.');
    }

    public function evidence(int $leave): StreamedResponse
    {
        $record = DB::table('training_leave_requests')->find($leave);
        $path = $record?->evidence;
        abort_unless($path && preg_match('/^[a-zA-Z0-9_-]+\.(pdf|jpg|jpeg|png)$/', $path) && Storage::disk('documents')->exists($path), 404);

        return Storage::disk('documents')->download($path, 'bukti-izin-'.$leave.'.'.pathinfo($path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function progress(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'batch_id' => ['nullable', 'integer', Rule::exists('training_batches', 'id')], 'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')->where('type', 'lpk')], 'status' => ['nullable', Rule::in(array_keys(TrainingEnrollment::STATUSES))]]);
        $query = TrainingEnrollment::with(['application.candidate.person' => fn ($query) => $query->withTrashed(), 'batch.company']);
        if ($search = trim($filters['search'] ?? '')) {
            $query->whereHas('application', fn ($query) => $query->withTrashed()->where('application_code', 'like', '%'.$search.'%')->orWhereHas('candidate.person', fn ($person) => $person->withTrashed()->where('full_name', 'like', '%'.$search.'%'))->orWhereHas('candidate', fn ($candidate) => $candidate->withTrashed()->where('candidate_code', 'like', '%'.$search.'%')));
        }
        foreach (['batch_id', 'status'] as $field) {
            if ($filters[$field] ?? null) {
                $query->where($field, $filters[$field]);
            }
        }
        if ($filters['company_id'] ?? null) {
            $query->whereHas('batch', fn ($query) => $query->where('company_id', $filters['company_id']));
        }
        $summary = [];
        foreach (TrainingEnrollment::STATUSES as $status => $label) {
            $summary[$label] = (clone $query)->where('status', $status)->count();
        }
        $enrollments = $query->latest('id')->paginate(15)->withQueryString();
        $leaves = DB::table('training_leave_requests')->whereIn('training_enrollment_id', $enrollments->pluck('id'))->where('approval_status', 'approved')->whereNull('return_confirmed_at')->where('start_at', '<=', now())->get()->groupBy('training_enrollment_id');

        return view('training.progress', ['enrollments' => $enrollments, 'summary' => $summary, 'leaves' => $leaves, 'batches' => TrainingBatch::orderBy('name')->get(), 'companies' => Company::withTrashed()->where('type', 'lpk')->orderBy('name')->get()]);
    }
}
