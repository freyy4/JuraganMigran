<?php

namespace App\Services;

use App\Models\Application;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessStageTransition
{
    public function move(User $actor, Application $application, array $data): void
    {
        DB::transaction(function () use ($actor, $application, $data): void {
            $locked = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            $destination = PipelineStage::whereKey($data['to_stage_id'])->lockForUpdate()->firstOrFail();
            app(TrustCommitmentProtocol::class)->assertPipelineTransition($locked, $destination->id);
            app(ProcessWorkflow::class)->assertTransition($locked, $destination->id);
            $destinationConfig = app(ProcessWorkflow::class)->stage($locked, $destination->id);
            if (! $locked->workflow_version_id && ! $destination->is_active) {
                throw ValidationException::withMessages(['to_stage_id' => 'Tahap tujuan sudah tidak aktif.']);
            }
            $expected = $data['from_stage_id'] === null ? null : (int) $data['from_stage_id'];
            $current = $locked->current_stage_id === null ? null : (int) $locked->current_stage_id;
            if ($current !== $expected) {
                throw ValidationException::withMessages(['from_stage_id' => 'Tahap lamaran telah berubah. Muat ulang papan dan konfirmasikan kembali.']);
            }
            if ($current === $destination->id) {
                throw ValidationException::withMessages(['to_stage_id' => 'Lamaran sudah berada di tahap yang dipilih.']);
            }
            if (! $locked->currentStage || ($destinationConfig['sequence'] ?? $destination->sequence) > (app(ProcessWorkflow::class)->stage($locked, $locked->current_stage_id)['sequence'] ?? $locked->currentStage->sequence)) {
                app(DocumentRequirementEngine::class)->assertGate($locked);
            }
            $now = now();
            $visit = $locked->stageHistories()->orderByDesc('id')->first();
            if ($visit && $visit->to_stage_id === $current && ! $visit->exited_at) {
                $visit->update(['exited_at' => $now]);
            }
            $locked->stageHistories()->create(['from_stage_id' => $current, 'to_stage_id' => $destination->id, 'entered_at' => $now, 'changed_by' => $actor->id, 'reason' => $data['reason'] ?? null, 'sla_hours_snapshot' => $destinationConfig['sla_hours'] ?? $destination->sla_hours]);
            $locked->current_stage_id = $destination->id;
            $locked->unsetRelation('currentStage');
            if (in_array($locked->status, ['ready', 'ready_for_departure', 'departure_ready'], true) && ! $locked->isReadyForDeparture()) {
                $locked->status = 'active';
            }
            $locked->save();
            app(TrackingHub::class)->stage($locked->id, $destination->id, $locked->stageHistories()->latest('id')->value('id'));
            if (DB::table('portal_accounts')->where('candidate_id', $locked->candidate_id)->where('status', 'active')->where('in_app_notifications', true)->exists()) {
                DB::table('portal_notifications')->insert(['candidate_id' => $locked->candidate_id, 'category' => 'PROCESS_UPDATE', 'title' => 'Proses Anda diperbarui', 'body_safe' => 'Tahap proses Anda telah diperbarui. Periksa langkah berikutnya di portal.', 'deep_link' => '/portal/process', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

    }
}
