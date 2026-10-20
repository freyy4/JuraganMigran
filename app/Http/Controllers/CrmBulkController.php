<?php

namespace App\Http\Controllers;

use App\Models\ApplicationBlocker;
use App\Models\Task;
use App\Services\AccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmBulkController extends Controller
{
    public function apply(Request $request): JsonResponse
    {
        $tasks = $request->routeIs('tasks.bulk', 'follow-ups.bulk');
        $data = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|distinct', 'action' => 'required|in:'.($tasks ? 'complete' : 'resolve'), 'confirmed' => 'required|accepted']);
        $results = [];
        foreach ($data['ids'] as $id) {
            $success = DB::transaction(function () use ($tasks, $id, $request): bool {
                $query = $tasks ? Task::query() : ApplicationBlocker::query();
                $record = $query->whereKey($id)->lockForUpdate()->first();
                if (! $record || ($record->application_id && ! app(AccessPolicy::class)->applicationIds($request->user(), 'process.read')->where('applications.id', $record->application_id)->exists())) {
                    return false;
                }
                if ($tasks) {
                    if (! $record->isCompleted()) {
                        $record->update(['status' => 'completed', 'completed_at' => now()]);
                    }
                } elseif (! $record->isResolved()) {
                    $record->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $request->user()->id]);
                }
                app(AccessPolicy::class)->audit($request->user(), 'crm.bulk.'.($tasks ? 'task_completed' : 'blocker_resolved'), ['id' => (int) $id]);

                return true;
            });
            $results[] = ['id' => (int) $id, 'success' => $success, 'message' => $success ? 'Berhasil.' : 'Data tidak tersedia dalam cakupan tindakan.'];
        }
        $succeeded = collect($results)->where('success', true)->count();

        return response()->json(['succeeded' => $succeeded, 'failed' => count($results) - $succeeded, 'results' => $results]);
    }
}
