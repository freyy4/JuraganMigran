<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Task;
use App\Models\User;
use App\Services\AccessPolicy;
use App\Services\CsRouter;
use App\Services\ProcessCockpit;
use App\Services\ProcessStageTransition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcessActionController extends Controller
{
    public function bulk(Request $request): RedirectResponse|JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['assign', 'move', 'task', 'next', 'priority', 'message', 'export'])],
            'applications' => ['required', 'array', 'min:1', 'max:100'], 'applications.*' => ['required', 'integer', 'distinct'],
            'confirmed' => ['required', 'accepted'],
            'assigned_cs_id' => ['required_if:action,assign', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'to_stage_id' => ['required_if:action,move', 'nullable', 'integer', Rule::exists('pipeline_stages', 'id')->where('is_active', true)],
            'from_stages' => ['required_if:action,move', 'array'], 'from_stages.*' => ['nullable', 'integer'],
            'reason' => ['required_if:action,assign,move', 'nullable', 'string', 'min:5', 'max:255'],
            'title' => ['required_if:action,task', 'nullable', 'string', 'max:255'],
            'due_at' => ['required_if:action,task', 'nullable', 'date_format:Y-m-d\TH:i'],
            'priority' => ['required_if:action,priority', 'nullable', Rule::in(array_keys(ProcessCockpit::PRIORITIES))],
            'template_id' => ['required_if:action,message', 'nullable', 'integer', Rule::exists('whatsapp_templates', 'id')->where('status', 'active')],
            'next_action' => ['nullable', 'string', 'max:255'],
            'waiting_status' => ['nullable', Rule::in(['WAITING_CANDIDATE', 'WAITING_INTERNAL', 'WAITING_EXTERNAL'])],
            'next_action_due_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'batch_key' => ['required_if:action,message', 'nullable', 'uuid'],
        ]);
        $permission = match ($data['action']) {
            'assign' => 'candidate.reassign', 'move' => 'candidate.update.stage', 'export' => 'report.export', default => 'process.update',
        };
        $policy = app(AccessPolicy::class);
        abort_unless($policy->has($request->user(), $permission), 403);
        if ($data['action'] === 'message') {
            abort_unless($policy->has($request->user(), 'candidate.read.contact'), 403);
        }
        if ($data['action'] === 'assign') {
            $all = collect($policy->policies($request->user(), $permission))->contains(fn ($p) => $p['scope'] === 'OPERATIONAL_ALL');
            abort_unless($all || ($request->user()->team_id && User::whereKey($data['assigned_cs_id'])->where('team_id', $request->user()->team_id)->exists()), 403);
        }
        $allowed = Application::whereIn('id', $data['applications'])->whereIn('id', $policy->applicationIds($request->user(), $permission))->whereIn('id', $policy->applicationIds($request->user(), 'process.read'))->get()->keyBy('id');
        if ($data['action'] === 'export') {
            abort_unless($allowed->count() === count($data['applications']), 403);
            $rows = app(ProcessCockpit::class)->rows($request->user(), [], 'report.export')->whereIn('row.id', $data['applications'])->get();
            $policy->audit($request->user(), 'PROCESS_BULK_ACTION', ['action' => 'export', 'count' => $rows->count()]);

            return response()->streamDownload(function () use ($rows): void {
                $file = fopen('php://output', 'w');
                fwrite($file, "\xEF\xBB\xBF");
                fputcsv($file, ['ID Aplikasi', 'Nama CPMI', 'Lowongan', 'Negara', 'Mitra', 'CS', 'Tahap', 'Prioritas', 'Status', 'Deadline'], ',', '"', '');
                foreach ($rows as $row) {
                    $row = app(ProcessCockpit::class)->decorate($row);
                    $values = [$row->application_code, $row->candidate_name, $row->vacancy_label, $row->country_label, $row->company_label, $row->cs_name, $row->stage_label, $row->priority, $row->operational_status, $row->due_label];
                    fputcsv($file, array_map(fn ($value) => preg_match('/^[\s]*[=+@\-]/u', (string) $value) ? "'".$value : $value, $values), ',', '"', '');
                }
                fclose($file);
            }, 'alur-proses.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }
        $results = [];
        foreach ($data['applications'] as $id) {
            if (! isset($allowed[$id])) {
                $results[] = ['id' => $id, 'success' => false, 'message' => 'Lamaran tidak tersedia dalam scope tindakan.'];

                continue;
            }
            try {
                DB::transaction(function () use ($request, $data, $id, $policy, $permission): void {
                    $application = Application::whereKey($id)->lockForUpdate()->firstOrFail();
                    abort_unless($policy->applicationIds($request->user(), $permission)->where('applications.id', $id)->exists(), 403);
                    if (in_array($application->status, ['closed', 'cancelled', 'withdrawn', 'rejected', 'completed', 'departed'], true)) {
                        throw ValidationException::withMessages(['application' => 'Lamaran sudah ditutup. Muat ulang daftar.']);
                    }
                    switch ($data['action']) {
                        case 'move':
                            if (! array_key_exists($id, $data['from_stages'])) {
                                throw ValidationException::withMessages(['from_stages' => 'Tahap asal tidak dikirim.']);
                            }
                            app(ProcessStageTransition::class)->move($request->user(), $application, ['from_stage_id' => $data['from_stages'][$id], 'to_stage_id' => $data['to_stage_id'], 'reason' => $data['reason']]);
                            break;
                        case 'assign':
                            $application->update(['assigned_cs_id' => $data['assigned_cs_id']]);
                            $member = DB::table('cs_members')->where('user_id', $data['assigned_cs_id'])->where('is_active', true)->value('id');
                            DB::table('cs_assignment_events')->insert(['application_id' => $id, 'member_id' => $member, 'status' => 'reassigned', 'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now()]);
                            break;
                        case 'task':
                            Task::create(['application_id' => $id, 'assigned_to' => $application->assigned_cs_id ?? $request->user()->id, 'title' => $data['title'], 'task_type' => 'follow_up', 'priority' => 'normal', 'status' => 'open', 'due_at' => Carbon::createFromFormat('Y-m-d\TH:i', $data['due_at'], Task::TIMEZONE)->setTimezone(config('app.timezone')), 'created_by' => $request->user()->id]);
                            break;
                        case 'next':
                            if (empty($data['next_action']) && empty($data['waiting_status'])) {
                                throw ValidationException::withMessages(['next_action' => 'Isi tindakan berikutnya atau pilih alasan menunggu.']);
                            }
                            $application->update(['next_action' => $data['next_action'] ?? null, 'waiting_status' => $data['waiting_status'] ?? null, 'next_action_due_at' => empty($data['next_action_due_at']) ? null : Carbon::createFromFormat('Y-m-d\TH:i', $data['next_action_due_at'], Task::TIMEZONE)->setTimezone(config('app.timezone'))]);
                            break;
                        case 'priority': $application->update(['process_priority' => $data['priority']]);
                            break;
                        case 'message': $this->draft($request, $application, $data);
                            break;
                    }
                });
                $results[] = ['id' => $id, 'success' => true, 'message' => 'Berhasil'];
            } catch (ValidationException $exception) {
                // Domain error details may contain document names; keep the cockpit response safe.
                $results[] = ['id' => $id, 'success' => false, 'message' => $data['action'] === 'move' ? 'Validasi tahap gagal atau tahap berubah. Periksa Candidate 360.' : collect($exception->errors())->flatten()->first()];
            }
        }
        $policy->audit($request->user(), 'PROCESS_BULK_ACTION', ['action' => $data['action'], 'results' => collect($results)->map(fn ($r) => ['id' => $r['id'], 'success' => $r['success']])->all()]);
        if ($request->expectsJson()) {
            return response()->json(['results' => $results]);
        }
        $success = collect($results)->where('success', true)->count();

        return to_route('pipeline.index')->with('success', $success.' dari '.count($results).' lamaran berhasil diproses.')->with('process_results', $results);
    }

    private function draft(Request $request, Application $application, array $data): void
    {
        $contactIds = app(AccessPolicy::class)->applicationIds($request->user(), 'candidate.read.contact');
        if (! $contactIds->where('applications.id', $application->id)->exists()) {
            throw ValidationException::withMessages(['contact' => 'Tidak memiliki scope kontak lamaran ini.']);
        }
        $template = DB::table('whatsapp_templates')->where('id', $data['template_id'])->where('status', 'active')->lockForUpdate()->first();
        if (! $template || ($template->vacancy_id && (int) $template->vacancy_id !== (int) $application->vacancy_id)) {
            throw ValidationException::withMessages(['template' => 'Template tidak sesuai lowongan.']);
        }
        $consented = DB::table('lead_submissions')->where('application_id', $application->id)->whereNotNull('consented_at')->where('is_test', false)->exists()
            || DB::table('portal_accounts')->where('candidate_id', $application->candidate_id)->whereNotNull('consented_at')->exists();
        if (! $consented) {
            throw ValidationException::withMessages(['consent' => 'Persetujuan komunikasi belum tercatat untuk lamaran ini.']);
        }
        preg_match_all('/\{\{?\s*([a-zA-Z0-9_]+)\s*\}\}?/', $template->body, $matches);
        if (array_diff($matches[1], ['name', 'cs_name', 'portal_url'])) {
            throw ValidationException::withMessages(['template' => 'Template proses hanya mendukung name, cs_name, portal_url.']);
        }
        $message = preg_replace_callback('/\{\{?\s*([a-zA-Z0-9_]+)\s*\}\}?/', fn ($match) => ['name' => $application->candidate->person->full_name, 'cs_name' => $application->assignedCs?->name ?? $request->user()->name, 'portal_url' => url('/portal/process')][$match[1]], $template->body);
        DB::table('process_message_drafts')->insertOrIgnore(['application_id' => $application->id, 'created_by' => $request->user()->id, 'template_id' => $template->id, 'batch_key' => $data['batch_key'], 'safe_message' => $message, 'status' => 'manual_ready', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function message(Request $request, int $draft): RedirectResponse
    {
        $policy = app(AccessPolicy::class);
        abort_unless($request->user()->hasPermission('candidate.read.contact'), 403);
        $record = DB::table('process_message_drafts')->where('created_by', $request->user()->id)->where('id', $draft)->whereIn('application_id', $policy->applicationIds($request->user(), 'process.update'))->whereIn('application_id', $policy->applicationIds($request->user(), 'candidate.read.contact'))->first() ?? abort(404);
        $application = Application::findOrFail($record->application_id);
        $phone = CsRouter::phone($application->candidate->person->whatsapp ?? '');
        abort_unless(preg_match('/^\+?[0-9]{8,15}$/', $phone), 422);
        DB::table('process_message_drafts')->where('id', $draft)->update(['status' => 'whatsapp_opened', 'updated_at' => now()]);
        DB::table('followup_events')->insert(['application_id' => $application->id, 'user_id' => $request->user()->id, 'status' => 'DRAFT_OPENED', 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->away('https://wa.me/'.ltrim($phone, '+').'?text='.rawurlencode($record->safe_message));
    }

    public function update(Request $request, Application $application): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'process_priority' => ['sometimes', 'nullable', Rule::in(array_keys(ProcessCockpit::PRIORITIES))],
            'next_action' => ['sometimes', 'nullable', 'string', 'max:255'],
            'next_action_due_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i'],
            'waiting_status' => ['sometimes', 'nullable', Rule::in(['WAITING_CANDIDATE', 'WAITING_INTERNAL', 'WAITING_EXTERNAL'])],
        ]);
        abort_unless(app(AccessPolicy::class)->applicationIds($request->user(), 'process.update')->where('applications.id', $application->id)->exists(), 403);
        if (array_key_exists('next_action_due_at', $data)) {
            $data['next_action_due_at'] = $data['next_action_due_at'] ? Carbon::createFromFormat('Y-m-d\TH:i', $data['next_action_due_at'], Task::TIMEZONE)->setTimezone(config('app.timezone')) : null;
        }
        $application->update($data);

        return $request->expectsJson() ? response()->json(['id' => $application->id, 'updated' => true]) : to_route('pipeline.index')->with('success', 'Tindakan berikutnya diperbarui.');
    }

    public function event(Request $request): JsonResponse
    {
        $data = $request->validate(['event' => ['required', Rule::in(['PROCESS_ROW_OPENED', 'PROCESS_STAGE_FILTER_CLICKED', 'PROCESS_PRIORITY_OPENED'])], 'application_id' => ['nullable', 'integer'], 'stage_id' => ['nullable', 'integer', 'exists:pipeline_stages,id']]);
        if (! empty($data['application_id'])) {
            abort_unless(app(AccessPolicy::class)->applicationIds($request->user(), 'process.read')->where('applications.id', $data['application_id'])->exists(), 403);
        }
        app(AccessPolicy::class)->audit($request->user(), $data['event'], array_diff_key($data, ['event' => true]));

        return response()->json(['recorded' => true]);
    }

    public function contact(Request $request, Application $application): RedirectResponse
    {
        abort_unless(app(AccessPolicy::class)->applicationIds($request->user(), 'candidate.read.contact')->where('applications.id', $application->id)->exists(), 403);
        $phone = CsRouter::phone($application->candidate->person->phone ?: ($application->candidate->person->whatsapp ?? ''));
        abort_unless(preg_match('/^\+?[0-9]{8,15}$/', $phone), 422);

        return redirect()->away('tel:'.$phone);
    }

    public function savedView(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $filters = app(ProcessCockpit::class)->filters($request);
        unset($filters['page']);
        DB::table('process_user_views')->updateOrInsert(['user_id' => $request->user()->id, 'name' => $data['name']], ['filters' => json_encode($filters), 'created_at' => now(), 'updated_at' => now()]);

        return to_route('pipeline.index', $filters)->with('success', 'Tampilan tersimpan untuk akun Anda.');
    }

    public function deleteView(Request $request, int $view): RedirectResponse
    {
        DB::table('process_user_views')->where('id', $view)->where('user_id', $request->user()->id)->delete();

        return to_route('pipeline.index')->with('success', 'Tampilan tersimpan dihapus.');
    }
}
