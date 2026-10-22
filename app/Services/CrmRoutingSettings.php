<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CrmRoutingSettings
{
    /** @return array{routing_enabled?: bool, fallback_user_id?: int|null} */
    public function channelValues(Request $request, object $channel, ?int $team): array
    {
        $values = $request->validate([
            'routing_enabled' => 'sometimes|required|boolean',
            'fallback_user_id' => 'sometimes|nullable|integer|exists:users,id',
        ]);
        $permission = $channel->provider === 'QR_WEB_SESSION' ? 'WHATSAPP_CHANNEL_MANAGE_ROUTING' : 'WHATSAPP_CONNECTION_MANAGE';
        $all = collect(app(AccessPolicy::class)->policies($request->user(), $permission))->contains('scope', 'OPERATIONAL_ALL');
        abort_unless($all || ($request->user()->team_id && $channel->team_id === $request->user()->team_id && $team === $request->user()->team_id), 403);
        $fallback = $values['fallback_user_id'] ?? $channel->fallback_user_id;
        if (array_key_exists('fallback_user_id', $values) && $values['fallback_user_id'] === null) {
            $fallback = null;
        }
        if ($fallback) {
            $user = User::find($fallback);
            if (! $user?->is_active || ! $user->hasPermission('crm.conversation.reply') || ($team && $user->team_id !== $team) || (! $all && $user->team_id !== $request->user()->team_id)) {
                throw ValidationException::withMessages(['fallback_user_id' => 'Pilih CS aktif yang berizin membalas dan berada dalam tim channel.']);
            }
        }
        if (array_key_exists('routing_enabled', $values)) {
            $values['routing_enabled'] = (bool) $values['routing_enabled'];
        }
        if (isset($values['fallback_user_id'])) {
            $values['fallback_user_id'] = (int) $values['fallback_user_id'];
        }

        return $values;
    }
}
