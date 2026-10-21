<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccessPolicy;
use App\Services\ConversationCrm;
use App\Services\CrmLeadQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CrmPipelineWorkspaceController extends Controller
{
    public static function permission(string $action): string
    {
        return match ($action) {
            'export' => 'lead.export', 'assign' => 'crm.lead.assign', 'stage' => 'crm.pipeline.move',
            'task' => 'crm.followup.manage', 'tag' => 'crm.tag.use', default => 'crm.lead.edit',
        };
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate(['action' => 'required|in:assign,stage,task,tag,archive,export', 'idempotency_key' => 'required|uuid', 'selection' => 'required|in:ids,filtered', 'ids' => 'required_if:selection,ids|array|max:100', 'ids.*' => 'integer|distinct', 'filters' => 'nullable|array', 'payload' => 'required|array']);
        $permission = self::permission($data['action']);
        abort_unless($request->user()->hasPermission($permission), 403);
        $existing = DB::table('crm_bulk_jobs')->where('requested_by', $request->user()->id)->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return response()->json(['id' => $existing->id, 'status' => $existing->status]);
        }
        $filterRequest = Request::create('/', 'GET', $data['filters'] ?? []);
        $filterRequest->setUserResolver(fn () => $request->user());
        $query = app(CrmLeadQuery::class)->query($filterRequest, $permission);
        if ($data['selection'] === 'ids') {
            $query->whereIn('id', $data['ids']);
        }
        $ids = $query->orderBy('id')->limit(5001)->pluck('id')->all();
        abort_if(count($ids) > 5000, 422, 'Batasi satu operasi sampai 5.000 lead.');
        abort_if(! $ids, 422, 'Tidak ada lead dalam cakupan izin dan filter.');
        $payloadRequest = Request::create('/', 'POST', $data['payload']);
        $payload = $payloadRequest->validate(match ($data['action']) {
            'export' => ['format' => 'required|in:csv,xlsx', 'contacts' => 'nullable|boolean'],
            'assign' => ['user_id' => 'required|integer|exists:users,id'],
            'stage' => ['stage' => ['required', Rule::in(array_diff(array_keys(config('conversation.stages')), ['CONVERTED']))], 'reason' => 'nullable|string|max:255'],
            'tag' => ['tag_id' => 'required|integer|exists:crm_tags,id', 'remove' => 'nullable|boolean'],
            'task' => ['due_at' => 'required|date|after:now', 'note' => 'required|string|max:1000'],
            default => ['reason' => 'required|string|max:255'],
        });
        abort_if($data['action'] === 'export' && ($payload['contacts'] ?? false) && ! $request->user()->hasPermission('lead.read.contact'), 403);
        if ($data['action'] === 'task') {
            $payload['due_at'] = Carbon::parse($payload['due_at'])->utc()->toDateTimeString();
        }
        if ($data['action'] === 'assign') {
            $user = User::findOrFail($payload['user_id']);
            $all = collect(app(AccessPolicy::class)->policies($request->user(), $permission))->contains('scope', 'OPERATIONAL_ALL');
            abort_unless($user->is_active && $user->hasPermission('crm.conversation.reply') && ($all || ($request->user()->team_id && $user->team_id === $request->user()->team_id)), 403);
        }
        $id = (string) Str::uuid();
        DB::table('crm_bulk_jobs')->insertOrIgnore(['id' => $id, 'requested_by' => $request->user()->id, 'idempotency_key' => $data['idempotency_key'], 'action' => $data['action'], 'selection' => json_encode(['ids' => $ids, 'filters' => app(CrmLeadQuery::class)->filters($filterRequest)]), 'payload' => json_encode($payload), 'total_count' => count($ids), 'created_at' => now(), 'updated_at' => now()]);
        $job = DB::table('crm_bulk_jobs')->where('requested_by', $request->user()->id)->where('idempotency_key', $data['idempotency_key'])->first();
        app(AccessPolicy::class)->audit($request->user(), 'crm.bulk_job_created', ['job_id' => $job->id, 'action' => $job->action, 'count' => $job->total_count]);

        return response()->json(['id' => $job->id, 'status' => $job->status, 'total' => $job->total_count], 202);
    }

    public function job(Request $request, string $job): JsonResponse
    {
        $row = DB::table('crm_bulk_jobs')->where('id', $job)->where('requested_by', $request->user()->id)->first() ?? abort(404);
        $data = collect((array) $row)->only(['id', 'status', 'total_count', 'success_count', 'failure_count', 'completed_at'])->all();
        if ($row->action !== 'export') {
            $data['failures'] = collect(json_decode($row->result ?? '{}', true))->filter(fn ($item) => ! $item['ok'])->map(fn ($item, $id) => ['lead_id' => (int) $id, 'reason' => $item['reason']])->values()->take(100)->all();
        }
        if ($row->action === 'export' && $row->status === 'DONE' && $row->completed_at && now()->diffInHours(Carbon::parse($row->completed_at), true) < 24) {
            $data['download_url'] = URL::temporarySignedRoute('crm.export-download', now()->addMinutes(5), ['job' => $row->id]);
        }

        return response()->json($data);
    }

    public function download(Request $request, string $job): BinaryFileResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $row = DB::table('crm_bulk_jobs')->where('id', $job)->where('requested_by', $request->user()->id)->where('action', 'export')->where('status', 'DONE')->first() ?? abort(404);
        abort_unless($request->user()->hasPermission('lead.export'), 403);
        abort_if(now()->diffInHours(Carbon::parse($row->completed_at), true) >= 24, 410);
        $payload = json_decode($row->payload, true);
        abort_if(($payload['contacts'] ?? false) && ! $request->user()->hasPermission('lead.read.contact'), 403);
        $ids = json_decode($row->selection, true)['ids'];
        abort_unless(app(ConversationCrm::class)->leads($request, 'lead.export')->whereIn('id', $ids)->whereNull('archived_at')->count() === count($ids), 403, 'Cakupan lead berubah. Buat ekspor baru.');
        $path = json_decode($row->result, true)['path'];

        return response()->download(Storage::disk('local')->path($path), 'pipeline-lead.'.$payload['format'], ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function previewExport(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('lead.export'), 403);
        $data = $request->validate(['selection' => 'required|in:ids,filtered', 'ids' => 'nullable|array|max:100', 'ids.*' => 'integer|distinct', 'filters' => 'nullable|array']);
        $filterRequest = Request::create('/', 'GET', $data['filters'] ?? []);
        $filterRequest->setUserResolver(fn () => $request->user());
        $query = app(CrmLeadQuery::class)->query($filterRequest, 'lead.export');
        if ($data['selection'] === 'ids') {
            $query->whereIn('id', $data['ids'] ?? []);
        }

        return response()->json(['count' => $query->count(), 'columns' => ['Lead ID', 'Nama', 'Sumber', 'Tahap', 'PIC ID', 'Follow-up', 'Dibuat', 'Diperbarui'], 'destination' => 'Unduhan pribadi']);
    }

    public function reorder(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('crm.pipeline.manage'), 403);
        $data = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|distinct|exists:crm_stages,id']);
        DB::transaction(function () use ($request, $data): void {
            $stages = DB::table('crm_stages')->orderBy('id')->lockForUpdate()->pluck('id')->all();
            abort_unless(count($stages) === count($data['ids']) && ! array_diff($stages, $data['ids']), 409, 'Daftar tahap berubah; muat ulang.');
            foreach ($data['ids'] as $order => $id) {
                DB::table('crm_stages')->where('id', $id)->update(['display_order' => $order, 'updated_at' => now()]);
            }
            app(AccessPolicy::class)->audit($request->user(), 'crm.stages_reordered', ['ids' => $data['ids']]);
        });

        return response()->json(['ok' => true]);
    }

    public function layout(Request $request): JsonResponse
    {
        if ($request->isMethod('put')) {
            $data = $request->validate(['scope' => 'required|in:USER,TEAM,SYSTEM', 'version' => 'required|integer|min:0', 'fields' => 'present|array|max:7', 'fields.*' => 'required|distinct|in:avatar,source,owner,tags,followup,activity,message,interest,phone']);
            abort_unless($data['scope'] === 'USER' || $request->user()->hasPermission('crm.pipeline.manage'), 403);
            $scopeId = match ($data['scope']) {
                'USER' => $request->user()->id, 'TEAM' => $request->user()->team_id, default => 0
            };
            abort_if($scopeId === null, 422, 'Tim belum ditentukan.');
            DB::transaction(function () use ($request, $data, $scopeId): void {
                $query = DB::table('crm_card_layouts')->where('scope_type', $data['scope'])->where('scope_id', $scopeId);
                $current = (clone $query)->lockForUpdate()->first();
                abort_if(($current->version ?? 0) !== $data['version'], 409, 'Tata letak telah berubah. Muat ulang.');
                if ($current) {
                    $query->update(['fields' => json_encode($data['fields']), 'version' => $current->version + 1, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
                } else {
                    DB::table('crm_card_layouts')->insert(['scope_type' => $data['scope'], 'scope_id' => $scopeId, 'fields' => json_encode($data['fields']), 'version' => 1, 'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                }
                app(AccessPolicy::class)->audit($request->user(), 'crm.card_layout_updated', ['scope' => $data['scope']]);
            });
        }
        $scope = $request->input('scope');
        if ($scope) {
            abort_unless(in_array($scope, ['USER', 'TEAM', 'SYSTEM'], true), 422);
            abort_unless($scope === 'USER' || $request->user()->hasPermission('crm.pipeline.manage'), 403);
        }
        $layout = null;
        foreach (['USER' => $request->user()->id, 'TEAM' => $request->user()->team_id, 'SYSTEM' => 0] as $type => $id) {
            if ($id === null || ($scope && $scope !== $type)) {
                continue;
            }
            $layout = DB::table('crm_card_layouts')->where('scope_type', $type)->where('scope_id', $id)->first();
            if ($layout) {
                break;
            }
        }

        return response()->json(['scope' => $scope ?? $layout?->scope_type ?? 'USER', 'version' => $layout->version ?? 0, 'fields' => $layout ? json_decode($layout->fields, true) : ['avatar', 'source', 'owner', 'tags', 'followup', 'activity']]);
    }
}
