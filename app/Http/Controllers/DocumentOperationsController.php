<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\CandidateDocument;
use App\Services\AccessPolicy;
use App\Services\CsRouter;
use App\Services\DocumentRequirementEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentOperationsController extends Controller
{
    public function queueData(): array
    {
        $queue = collect();
        $counts = ['Menunggu Verifikasi' => 0, 'Perlu CPMI Lengkapi' => 0, 'Perlu Perbaikan' => 0, 'Akan Kedaluwarsa' => 0, 'Dokumen Asli Keluar / Belum Kembali' => 0, 'Kritis Sebelum Keberangkatan' => 0];
        Application::with(['candidate.person' => fn ($q) => $q->withTrashed(), 'vacancy.jobOrder.country', 'assignedCs', 'currentStage'])->chunkById(100, function ($applications) use (&$queue, &$counts): void {
            foreach ($applications as $application) {
                $flight = DB::table('flight_details')->where('application_id', $application->id)->where('departure_at', '>=', now())->orderBy('departure_at')->first();
                foreach (app(DocumentRequirementEngine::class)->rows($application) as $row) {
                    if (! $row['applies']) {
                        continue;
                    }
                    $status = $row['status'];
                    if (in_array($status, ['uploaded', 'reviewed'], true)) {
                        $counts['Menunggu Verifikasi']++;
                    }
                    if ($row['rule']->is_required && in_array($status, ['missing', 'rejected', 'expired'], true)) {
                        $counts['Perlu CPMI Lengkapi']++;
                    }
                    if ($status === 'needs_correction') {
                        $counts['Perlu Perbaikan']++;
                    }
                    if ($status === 'expiring_soon') {
                        $counts['Akan Kedaluwarsa']++;
                    }
                    $critical = $flight && Carbon::parse($flight->departure_at)->lte(now()->addDays(7)) && $row['rule']->is_required && ! $row['verified'];
                    if ($critical) {
                        $counts['Kritis Sebelum Keberangkatan']++;
                    }
                    if (! $row['verified'] && ($row['rule']->is_required || $row['document'])) {
                        $queue->push([...$row, 'application' => $application, 'critical' => $critical, 'priority' => $critical ? 'Kritis' : ($row['overdue'] ? 'Terlambat' : 'Normal')]);
                        if ($queue->count() > 60) {
                            $queue = $queue->sortByDesc(fn ($r) => ($r['critical'] ? 100 : 0) + ($r['overdue'] ? 50 : 0))->take(30)->values();
                        }
                    }
                }
            }
        });
        $ids = app(AccessPolicy::class)->applicationIds(auth()->user(), request()->attributes->get('access_permission', 'document.read.metadata'));
        $counts['Dokumen Asli Keluar / Belum Kembali'] = DB::table('original_document_custodies')->whereIn('application_id', $ids)->whereIn('custody_state', ['HANDED_TO_PT', 'HELD_BY_PT', 'TEMPORARILY_RELEASED', 'RETURN_REQUESTED', 'RETURN_APPROVED', 'RETURN_SCHEDULED'])->count();

        return ['counts' => $counts, 'queue' => $queue->sortByDesc(fn ($r) => ($r['critical'] ? 100 : 0) + ($r['overdue'] ? 50 : 0))->take(20)->values()];
    }

    public function workspace(CandidateDocument $document): View
    {
        $application = $document->application;
        $context = $application ? app(DocumentRequirementEngine::class)->rows($application)->first(fn ($row) => $row['rule']->document_type_id === $document->document_type_id) : null;

        return view('documents.workspace', ['document' => $document->load(['documentType', 'candidate.person', 'application.vacancy.jobOrder.country', 'application.currentStage', 'application.assignedCs']), 'context' => $context, 'versions' => CandidateDocument::where('candidate_id', $document->candidate_id)->where('application_id', $document->application_id)->where('document_type_id', $document->document_type_id)->orderByDesc('version')->get(), 'events' => DB::table('document_verification_events as event')->leftJoin('users', 'users.id', '=', 'event.performed_by')->where('candidate_document_id', $document->id)->select('event.*', 'users.name as performer')->orderByDesc('event.id')->get()]);
    }

    public function preview(CandidateDocument $document): StreamedResponse
    {
        abort_unless($document->file_path && preg_match('/^[a-zA-Z0-9_-]+\.(pdf|jpg|jpeg|png)$/', $document->file_path) && Storage::disk('documents')->exists($document->file_path), 404);

        return Storage::disk('documents')->response($document->file_path, 'dokumen.'.pathinfo($document->file_path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'"]);
    }

    public function owner(Request $request, CandidateDocument $document): RedirectResponse
    {
        $data = $request->validate(['action_owner' => ['required', Rule::in(array_keys(DocumentRequirementEngine::OWNERS))], 'action_assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)], 'action_due_at' => ['nullable', 'date_format:Y-m-d\TH:i']]);
        if ($data['action_assigned_to'] ?? null) {
            $user = DB::table('users')->find($data['action_assigned_to']);
            $all = collect(app(AccessPolicy::class)->policies($request->user(), 'document.upload'))->contains('scope', 'OPERATIONAL_ALL');
            if (! $all && $user->id !== $request->user()->id && (! $request->user()->team_id || $user->team_id !== $request->user()->team_id)) {
                abort(403);
            }
        }
        $data['action_due_at'] = empty($data['action_due_at']) ? null : Carbon::createFromFormat('Y-m-d\TH:i', $data['action_due_at'], 'Asia/Jakarta')->timezone(config('app.timezone'));
        $document->update($data);

        return back()->with('success', 'Pemilik tindakan dan deadline diperbarui. Waktu pihak eksternal tidak dihitung sebagai keterlambatan CS.');
    }

    public function action(Request $request, Application $application): RedirectResponse
    {
        $data = $request->validate(['document_type_id' => ['required', 'integer'], 'owner' => ['required', Rule::in(array_keys(DocumentRequirementEngine::OWNERS))], 'due_at' => ['nullable', 'date_format:Y-m-d\TH:i'], 'reason' => ['required', 'string', 'max:2000']]);
        abort_unless(app(DocumentRequirementEngine::class)->requirements($application)->contains('document_type_id', $data['document_type_id']), 422);
        DB::table('document_actions')->updateOrInsert(['application_id' => $application->id, 'document_type_id' => $data['document_type_id']], ['owner' => $data['owner'], 'assigned_to' => $application->assigned_cs_id, 'due_at' => empty($data['due_at']) ? null : Carbon::createFromFormat('Y-m-d\TH:i', $data['due_at'], 'Asia/Jakarta')->timezone(config('app.timezone')), 'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Tindakan dokumen dicatat. Pihak eksternal tidak dihitung sebagai CS overdue.');
    }

    public function exception(Request $request, Application $application): RedirectResponse
    {
        $data = $request->validate(['document_type_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000'], 'valid_until' => ['required', 'date_format:Y-m-d\TH:i']]);
        abort_unless(app(DocumentRequirementEngine::class)->requirements($application)->contains('document_type_id', $data['document_type_id']), 422);
        DB::table('document_exceptions')->insert([...$data, 'application_id' => $application->id, 'stage_id' => $application->current_stage_id, 'valid_until' => Carbon::createFromFormat('Y-m-d\TH:i', $data['valid_until'], 'Asia/Jakarta')->timezone(config('app.timezone')), 'requested_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Pengecualian diajukan. Pengaman tahap tetap berlaku sampai disetujui supervisor.');
    }

    public function approveException(Request $request, Application $application, int $exception): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);
        DB::transaction(function () use ($data, $application, $exception, $request): void {
            $row = DB::table('document_exceptions')->where('id', $exception)->where('application_id', $application->id)->lockForUpdate()->first();
            abort_unless($row && $row->status === 'requested' && $row->requested_by !== $request->user()->id && Carbon::parse($row->valid_until)->isFuture(), 422);
            DB::table('document_exceptions')->where('id', $exception)->update(['status' => $data['decision'], 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Keputusan pengecualian dicatat.');
    }

    public function reminder(Request $request): RedirectResponse
    {
        $data = $request->validate(['applications' => ['required', 'array', 'min:1', 'max:100'], 'applications.*' => ['required', 'integer', 'distinct'], 'document_type_id' => ['required', 'integer', 'exists:document_types,id'], 'template_id' => ['required', 'integer', Rule::exists('whatsapp_templates', 'id')->where('status', 'active')], 'batch_key' => ['required', 'uuid']]);
        $ids = app(AccessPolicy::class)->applicationIds($request->user(), 'document.followup')->pluck('applications.id')->all();
        abort_if(array_diff($data['applications'], $ids), 403);
        DB::transaction(function () use ($data, $request): void {
            $template = DB::table('whatsapp_templates')->where('id', $data['template_id'])->where('status', 'active')->lockForUpdate()->first();
            abort_unless($template, 422);
            preg_match_all('/\{\{?\s*([a-zA-Z0-9_]+)\s*\}\}?/', $template->body, $matches);
            if (array_diff($matches[1], ['name', 'cs_name', 'portal_url'])) {
                throw ValidationException::withMessages(['template_id' => 'Reminder dokumen hanya mengizinkan nama, cs_name dan portal_url. Data kesehatan/identitas tidak boleh dimasukkan.']);
            }
            foreach (Application::whereIn('id', $data['applications'])->lockForUpdate()->get() as $application) {
                abort_unless($application->assigned_cs_id && (! $template->vacancy_id || $template->vacancy_id === $application->vacancy_id), 422);
                $row = app(DocumentRequirementEngine::class)->rows($application)->first(fn ($row) => $row['rule']->document_type_id == $data['document_type_id'] && $row['applies'] && $row['rule']->is_required && in_array($row['status'], ['missing', 'needs_correction', 'rejected', 'expired'], true) && $row['owner'] !== 'EXTERNAL_DEPENDENCY');
                if (! $row) {
                    throw ValidationException::withMessages(['applications' => 'Pilihan tidak lagi sesuai: hanya dokumen kurang/perlu perbaikan yang dapat diingatkan. Muat ulang daftar.']);
                }
                $message = preg_replace_callback('/\{\{?\s*([a-zA-Z0-9_]+)\s*\}\}?/', fn ($match) => ['name' => $application->candidate->person->full_name, 'cs_name' => $application->assignedCs?->name, 'portal_url' => url('/portal/documents')][$match[1]], $template->body);
                DB::table('document_followups')->insertOrIgnore(['application_id' => $application->id, 'document_type_id' => $data['document_type_id'], 'whatsapp_template_id' => $template->id, 'safe_message' => $message, 'batch_key' => $data['batch_key'], 'created_by' => $request->user()->id, 'status' => 'manual_ready', 'created_at' => now()]);
            }
        });

        return to_route('document-operations.followups')->with('success', 'Reminder disiapkan menggunakan template aktif. Buka WhatsApp untuk mengirim masing-masing pesan.');
    }

    public function followups(): View
    {
        $records = DB::table('document_followups')->whereIn('application_id', app(AccessPolicy::class)->applicationIds(auth()->user(), 'document.followup'))->orderByDesc('id')->paginate(15);

        return view('documents.followups', ['records' => $records]);
    }

    public function whatsapp(Request $request, int $followup): RedirectResponse
    {
        $row = DB::table('document_followups')->where('id', $followup)->whereIn('application_id', app(AccessPolicy::class)->applicationIds($request->user(), 'document.followup'))->first() ?? abort(404);
        $application = Application::findOrFail($row->application_id);
        $phone = CsRouter::phone($application->candidate->person->whatsapp ?? '');
        abort_unless(preg_match('/^\+?[0-9]{8,15}$/', $phone), 422);
        DB::transaction(function () use ($row, $request): void {
            DB::table('document_followups')->where('id', $row->id)->update(['status' => 'whatsapp_opened']);
            DB::table('followup_events')->insert(['application_id' => $row->application_id, 'user_id' => $request->user()->id, 'status' => 'document_reminder_whatsapp_opened', 'created_at' => now(), 'updated_at' => now()]);
        });

        return redirect()->away('https://wa.me/'.ltrim($phone, '+').'?text='.rawurlencode($row->safe_message));
    }
}
