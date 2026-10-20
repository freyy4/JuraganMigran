<?php

namespace App\Http\Controllers;

use App\Models\PipelineStage;
use App\Models\Vacancy;
use App\Services\AccessPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MasterWorkflowController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['selected' => ['nullable', 'integer', 'exists:process_workflows,id'], 'search' => ['nullable', 'string', 'max:255'], 'vacancy_search' => ['nullable', 'string', 'max:100']]);
        $workflow = ! empty($data['selected']) ? DB::table('process_workflows')->find($data['selected']) : null;

        return view('master-data.workflows', ['workflows' => DB::table('process_workflows')->when(! empty($data['search']), fn ($query) => $query->where('name', 'like', '%'.$data['search'].'%'))->orderBy('name')->paginate(25)->withQueryString(), 'workflow' => $workflow, 'schema' => $workflow ? json_decode($workflow->draft_schema, true) : null, 'stages' => PipelineStage::where('is_active', true)->orderBy('sequence')->orderBy('id')->get(), 'versions' => $workflow ? DB::table('process_workflow_versions')->where('process_workflow_id', $workflow->id)->orderByDesc('version')->limit(20)->get() : collect(), 'vacancies' => Vacancy::query()->when(! empty($data['vacancy_search']), fn ($query) => $query->where('title', 'like', '%'.$data['vacancy_search'].'%'))->orderBy('title')->limit(20)->get(['id', 'title', 'workflow_version_id'])]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => ['nullable', 'integer', 'exists:process_workflows,id'], 'draft_token' => ['nullable', 'string', 'max:64'], 'name' => ['required', 'string', 'max:255'], 'stages' => ['required', 'array', 'min:1', 'max:100'], 'stages.*.id' => ['required', 'integer', 'distinct', 'exists:pipeline_stages,id'], 'stages.*.enabled' => ['required', 'boolean'], 'stages.*.sla_hours' => ['nullable', 'integer', 'min:0', 'max:87600'], 'stages.*.is_terminal' => ['required', 'boolean'], 'stages.*.required_checkpoint' => ['required', 'boolean'], 'stages.*.allowed_transitions' => ['nullable', 'array'], 'stages.*.allowed_transitions.*' => ['integer', 'distinct', 'exists:pipeline_stages,id']]);
        $rows = collect($data['stages'])->where('enabled', true)->values();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['stages' => 'Pilih minimal satu tahap.']);
        }
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $snapshot = $rows->map(function ($row, $index) use ($rows, $ids): array {
            $stage = PipelineStage::whereKey($row['id'])->where('is_active', true)->first();
            if (! $stage) {
                throw ValidationException::withMessages(['stages' => 'Tahap sudah tidak aktif. Tinjau ulang alur.']);
            }
            $allowed = array_map('intval', $row['allowed_transitions'] ?? []);
            if (! $row['is_terminal'] && $allowed === [] && isset($rows[$index + 1])) {
                $allowed = [(int) $rows[$index + 1]['id']];
            }
            if (array_diff($allowed, $ids) || in_array((int) $row['id'], $allowed, true) || $row['is_terminal'] && $allowed !== []) {
                throw ValidationException::withMessages(['stages' => 'Transisi harus menuju tahap lain dalam alur. Tahap akhir tidak memiliki transisi keluar.']);
            }

            return ['id' => $stage->id, 'code' => $stage->code, 'name' => $stage->name, 'sequence' => $index + 1, 'sla_hours' => $row['sla_hours'], 'is_terminal' => (bool) $row['is_terminal'], 'required_checkpoint' => (bool) $row['required_checkpoint'], 'allowed_transitions' => $allowed];
        })->all();
        $id = DB::transaction(function () use ($request, $data, $snapshot): int {
            $workflow = ! empty($data['id']) ? DB::table('process_workflows')->where('id', $data['id'])->lockForUpdate()->first() : null;
            if ($workflow && ! hash_equals(hash('sha256', $workflow->draft_schema), $data['draft_token'] ?? '')) {
                throw ValidationException::withMessages(['draft_token' => 'Draf telah berubah. Muat ulang sebelum menyimpan.']);
            }
            $values = ['name' => $data['name'], 'status' => 'draft', 'draft_schema' => json_encode(['stages' => $snapshot]), 'updated_at' => now()];
            if ($workflow) {
                DB::table('process_workflows')->where('id', $workflow->id)->update($values);
                $id = $workflow->id;
            } else {
                $id = DB::table('process_workflows')->insertGetId([...$values, 'created_at' => now()]);
            }
            app(AccessPolicy::class)->audit($request->user(), 'workflow.draft.saved', ['workflow_id' => $id]);

            return $id;
        });

        return to_route('master-workflows.index', ['selected' => $id])->with('success', 'Draf alur disimpan. Versi yang sedang digunakan tetap berlaku.');
    }

    public function publish(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => ['required', 'integer', 'exists:process_workflows,id'], 'draft_token' => ['required', 'string', 'size:64'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($request, $data): void {
            $workflow = DB::table('process_workflows')->where('id', $data['id'])->lockForUpdate()->first();
            if ($workflow->status !== 'draft' || ! hash_equals(hash('sha256', $workflow->draft_schema), $data['draft_token'])) {
                throw ValidationException::withMessages(['workflow' => 'Draf sudah berubah atau sudah diterbitkan. Tinjau kembali sebelum menerbitkan.']);
            }
            $snapshot = json_decode($workflow->draft_schema, true);
            foreach ($snapshot['stages'] as $stage) {
                if (! PipelineStage::whereKey($stage['id'])->where('is_active', true)->where('code', $stage['code'])->exists()) {
                    throw ValidationException::withMessages(['workflow' => 'Tahap berubah. Simpan dan tinjau ulang draf.']);
                }
            }
            $version = (DB::table('process_workflow_versions')->where('process_workflow_id', $workflow->id)->max('version') ?? 0) + 1;
            $versionId = DB::table('process_workflow_versions')->insertGetId(['process_workflow_id' => $workflow->id, 'version' => $version, 'snapshot' => $workflow->draft_schema, 'published_by' => $request->user()->id, 'published_at' => now()]);
            DB::table('process_workflows')->where('id', $workflow->id)->update(['status' => 'published', 'updated_at' => now()]);
            app(AccessPolicy::class)->audit($request->user(), 'workflow.version.published', ['workflow_id' => $workflow->id, 'version_id' => $versionId]);
        });

        return back()->with('success', 'Versi alur diterbitkan. Pilih lowongan untuk pemakaian pada lamaran baru.');
    }

    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate(['vacancy_id' => ['required', 'integer', 'exists:vacancies,id'], 'version_id' => ['required', 'integer', 'exists:process_workflow_versions,id'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($request, $data): void {
            $vacancy = Vacancy::whereKey($data['vacancy_id'])->lockForUpdate()->firstOrFail();
            $vacancy->workflow_version_id = $data['version_id'];
            $vacancy->save();
            app(AccessPolicy::class)->audit($request->user(), 'workflow.version.assigned', ['vacancy_id' => $vacancy->id, 'version_id' => $data['version_id']]);
        });

        return back()->with('success', 'Versi alur dipakai untuk lamaran baru. Lamaran yang sudah berjalan tidak berubah.');
    }
}
