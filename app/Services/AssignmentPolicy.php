<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AssignmentPolicy
{
    public function activeUserIds(): Builder
    {
        return DB::table('users')->where('is_active', true)->select('id');
    }

    public function syncUnassignedConversations(int $lead, ?int $user): void
    {
        $owner = $this->existingOwner($user, true);
        if (! $owner) {
            return;
        }
        $changed = DB::table('crm_conversations')->where('lead_id', $lead)->whereNull('assigned_user_id')->update(['assigned_user_id' => $owner->id, 'team_id' => $owner->team_id, 'version' => DB::raw('version + 1')]);
        if ($changed) {
            app(ConversationCrm::class)->activity($lead, 'owner_changed', 'Owner mengikuti penugasan lead: '.$owner->name);
        }
    }

    public function existingOwner(?int $id, bool $requireInboxPermission = false): ?User
    {
        $owner = $id ? User::find($id) : null;
        if (! $owner?->is_active || ($requireInboxPermission && ! $owner->hasPermission('crm.conversation.reply'))) {
            return null;
        }
        if ($requireInboxPermission && ! in_array(DB::table('crm_agent_profiles')->where('user_id', $id)->value('availability') ?? 'ONLINE', ['ONLINE', 'BUSY'], true)) {
            return null;
        }

        return $owner;
    }

    public function conversationOwner(object $channel, object $lead): ?int
    {
        $eligible = User::where('is_active', true)->when($channel->team_id, fn ($q) => $q->where('team_id', $channel->team_id))->orderBy('id')->get()->filter(fn (User $u) => $u->hasPermission('crm.conversation.reply'));
        if ($lead->assigned_cs_id) {
            $existing = app(AssignmentPolicy::class)->existingOwner($lead->assigned_cs_id);
            if ($existing) {
                return $existing->id;
            }
        }
        $hours = json_decode($channel->business_hours ?? 'null', true);
        if ($hours) {
            $local = now()->timezone('Asia/Jakarta');
            if (! in_array($local->dayOfWeekIso, $hours['days'] ?? [1, 2, 3, 4, 5], true) || $local->format('H:i') < $hours['start'] || $local->format('H:i') >= $hours['end']) {
                return null;
            }
        }
        $profiles = DB::table('crm_agent_profiles')->whereIn('user_id', $eligible->pluck('id'))->get()->keyBy('user_id');
        $counts = DB::table('crm_conversations')->where('status', 'OPEN')->whereIn('assigned_user_id', $eligible->pluck('id'))->groupBy('assigned_user_id')->selectRaw('assigned_user_id, COUNT(*) AS total')->pluck('total', 'assigned_user_id');
        $eligible = $eligible->filter(fn ($u) => ($profiles[$u->id]->availability ?? 'ONLINE') === 'ONLINE' && ($counts[$u->id] ?? 0) < ($profiles[$u->id]->conversation_cap ?? 30));
        if ($channel->routing_method === 'MANUAL') {
            return null;
        }
        if ($channel->routing_method === 'LEAST_ACTIVE') {
            $counts = DB::table('crm_conversations')->where('status', 'OPEN')->whereIn('assigned_user_id', $eligible->pluck('id'))->groupBy('assigned_user_id')->selectRaw('assigned_user_id, COUNT(*) AS total')->pluck('total', 'assigned_user_id');

            return $eligible->sortBy(fn ($u) => $counts[$u->id] ?? 0)->first()?->id;
        }
        $user = $eligible->first(fn ($u) => $u->id > ($channel->last_user_id ?? 0)) ?? $eligible->first();
        if ($user) {
            DB::table('whatsapp_channels')->where('id', $channel->id)->update(['last_user_id' => $user->id]);
        }

        return $user?->id;
    }

    public function assignForm(int $leadId, int $vacancyId, ?int $submissionId = null): ?object
    {
        return DB::transaction(function () use ($leadId, $vacancyId, $submissionId): ?object {
            $lead = DB::table('acquisition_leads')->where('id', $leadId)->lockForUpdate()->first();
            app(AssignmentPolicy::class)->syncUnassignedConversations($leadId, $lead->assigned_cs_id);
            if ($lead->assigned_member_id) {
                $existing = DB::table('cs_members')->where('id', $lead->assigned_member_id)->where('is_active', true)->first();
                if ($existing && (! $existing->user_id || $this->existingOwner($existing->user_id))) {
                    return $existing;
                }
            }
            if ($lead->assigned_cs_id && $this->existingOwner($lead->assigned_cs_id)) {
                $owner = DB::table('cs_members')->where('user_id', $lead->assigned_cs_id)->where('is_active', true)->first();
                if ($owner) {
                    DB::table('acquisition_leads')->where('id', $leadId)->update(['assigned_member_id' => $owner->id]);
                }

                return $owner;
            }
            if ($lead->assigned_cs_id || $lead->assigned_member_id) {
                DB::table('acquisition_leads')->where('id', $leadId)->update(['assigned_cs_id' => null, 'assigned_member_id' => null, 'crm_version' => DB::raw('crm_version + 1')]);
                app(ConversationCrm::class)->activity($leadId, 'owner_unassigned', 'Penugasan tidak aktif dikembalikan ke antrean.');
            }
            $mapping = DB::table('cs_vacancy_mappings')->where('vacancy_id', $vacancyId)->first();
            $group = $mapping ? DB::table('cs_groups')->where('id', $mapping->group_id)->where('is_active', true)->lockForUpdate()->first() : null;
            $members = $group ? DB::table('cs_members')->where('group_id', $group->id)->where('is_active', true)->where(fn ($q) => $q->whereNull('user_id')->orWhereIn('user_id', app(AssignmentPolicy::class)->activeUserIds()))->orderBy('id')->get() : collect();
            $member = $members->first(fn ($member) => $member->id > ($group->last_member_id ?? 0)) ?? $members->first();
            if ($member) {
                DB::table('acquisition_leads')->where('id', $leadId)->update(['assigned_member_id' => $member->id, 'assigned_cs_id' => $member->user_id, 'updated_at' => now()]);
                DB::table('cs_groups')->where('id', $group->id)->update(['last_member_id' => $member->id, 'updated_at' => now()]);
                DB::table('cs_members')->where('id', $member->id)->update(['last_assigned_at' => now()]);
                app(AssignmentPolicy::class)->syncUnassignedConversations($leadId, $member->user_id);
                app(ConversationCrm::class)->activity($leadId, 'owner_assigned', 'CS ditugaskan melalui kebijakan bersama: '.$member->name);
            }
            DB::table('cs_assignment_events')->insert(['lead_id' => $leadId, 'member_id' => $member?->id, 'submission_id' => $submissionId, 'status' => $member ? 'assigned' : ($mapping ? 'no_active_cs' : 'unmapped_vacancy'), 'created_at' => now(), 'updated_at' => now()]);
            app(AccessPolicy::class)->audit(auth()->user(), 'cs.assignment', ['lead_id' => $leadId, 'member_id' => $member?->id, 'status' => $member ? 'assigned' : 'queued']);

            return $member;
        });
    }
}
