<?php

namespace App\Services;

use App\Http\Controllers\AcquisitionController;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversationCrm
{
    public function leads(Request $request, string $permission = 'crm.lead.read'): Builder
    {
        return app(AcquisitionController::class)->scopedLeads($request, $permission);
    }

    public function conversations(Request $request, string $permission = 'crm.inbox.read_assigned'): Builder
    {
        $policies = app(AccessPolicy::class)->policies($request->user(), $permission);

        return DB::table('crm_conversations')->whereNull('crm_conversations.merged_into_id')->where(function (Builder $q) use ($request, $policies, $permission): void {
            $q->whereRaw('1=0');
            foreach ($policies as $policy) {
                if ($policy['scope'] === 'OPERATIONAL_ALL') {
                    $q->orWhereRaw('1=1');
                } elseif ($policy['scope'] === 'ASSIGNED') {
                    $q->orWhere('crm_conversations.assigned_user_id', $request->user()->id);
                    if ($permission === 'crm.inbox.read_assigned' && $request->user()->team_id) {
                        $q->orWhere(fn ($queue) => $queue->whereNull('crm_conversations.assigned_user_id')->where('crm_conversations.team_id', $request->user()->team_id));
                    }
                } elseif ($policy['scope'] === 'TEAM' && $request->user()->team_id) {
                    $q->orWhere('crm_conversations.team_id', $request->user()->team_id);
                }
            }
        });
    }

    public function activity(int $lead, string $type, string $summary, ?int $actor = null): void
    {
        DB::table('acquisition_leads')->where('id', $lead)->update(['updated_at' => now()]);
        DB::table('crm_activities')->insert(['lead_id' => $lead, 'actor_id' => $actor, 'event_type' => $type, 'summary' => $summary, 'created_at' => now()]);
        app(AccessPolicy::class)->audit($actor ? User::find($actor) : null, 'crm.'.$type, ['lead_id' => $lead]);
    }

    public function owner(object $channel, object $lead): ?int
    {
        return app(AssignmentPolicy::class)->conversationOwner($channel, $lead);
    }

    public function ingest(array $payload): void
    {
        $this->ingestNormalized(app(WhatsAppCloud::class)->normalizeInbound($payload));
    }

    public function ingestNormalized(array $events): void
    {
        DB::transaction(fn () => $this->ingestEvents($events), 3);
    }

    private function ingestEvents(array $events): void
    {
        foreach ($events as $event) {
            $channel = DB::table('whatsapp_channels')->where('id', $event['channel_id'])->where('provider', $event['provider'])->where('is_active', true)->lockForUpdate()->first();
            if (! $channel) {
                continue;
            }
            DB::table('whatsapp_channels')->where('id', $channel->id)->update(['last_webhook_at' => now()]);
            $event['id'] = $event['provider'] === 'QR_WEB_SESSION' ? 'qr:'.$channel->id.':'.hash('sha256', $event['external']) : $event['external'];
            if ($event['kind'] === 'message') {
                $this->inbound($channel, $event);
                if ($event['provider'] === 'QR_WEB_SESSION') {
                    DB::table('whatsapp_qr_sessions')->where('channel_id', $channel->id)->update(['last_inbound_at' => now()]);
                }
            } else {
                DB::table('crm_delivery_events')->insertOrIgnore(['channel_id' => $channel->id, 'external_message_id' => $event['id'], 'status' => $event['status'], 'provider_timestamp' => Carbon::createFromTimestampUTC($event['timestamp']), 'error_code' => $event['error_code'] ?? null]);
                $this->delivery($event['id'], $channel->id);
            }
        }
    }

    private function inbound(object $channel, array $message): void
    {
        if (empty($message['id']) || DB::table('crm_messages')->where('external_message_id', $message['id'])->exists()) {
            return;
        }
        $phone = CsRouter::phone($message['from']);
        $lead = app(LeadUpsertService::class)->upsert(mb_substr($message['name'] ?? $phone, 0, 255), $message['from'], null, 'WhatsApp');
        app(AttributionService::class)->record($lead->id, 'inbound-'.$channel->id.'-'.hash('sha256', $message['id']), 'WHATSAPP_INBOUND', 'WhatsApp', [], Carbon::createFromTimestampUTC((int) $message['timestamp'])->toDateTimeString());
        $conversation = DB::table('crm_conversations')->where('channel_id', $channel->id)->where('wa_contact_id', ltrim($phone, '+'))->lockForUpdate()->first();
        if (! $conversation) {
            $owner = $this->owner($channel, $lead);
            $id = DB::table('crm_conversations')->insertGetId(['channel_id' => $channel->id, 'lead_id' => $lead->id, 'wa_contact_id' => ltrim($phone, '+'), 'assigned_user_id' => $owner, 'team_id' => $owner ? User::find($owner)?->team_id : $channel->team_id, 'created_at' => now(), 'updated_at' => now()]);
            if ($owner && $lead->assigned_cs_id !== $owner) {
                DB::table('acquisition_leads')->where('id', $lead->id)->update(['assigned_cs_id' => $owner]);
            }
            $conversation = DB::table('crm_conversations')->find($id);
            $this->activity($lead->id, 'conversation_opened', 'Percakapan dibuka; owner '.($owner ?? 'antrean belum ditugaskan').'.');
        }
        $timestamp = Carbon::createFromTimestampUTC((int) $message['timestamp']);
        $type = $message['type'] ?? 'unknown';
        $body = $message['text'] ?? '';
        $media = $message['media'] ?? null;
        DB::table('crm_messages')->insert(['provider_payload' => $media ? Crypt::encryptString(json_encode(isset($media['local_path']) ? ['local_path' => $media['local_path']] : ['media_id' => $media['id'] ?? null])) : null, 'media_mime' => $media['mime_type'] ?? null, 'media_name' => isset($media['filename']) ? mb_substr(basename($media['filename']), 0, 200) : null, 'provider' => $message['provider'], 'provider_message_id' => $message['external'], 'conversation_id' => $conversation->id, 'external_message_id' => $message['id'], 'direction' => 'INBOUND', 'message_type' => $type, 'text' => $body, 'status' => 'DELIVERED', 'provider_timestamp' => $timestamp, 'created_at' => now(), 'updated_at' => now()]);
        $latestInbound = $conversation->last_inbound_at ? max(Carbon::parse($conversation->last_inbound_at), $timestamp) : $timestamp;
        $latest = $conversation->last_message_at ? max(Carbon::parse($conversation->last_message_at), $timestamp) : $timestamp;
        DB::table('crm_conversations')->where('id', $conversation->id)->update(['status' => 'OPEN', 'last_message_at' => $latest, 'last_inbound_at' => $latestInbound, 'service_window_expires_at' => $latestInbound->copy()->addHours(24), 'unread_count' => DB::raw('unread_count + 1'), 'updated_at' => now()]);
        if (mb_strtoupper(trim($body)) === 'TEST JM' && $channel->webhook_ready && $channel->is_active) {
            DB::table('whatsapp_channels')->where('id', $channel->id)->update(['inbound_test_at' => now()]);
            app(MetaConnect::class)->audit('inbound_test', 'RECEIVED', $channel->id);
        }
        $this->activity($lead->id, 'message_received', 'Pesan WhatsApp diterima.');
    }

    public function delivery(string $external, int $channel): void
    {
        $test = DB::table('whatsapp_connection_tests')->where('external_message_id', $external)->where('channel_id', $channel)->lockForUpdate()->first();
        if ($test) {
            $rank = ['PENDING' => 0, 'SENT' => 1, 'DELIVERED' => 2, 'READ' => 3, 'FAILED' => 0];
            foreach (DB::table('crm_delivery_events')->where('external_message_id', $external)->where('channel_id', $channel)->orderBy('provider_timestamp')->get() as $event) {
                if (($event->status === 'FAILED' && ! in_array($test->status, ['DELIVERED', 'READ'], true) && (! $test->status_at || $event->provider_timestamp >= $test->status_at)) || ($event->status !== 'FAILED' && $rank[$event->status] >= ($rank[$test->status] ?? 0))) {
                    DB::table('whatsapp_connection_tests')->where('id', $test->id)->update(['status' => $event->status, 'status_at' => $event->provider_timestamp, 'error_code' => $event->error_code ? 'MESSAGE_TEST_FAILED' : null]);
                    $test->status = $event->status;
                    $test->status_at = $event->provider_timestamp;
                    if ($event->status === 'FAILED') {
                        DB::table('whatsapp_channels')->where('id', $channel)->update(['outbound_test_at' => null]);
                    }
                }
            }
        }
        $message = DB::table('crm_messages')->where('external_message_id', $external)->whereIn('conversation_id', DB::table('crm_conversations')->where('channel_id', $channel)->select('id'))->lockForUpdate()->first();
        if (! $message) {
            return;
        }
        $rank = ['QUEUED' => 0, 'SENDING' => 0, 'FAILED' => 0, 'SENT' => 1, 'DELIVERED' => 2, 'READ' => 3];
        foreach (DB::table('crm_delivery_events')->where('external_message_id', $external)->where('channel_id', $channel)->orderBy('provider_timestamp')->get() as $event) {
            if ($event->status === 'FAILED' && in_array($message->status, ['DELIVERED', 'READ'], true)) {
                continue;
            }
            if ($event->status !== 'FAILED' && $rank[$event->status] < ($rank[$message->status] ?? 0)) {
                continue;
            }
            if ($event->status === 'FAILED' && $message->status_at && Carbon::parse($event->provider_timestamp)->lt($message->status_at)) {
                continue;
            }
            DB::table('crm_messages')->where('id', $message->id)->update(['status' => $event->status, 'status_at' => $event->provider_timestamp, 'error_code' => $event->error_code]);
            $message->status = $event->status;
            $message->status_at = $event->provider_timestamp;
        }
    }

    public function move(Request $request, int $lead, string $stage, ?string $reason, int $version): void
    {
        abort_if($stage === 'CONVERTED', 422, 'Gunakan review konversi Candidate/Lamaran untuk tahap Dikonversi.');
        $this->leads($request, 'crm.pipeline.move')->whereNull('archived_at')->where('id', $lead)->first() ?? abort(404);
        DB::transaction(function () use ($request, $lead, $stage, $reason, $version): void {
            $row = DB::table('acquisition_leads')->where('id', $lead)->lockForUpdate()->first();
            abort_if($row->archived_at !== null, 409, 'Lead sudah diarsipkan.');
            abort_if($row->crm_version !== $version, 409, 'Lead berubah. Muat ulang sebelum memindahkan tahap.');
            if (! in_array($stage, config('conversation.transitions.'.$row->crm_stage, []), true)) {
                throw ValidationException::withMessages(['stage' => 'Perpindahan tahap tidak diizinkan. Ikuti Kontak Awal → Diskusi → Qualified; gunakan konversi untuk membuat lamaran.']);
            }
            if ($stage === 'LOST' && ! in_array($reason, config('conversation.lost_reasons'), true)) {
                throw ValidationException::withMessages(['reason' => 'Pilih alasan penutupan lead.']);
            }
            DB::table('acquisition_leads')->where('id', $lead)->update(['crm_stage' => $stage, 'crm_stage_entered_at' => now(), 'lost_reason' => $stage === 'LOST' ? $reason : null, 'status' => $stage === 'QUALIFIED' ? 'qualified' : ($stage === 'LOST' ? 'disqualified' : 'new'), 'crm_version' => $version + 1, 'updated_at' => now()]);
            $this->activity($lead, 'stage_changed', $row->crm_stage.' → '.$stage.($reason ? ': '.$reason : ''), $request->user()->id);
        });
    }

    public function nextFollowUp(int $lead): void
    {
        DB::table('acquisition_leads')->where('id', $lead)->update(['next_followup_at' => DB::table('crm_follow_ups')->where('lead_id', $lead)->where('status', 'SCHEDULED')->min('due_at')]);
    }
}
