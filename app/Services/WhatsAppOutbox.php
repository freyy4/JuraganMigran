<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WhatsAppOutbox
{
    public function send(int $id): void
    {
        $message = DB::transaction(function () use ($id): ?object {
            $message = DB::table('crm_messages')->find($id);
            if (! $message) {
                return null;
            }
            DB::table('crm_conversations')->where('id', $message->conversation_id)->lockForUpdate()->first();
            $row = DB::table('crm_messages')->where('id', $id)->where('status', 'QUEUED')->lockForUpdate()->first();
            if ($row) {
                DB::table('crm_messages')->where('id', $id)->update(['status' => 'SENDING', 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
            }

            return $row;
        });
        if (! $message) {
            return;
        }
        $conversation = DB::table('crm_conversations')->find($message->conversation_id);
        $channel = DB::table('whatsapp_channels')->find($conversation->channel_id);
        $payload = json_decode(Crypt::decryptString($message->provider_payload), true);
        if ($message->provider !== $channel->provider || ! $channel->is_active || $channel->status !== 'CONNECTED' || ($channel->strict_owner && $conversation->assigned_user_id !== $message->sent_by_user_id) || ! User::find($message->sent_by_user_id)?->hasPermission('crm.conversation.reply') || (($channel->provider ?? 'META_CLOUD_API') === 'META_CLOUD_API' && in_array($payload['type'], ['text', 'image', 'document'], true) && (! $conversation->service_window_expires_at || now()->gte($conversation->service_window_expires_at)))) {
            DB::table('crm_messages')->where('id', $id)->update(['status' => 'FAILED', 'error_code' => 'POLICY_OR_OWNER_CHANGED']);

            return;
        }
        if ($payload['type'] === 'template' && ! DB::table('crm_whatsapp_templates')->where('channel_id', $channel->id)->where('name', $payload['template']['name'])->where('language', $payload['template']['language']['code'])->where('status', 'APPROVED')->exists()) {
            DB::table('crm_messages')->where('id', $id)->update(['status' => 'FAILED', 'error_code' => 'TEMPLATE_NO_LONGER_APPROVED']);

            return;
        }
        try {
            $adapter = ($channel->provider ?? 'META_CLOUD_API') === 'QR_WEB_SESSION' ? app(WhatsAppQrGateway::class) : app(WhatsAppCloud::class);
            $external = $adapter->sendMessage($channel, $payload);
            DB::transaction(function () use ($external, $id, $conversation, $channel): void {
                $saved = DB::table('crm_messages')->find($id);
                $original = json_decode(Crypt::decryptString($saved->provider_payload), true);
                if (isset($original['_local_media'])) {
                    Storage::disk('local')->delete($original['_local_media']['path']);
                }
                DB::table('crm_messages')->where('id', $id)->update(['external_message_id' => ($channel->provider === 'QR_WEB_SESSION' ? 'qr:'.$channel->id.':'.hash('sha256', $external) : $external), 'provider' => $channel->provider, 'provider_message_id' => $external, 'status' => 'SENT', 'status_at' => now(), 'updated_at' => now()]);
                DB::table('crm_conversations')->where('id', $conversation->id)->update(['last_outbound_at' => now(), 'last_message_at' => now()]);
                if ($channel->provider === 'QR_WEB_SESSION') {
                    DB::table('whatsapp_qr_sessions')->where('channel_id', $channel->id)->update(['last_outbound_at' => now()]);
                }
                app(ConversationCrm::class)->delivery($channel->provider === 'QR_WEB_SESSION' ? 'qr:'.$channel->id.':'.hash('sha256', $external) : $external, $channel->id);
                app(ConversationCrm::class)->activity($conversation->lead_id, 'message_sent', 'Pesan dikirim ke WhatsApp.', $conversation->assigned_user_id);
            });
        } catch (WhatsAppProviderFailure $error) {
            DB::table('crm_messages')->where('id', $id)->update(['status' => $error->uncertain ? 'UNKNOWN' : 'FAILED', 'error_code' => $error->diagnostic]);
        } catch (ConnectionException) {
            DB::table('crm_messages')->where('id', $id)->update(['status' => 'UNKNOWN', 'error_code' => 'DELIVERY_UNCERTAIN']);
        }
    }
}
