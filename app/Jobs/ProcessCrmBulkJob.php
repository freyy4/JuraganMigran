<?php

namespace App\Jobs;

use App\Http\Controllers\CrmPipelineWorkspaceController;
use App\Models\User;
use App\Services\AccessPolicy;
use App\Services\ConversationCrm;
use App\Services\CrmWorkspaceExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcessCrmBulkJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $jobId) {}

    public function handle(ConversationCrm $crm): void
    {
        $job = DB::table('crm_bulk_jobs')->find($this->jobId);
        if (! $job || in_array($job->status, ['DONE', 'PARTIAL', 'FAILED'], true)) {
            return;
        }
        $actor = User::find($job->requested_by);
        $permission = CrmPipelineWorkspaceController::permission($job->action);
        if (! $actor || ! $actor->is_active || ! $actor->hasPermission($permission)) {
            DB::table('crm_bulk_jobs')->where('id', $job->id)->update(['status' => 'FAILED', 'failure_count' => $job->total_count, 'completed_at' => now()]);

            return;
        }
        $request = Request::create('/');
        $request->setUserResolver(fn () => $actor);
        $selection = json_decode($job->selection, true);
        $payload = json_decode($job->payload, true);
        DB::table('crm_bulk_jobs')->where('id', $job->id)->update(['status' => 'RUNNING', 'updated_at' => now()]);
        if ($job->action === 'export') {
            try {
                $path = app(CrmWorkspaceExport::class)->create($request, $selection['ids'], $payload['format'], $payload['contacts'] ?? false, $job->id);
                DB::table('crm_bulk_jobs')->where('id', $job->id)->update(['status' => 'DONE', 'success_count' => app(ConversationCrm::class)->leads($request, 'lead.export')->whereIn('id', $selection['ids'])->whereNull('archived_at')->count(), 'result' => json_encode(['path' => $path]), 'completed_at' => now()]);
            } catch (\Throwable $exception) {
                DB::table('crm_bulk_jobs')->where('id', $job->id)->update(['status' => 'FAILED', 'completed_at' => now()]);
            }
            app(AccessPolicy::class)->audit($actor, 'crm.export_completed', ['job_id' => $job->id]);

            return;
        }
        foreach ($selection['ids'] as $id) {
            DB::transaction(function () use ($job, $crm, $request, $permission, $id, $payload, $actor): void {
                $current = DB::table('crm_bulk_jobs')->where('id', $job->id)->lockForUpdate()->first();
                $results = json_decode($current->result ?? '{}', true);
                if (isset($results[(string) $id])) {
                    return;
                }
                $lead = $crm->leads($request, $permission)->where('id', $id)->whereNull('archived_at')->lockForUpdate()->first();
                $ok = false;
                try {
                    DB::transaction(function () use ($lead, $job, $crm, $request, $id, $payload, $actor): void {
                        abort_unless($lead, 404);
                        if ($job->action === 'stage') {
                            $crm->move($request, $id, $payload['stage'], $payload['reason'] ?? null, $lead->crm_version);
                        } elseif ($job->action === 'archive') {
                            DB::table('acquisition_leads')->where('id', $id)->update(['archived_at' => now(), 'crm_version' => $lead->crm_version + 1, 'updated_at' => now()]);
                            $crm->activity($id, 'archived', $payload['reason'], $actor->id);
                        } elseif ($job->action === 'tag') {
                            abort_unless(DB::table('crm_tags')->where('id', $payload['tag_id'])->where('is_active', true)->exists(), 422);
                            $tag = DB::table('crm_lead_tags')->where('lead_id', $id)->where('tag_id', $payload['tag_id']);
                            if ($payload['remove'] ?? false) {
                                $tag->delete();
                            } else {
                                DB::table('crm_lead_tags')->insertOrIgnore(['lead_id' => $id, 'tag_id' => $payload['tag_id'], 'added_by' => $actor->id, 'created_at' => now()]);
                            }
                            $crm->activity($id, 'tag_changed', 'Tag #'.$payload['tag_id'], $actor->id);
                        } elseif ($job->action === 'task') {
                            abort_unless($lead->assigned_cs_id, 422, 'Lead belum mempunyai PIC.');
                            DB::table('crm_follow_ups')->insert(['lead_id' => $id, 'assigned_user_id' => $lead->assigned_cs_id, 'due_at' => $payload['due_at'], 'type' => 'OTHER', 'note' => $payload['note'], 'created_at' => now(), 'updated_at' => now()]);
                            $crm->nextFollowUp($id);
                            $crm->activity($id, 'followup_created', $payload['note'], $actor->id);
                        } else {
                            $target = User::find($payload['user_id']);
                            $all = collect(app(AccessPolicy::class)->policies($actor, 'crm.lead.assign'))->contains('scope', 'OPERATIONAL_ALL');
                            abort_unless($target && $target->is_active && $target->hasPermission('crm.conversation.reply') && ($all || ($actor->team_id && $actor->team_id === $target->team_id)), 403);
                            abort_if(DB::table('crm_messages')->whereIn('conversation_id', DB::table('crm_conversations')->where('lead_id', $id)->select('id'))->where('status', 'SENDING')->exists(), 409);
                            DB::table('acquisition_leads')->where('id', $id)->update(['assigned_cs_id' => $target->id, 'crm_version' => $lead->crm_version + 1, 'updated_at' => now()]);
                            DB::table('crm_conversations')->where('lead_id', $id)->update(['assigned_user_id' => $target->id, 'team_id' => $target->team_id, 'version' => DB::raw('version + 1')]);
                            $crm->activity($id, 'owner_changed', 'Owner dialihkan ke #'.$target->id, $actor->id);
                        }
                    });
                    $ok = true;
                } catch (\Throwable $exception) {
                    $results[(string) $id] = ['ok' => false, 'reason' => 'Lead tidak memenuhi izin atau aturan tindakan.'];
                }
                if ($ok) {
                    $results[(string) $id] = ['ok' => true];
                }
                DB::table('crm_bulk_jobs')->where('id', $job->id)->update(['result' => json_encode($results), 'success_count' => $current->success_count + (int) $ok, 'failure_count' => $current->failure_count + (int) ! $ok, 'updated_at' => now()]);
            });
        }
        $row = DB::table('crm_bulk_jobs')->find($job->id);
        DB::table('crm_bulk_jobs')->where('id', $job->id)->update(['status' => $row->failure_count ? ($row->success_count ? 'PARTIAL' : 'FAILED') : 'DONE', 'completed_at' => now()]);
        app(AccessPolicy::class)->audit($actor, 'crm.bulk_job_completed', ['job_id' => $job->id, 'success' => $row->success_count, 'failure' => $row->failure_count]);
    }
}
