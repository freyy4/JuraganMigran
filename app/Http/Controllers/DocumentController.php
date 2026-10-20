<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewDocumentRequest;
use App\Http\Requests\UploadDocumentRequest;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\DocumentType;
use App\Models\Vacancy;
use App\Services\DocumentRequirementEngine;
use App\Services\DocumentScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255']], ['string' => 'Pencarian harus berupa teks.', 'max' => 'Pencarian maksimal :max karakter.']);
        $query = Application::with(['candidate.person' => fn ($query) => $query->withTrashed(), 'vacancy']);
        if ($search = trim($data['search'] ?? '')) {
            $query->where(function ($query) use ($search): void {
                $query->where('application_code', 'like', '%'.$search.'%')->orWhereHas('candidate', fn ($candidate) => $candidate->withTrashed()->where('candidate_code', 'like', '%'.$search.'%'))->orWhereHas('candidate.person', fn ($person) => $person->withTrashed()->where('full_name', 'like', '%'.$search.'%'));
            });
        }
        $today = now('Asia/Jakarta')->toDateString();
        $validDates = fn ($q) => $q->where(fn ($dates) => $dates->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today));
        $latest = CandidateDocument::latestVersions();
        $pending = (clone $latest)->whereIn('status', ['uploaded', 'reviewed'])->where($validDates)->count();
        $rejected = (clone $latest)->where('status', 'rejected')->count();
        $expiring = (clone $latest)->whereNotIn('status', ['rejected', 'expired'])->whereBetween('expires_at', [$today, now('Asia/Jakarta')->addDays(30)->toDateString()])->count();

        $operations = app(DocumentOperationsController::class)->queueData();

        return view('documents.index', ['applications' => $query->orderByDesc('id')->paginate(15)->withQueryString(), 'summary' => ['Menunggu Review' => $pending, 'Dokumen Kurang' => $this->missingCount(), 'Ditolak' => $rejected, 'Akan Kedaluwarsa' => $expiring], 'operations' => $operations]);
    }

    public function verification(Request $request): View
    {
        return $this->queue($request, false);
    }

    public function expiry(Request $request): View
    {
        return $this->queue($request, true);
    }

    private function queue(Request $request, bool $expiry): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(['pending', 'uploaded', 'reviewed', 'verified', 'rejected', 'needs_correction'])], 'period' => ['nullable', Rule::in(['expired', 'expiring'])]]);
        $query = CandidateDocument::latestVersions()->with(['documentType', 'candidate.person' => fn ($query) => $query->withTrashed(), 'application.vacancy.jobOrder.country', 'application.currentStage', 'verifier']);
        $today = now('Asia/Jakarta')->toDateString();
        if ($expiry) {
            if (($filters['period'] ?? 'expired') === 'expiring') {
                $query->whereNotIn('status', ['rejected', 'expired'])->whereBetween('expires_at', [$today, now('Asia/Jakarta')->addDays(30)->toDateString()]);
            } else {
                $query->where(fn ($query) => $query->where('status', 'expired')->orWhereDate('expires_at', '<', $today));
            }
        } else {
            if (($filters['status'] ?? 'pending') === 'pending') {
                $query->whereIn('status', ['uploaded', 'reviewed']);
            } else {
                $query->where('status', $filters['status']);
            }
            $query->where(fn ($query) => $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today));
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn ($query) => $query->where('document_number', 'like', '%'.$search.'%')->orWhereHas('application', fn ($application) => $application->withTrashed()->where('application_code', 'like', '%'.$search.'%'))->orWhereHas('candidate', fn ($candidate) => $candidate->withTrashed()->where('candidate_code', 'like', '%'.$search.'%'))->orWhereHas('candidate.person', fn ($person) => $person->withTrashed()->where('full_name', 'like', '%'.$search.'%')));
        }

        return view('documents.queue', ['documents' => $query->orderByDesc('id')->paginate(15)->withQueryString(), 'expiry' => $expiry, 'filters' => $filters]);
    }

    public function missing(Request $request): View
    {
        $request->validate(['search' => ['nullable', 'string', 'max:255'], 'document_type_id' => ['nullable', 'integer', 'exists:document_types,id']]);
        $ids = [];
        $engine = app(DocumentRequirementEngine::class);
        $query = Application::with(['candidate.person' => fn ($q) => $q->withTrashed(), 'vacancy', 'assignedCs', 'currentStage']);
        if ($search = trim((string) $request->input('search'))) {
            $query->where(fn ($q) => $q->where('application_code', 'like', '%'.$search.'%')->orWhereHas('candidate', fn ($candidate) => $candidate->where('candidate_code', 'like', '%'.$search.'%'))->orWhereHas('candidate.person', fn ($person) => $person->withTrashed()->where('full_name', 'like', '%'.$search.'%')));
        }
        (clone $query)->chunkById(100, function ($applications) use (&$ids, $engine, $request): void {
            foreach ($applications as $application) {
                if ($engine->rows($application)->contains(fn ($row) => $row['applies'] && $row['rule']->is_required && ! $row['valid'] && (! $request->filled('document_type_id') || $row['rule']->document_type_id == $request->integer('document_type_id')))) {
                    $ids[] = $application->id;
                }
            }
        });
        $applications = $query->whereIn('id', $ids)->latest('id')->paginate(15)->withQueryString();
        $missing = [];
        $groups = collect();
        foreach ($applications as $application) {
            $rows = $engine->rows($application)->filter(fn ($row) => $row['applies'] && $row['rule']->is_required && ! $row['valid'] && (! $request->filled('document_type_id') || $row['rule']->document_type_id == $request->integer('document_type_id')));
            $missing[$application->id] = $rows->pluck('rule');
            foreach ($rows as $row) {
                $groups->push([...$row, 'application' => $application]);
            }
        }

        return view('documents.missing', ['applications' => $applications, 'missing' => $missing, 'groups' => $groups->groupBy(fn ($row) => $row['rule']->document_type_id), 'types' => DocumentType::whereIn('id', $groups->map(fn ($row) => $row['rule']->document_type_id)->unique())->orderBy('name')->get(), 'templates' => DB::table('whatsapp_templates')->where('status', 'active')->get()]);
    }

    public function missingCount(?object $applicationIds = null): int
    {
        $missing = 0;
        Application::when($applicationIds !== null, fn ($q) => $q->whereIn('id', $applicationIds))->chunkById(100, function ($applications) use (&$missing): void {
            foreach ($applications as $application) {
                $missing += app(DocumentRequirementEngine::class)->rows($application)->filter(fn ($row) => $row['applies'] && $row['rule']->is_required && ! $row['valid'])->count();
            }
        });

        return $missing;
    }

    private function documentsFor(Application $application, ?Collection $pool = null): Collection
    {
        if ($pool !== null) {
            return $pool->filter(fn ($doc) => $doc->candidate_id === $application->candidate_id && ($doc->application_id === null || $doc->application_id === $application->id))->unique('document_type_id')->keyBy('document_type_id');
        }

        return CandidateDocument::where('candidate_id', $application->candidate_id)->where(fn ($q) => $q->where('application_id', $application->id)->orWhereNull('application_id'))->with(['documentType', 'verifier'])->orderByRaw('application_id IS NULL')->orderByDesc('version')->orderByDesc('id')->get()->unique('document_type_id')->keyBy('document_type_id');
    }

    public function show(Application $application): View
    {
        $engine = app(DocumentRequirementEngine::class);
        $rows = $engine->rows($application);

        return view('documents.show', ['application' => $application->load(['candidate.person' => fn ($q) => $q->withTrashed(), 'vacancy', 'currentStage']), 'requirements' => $rows->pluck('rule'), 'documents' => $engine->documents($application), 'rows' => $rows->keyBy(fn ($row) => $row['rule']->source_key), 'readiness' => $engine->summary($application), 'exceptions' => DB::table('document_exceptions')->where('application_id', $application->id)->latest('id')->get()]);
    }

    public function upload(UploadDocumentRequest $request, Application $application): RedirectResponse
    {
        app(DocumentScanner::class)->inspect($request->file('file'));
        $path = $request->file('file')->store('', 'documents');
        try {
            DB::transaction(function () use ($request, $application, $path): void {
                $locked = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
                $vacancy = Vacancy::withTrashed()->whereKey($locked->vacancy_id)->lockForUpdate()->firstOrFail();
                if (! app(DocumentRequirementEngine::class)->requirements($locked)->contains('document_type_id', $request->validated('document_type_id'))) {
                    throw ValidationException::withMessages(['document_type_id' => 'Kebutuhan dokumen telah berubah. Muat ulang halaman.']);
                }
                $data = collect($request->validated())->except('file')->all();
                $version = CandidateDocument::withTrashed()->where('candidate_id', $locked->candidate_id)->where('application_id', $locked->id)->where('document_type_id', $data['document_type_id'])->max('version') ?? 0;
                CandidateDocument::where('candidate_id', $locked->candidate_id)->where('application_id', $locked->id)->where('document_type_id', $data['document_type_id'])->update(['status' => 'superseded']);
                DB::table('document_actions')->where('application_id', $locked->id)->where('document_type_id', $data['document_type_id'])->update(['owner' => 'INTERNAL_REVIEW', 'assigned_to' => $locked->assigned_cs_id, 'due_at' => now()->addHours(config('health-documents.review_sla_hours', 48)), 'updated_at' => now()]);
                CandidateDocument::create([...$data, 'candidate_id' => $locked->candidate_id, 'application_id' => $locked->id, 'file_path' => $path, 'version' => $version + 1, 'status' => 'uploaded', 'uploaded_by' => $request->user()->id, 'action_assigned_to' => $locked->assigned_cs_id, 'action_due_at' => now()->addHours(config('health-documents.review_sla_hours', 48))]);
                if (in_array($locked->status, ['ready', 'ready_for_departure', 'departure_ready'], true) && ! $locked->isReadyForDeparture()) {
                    $locked->update(['status' => 'active']);
                }
            });
        } catch (\Throwable $exception) {
            Storage::disk('documents')->delete($path);
            throw $exception;
        }

        return to_route('documents.show', $application)->with('success', 'Dokumen berhasil diunggah. Versi sebelumnya tetap tersimpan.');
    }

    public function review(ReviewDocumentRequest $request, CandidateDocument $document): RedirectResponse
    {
        DB::transaction(function () use ($request, $document): void {
            if ($document->application_id) {
                Application::withTrashed()->whereKey($document->application_id)->lockForUpdate()->firstOrFail();
            }
            $locked = CandidateDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $latest = CandidateDocument::where('candidate_id', $locked->candidate_id)->where('application_id', $locked->application_id)->where('document_type_id', $locked->document_type_id)->latestVersions()->first();
            if (! $latest || $latest->id !== $locked->id) {
                throw ValidationException::withMessages(['status' => 'Versi dokumen sudah berubah. Periksa versi terbaru.']);
            }
            $status = $request->validated('status');
            if ($locked->effectiveStatus() === 'expired' && ! in_array($status, ['rejected', 'needs_correction'], true)) {
                throw ValidationException::withMessages(['status' => 'Dokumen kedaluwarsa harus diunggah ulang.']);
            }
            if ($status === 'reviewed' && $locked->status !== 'uploaded') {
                throw ValidationException::withMessages(['status' => 'Hanya dokumen yang diunggah dapat mulai ditinjau.']);
            }
            if ($status === 'verified' && $locked->status !== 'reviewed') {
                throw ValidationException::withMessages(['status' => 'Tinjau dokumen sebelum verifikasi.']);
            }
            if (! $locked->file_path || ! Storage::disk('documents')->exists($locked->file_path)) {
                throw ValidationException::withMessages(['status' => 'File dokumen tidak tersedia. Unggah ulang file.']);
            }
            $reasonCode = $request->validated('correction_code');
            $safeReason = $reasonCode ? ($reasonCode === 'OTHER' ? $request->validated('rejection_reason') : DocumentRequirementEngine::REASONS[$reasonCode]) : $request->validated('rejection_reason');
            $fromStatus = $locked->status;
            if ($status === 'verified' && $locked->documentType->has_expiry && ! $locked->expires_at && $locked->application && app(DocumentRequirementEngine::class)->requirements($locked->application)->contains(fn ($rule) => $rule->document_type_id === $locked->document_type_id && str_starts_with($rule->source_key, 'rule-') && $rule->expiry_policy === 'VALID')) {
                throw ValidationException::withMessages(['status' => 'Tanggal kedaluwarsa wajib tersedia untuk jenis dokumen ini. Unggah ulang data dokumen.']);
            }
            $locked->update(['correction_code' => $reasonCode, 'action_owner' => in_array($status, ['needs_correction', 'rejected'], true) ? 'CPMI_ACTION' : 'INTERNAL_REVIEW', 'status' => $status, 'rejection_reason' => in_array($status, ['rejected', 'needs_correction'], true) ? $safeReason : null, 'verified_by' => $status === 'verified' ? $request->user()->id : null, 'verified_at' => $status === 'verified' ? now() : null]);
            if ($locked->application_id && in_array($locked->application?->status, ['ready', 'ready_for_departure', 'departure_ready'], true) && ! $locked->application->isReadyForDeparture()) {
                $locked->application->update(['status' => 'active']);
            }
            if ($locked->application_id) {
                DB::table('document_actions')->where('application_id', $locked->application_id)->where('document_type_id', $locked->document_type_id)->update(['owner' => in_array($status, ['needs_correction', 'rejected'], true) ? 'CPMI_ACTION' : 'INTERNAL_REVIEW', 'updated_at' => now()]);
            }
            DB::table('document_verification_events')->insert(['candidate_document_id' => $locked->id, 'from_status' => $fromStatus, 'to_status' => $status, 'reason_code' => $reasonCode, 'safe_reason' => in_array($status, ['rejected', 'needs_correction'], true) ? $safeReason : null, 'performed_by' => $request->user()->id, 'performed_at' => now()]);
            if (DB::table('portal_accounts')->where('candidate_id', $locked->candidate_id)->where('status', 'active')->where('in_app_notifications', true)->exists()) {
                DB::table('portal_notifications')->insert(['candidate_id' => $locked->candidate_id, 'category' => 'DOCUMENT_UPDATE', 'title' => 'Status dokumen diperbarui', 'body_safe' => match ($status) {
                    'needs_correction' => 'Dokumen Anda perlu diperbaiki. Buka Dokumen untuk petunjuk pengunggahan ulang.', 'verified' => 'Dokumen Anda telah diverifikasi.','rejected' => 'Dokumen perlu diperbaiki. Hubungi CS untuk petunjuk pengunggahan ulang.',default => 'Dokumen Anda sedang ditinjau.'
                }, 'deep_link' => '/portal/documents', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return back()->with('success', 'Status dokumen berhasil diperbarui.');
    }

    public function download(CandidateDocument $document): StreamedResponse
    {
        abort_unless($document->file_path && preg_match('/^[a-zA-Z0-9_-]+\.(pdf|jpg|jpeg|png)$/', $document->file_path) && Storage::disk('documents')->exists($document->file_path), 404);

        return Storage::disk('documents')->download($document->file_path, 'dokumen-'.$document->id.'.'.pathinfo($document->file_path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
