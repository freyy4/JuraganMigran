<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationBlocker;
use App\Models\PipelineStage;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessWorkflow
{
    public function stages(Application $application): ?array
    {
        if (! $application->workflow_version_id) {
            return null;
        }
        if ($application->relationLoaded('workflowConfiguration')) {
            return $application->getRelation('workflowConfiguration');
        }
        $version = DB::table('process_workflow_versions')->where('id', $application->workflow_version_id)->first();

        $stages = $version ? json_decode($version->snapshot, true)['stages'] : [];
        $application->setRelation('workflowConfiguration', $stages);

        return $stages;
    }

    public function stage(Application $application, int $id): ?array
    {
        $stages = $this->stages($application);
        if ($stages !== null) {
            return collect($stages)->firstWhere('id', $id);
        }
        $stage = PipelineStage::find($id);

        return $stage ? $stage->only(['id', 'code', 'name', 'sequence', 'sla_hours', 'is_terminal', 'is_active']) : null;
    }

    public function assertTransition(Application $application, int $destination): void
    {
        $stages = $this->stages($application);
        if ($stages === null) {
            return;
        }
        $target = collect($stages)->firstWhere('id', $destination);
        $current = collect($stages)->firstWhere('id', $application->current_stage_id);
        if (! $target || ($current && ! in_array($destination, $current['allowed_transitions'] ?? [], true))) {
            throw ValidationException::withMessages(['to_stage_id' => 'Tahap tujuan tidak diizinkan oleh versi alur lamaran ini.']);
        }
        if ($current && ($current['required_checkpoint'] ?? false)) {
            app(DocumentRequirementEngine::class)->assertGate($application);
            if ($application->tasks()->whereNotIn('status', [...Task::COMPLETED_STATUSES, 'cancelled'])->exists() || $application->blockers()->whereNotIn('status', [...ApplicationBlocker::RESOLVED, 'closed', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['to_stage_id' => 'Selesaikan tugas dan kendala sebelum keluar dari tahap dengan checkpoint.']);
            }
        }
    }
}
