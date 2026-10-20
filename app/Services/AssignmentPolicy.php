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
}
