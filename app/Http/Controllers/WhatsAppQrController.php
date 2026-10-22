<?php

namespace App\Http\Controllers;

use App\Services\CrmRoutingSettings;
use App\Services\CsRouter;
use App\Services\WhatsAppProviderFailure;
use App\Services\WhatsAppQrGateway;
use App\Services\WhatsAppQrSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WhatsAppQrController extends Controller
{
    public function __construct(private WhatsAppQrSessions $sessions, private WhatsAppQrGateway $gateway) {}

    private function result(callable $action): JsonResponse
    {
        try {
            return response()->json($action())->header('Cache-Control', 'private, no-store');
        } catch (WhatsAppProviderFailure $error) {
            return response()->json(['code' => $error->diagnostic, 'message' => $error->getMessage()], 422)->header('Cache-Control', 'private, no-store');
        }
    }

    public function index(): JsonResponse
    {
        return $this->result(fn () => ['ready' => $this->gateway->ready(), 'migrationTargets' => DB::table('whatsapp_channels')->where('provider', 'META_CLOUD_API')->where('status', 'CONNECTED')->whereNotNull('inbound_test_at')->whereNotNull('outbound_test_at')->get(['id', 'name', 'display_phone_number']), 'channels' => DB::table('whatsapp_channels')->where('provider', 'QR_WEB_SESSION')->whereNull('removed_at')->orderByDesc('id')->limit(50)->get()->map(fn ($channel) => $this->sessions->safe($channel, DB::table('whatsapp_qr_sessions')->where('channel_id', $channel->id)->first()))]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100']);

        return $this->result(fn () => ['channel' => ['id' => $this->sessions->create($data['name'])->id]]);
    }

    public function start(Request $request, int $channel): JsonResponse
    {
        return $this->result(fn () => $this->sessions->start($channel, $request->user()->id));
    }

    public function rescan(Request $request, int $channel): JsonResponse
    {
        return $this->result(fn () => $this->sessions->start($channel, $request->user()->id, true));
    }

    public function status(int $channel): JsonResponse
    {
        return $this->result(fn () => $this->sessions->lease($channel, fn () => $this->sessions->refresh($channel)));
    }

    public function image(Request $request, string $pairing): Response|JsonResponse
    {
        $session = DB::table('whatsapp_qr_sessions')->where('pairing_id', $pairing)->first() ?? abort(404);
        abort_unless($session->pairing_user_id === $request->user()->id, 403);
        abort_unless($session->state === 'WAITING_FOR_SCAN' && $session->expires_at && now()->lt($session->expires_at), 410);
        try {
            $this->sessions->audit($session->channel_id, 'qr_viewed');

            return response($this->gateway->qr($session))->header('Content-Type', 'image/png')->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache')->header('X-Content-Type-Options', 'nosniff')->header('Referrer-Policy', 'no-referrer');
        } catch (WhatsAppProviderFailure $error) {
            return response()->json(['code' => $error->diagnostic, 'message' => $error->getMessage()], 410)->header('Cache-Control', 'private, no-store');
        }
    }

    public function remove(Request $request, int $channel): JsonResponse
    {
        $request->validate(['confirm' => 'accepted']);

        return $this->result(fn () => $this->sessions->remove($channel));
    }

    public function disconnect(Request $request, int $channel): JsonResponse
    {
        $request->validate(['confirm' => 'accepted']);

        return $this->result(fn () => $this->sessions->disconnect($channel));
    }

    public function routing(Request $request, int $channel): JsonResponse
    {
        $data = $request->validate(['team_id' => 'nullable|integer|min:1', 'routing_method' => 'required|in:ROUND_ROBIN,LEAST_ACTIVE,MANUAL', 'prefer_existing_owner' => 'required|boolean', 'strict_owner' => 'required|boolean', 'hours_start' => 'nullable|date_format:H:i|required_with:hours_end', 'hours_end' => 'nullable|date_format:H:i|after:hours_start|required_with:hours_start']);
        if (array_key_exists('team_id', $data) && ($data['team_id'] ?? null) != $this->sessions->channel($channel)->team_id) {
            abort_unless($request->user()->hasPermission('WHATSAPP_CHANNEL_MANAGE_TEAM'), 403);
        }

        $extras = app(CrmRoutingSettings::class)->channelValues($request, $this->sessions->channel($channel), isset($data['team_id']) ? (int) $data['team_id'] : null);

        return $this->result(fn () => $this->sessions->lease($channel, function () use ($channel, $data, $extras): array {
            $state = $this->sessions->refresh($channel);
            if ($state['state'] !== 'CONNECTED' || $state['health'] !== 'HEALTHY') {
                throw new WhatsAppProviderFailure('REAUTH_REQUIRED');
            }
            if (! empty($data['team_id']) && ! collect(app(MetaConnectController::class)->teams())->contains('id', $data['team_id'])) {
                abort(422, 'Tim tidak memiliki CS aktif yang berizin.');
            }
            DB::table('whatsapp_channels')->where('id', $channel)->update($extras + ['team_id' => $data['team_id'] ?? null, 'routing_method' => $data['routing_method'], 'prefer_existing_owner' => $data['prefer_existing_owner'], 'strict_owner' => $data['strict_owner'], 'business_hours' => ! empty($data['hours_start']) ? json_encode(['days' => [1, 2, 3, 4, 5], 'start' => $data['hours_start'], 'end' => $data['hours_end']]) : null, 'status' => 'CONNECTED', 'is_active' => true]);
            DB::table('whatsapp_qr_sessions')->where('channel_id', $channel)->update(['activated' => true]);
            $this->sessions->audit($channel, 'routing_configured');

            return $this->sessions->safe($this->sessions->channel($channel), DB::table('whatsapp_qr_sessions')->where('channel_id', $channel)->first());
        }));
    }

    public function migrate(Request $request, int $channel): JsonResponse
    {
        abort_unless($request->user()->hasPermission('WHATSAPP_CHANNEL_DISCONNECT'), 403);
        $data = $request->validate(['target_channel_id' => 'required|integer|different:channel', 'confirm' => 'accepted']);

        return $this->result(fn () => $this->sessions->lease($channel, function () use ($channel, $data): array {
            return DB::transaction(function () use ($channel, $data): array {
                $source = DB::table('whatsapp_channels')->where('id', $channel)->where('provider', 'QR_WEB_SESSION')->lockForUpdate()->first() ?? abort(404);
                $target = DB::table('whatsapp_channels')->where('id', $data['target_channel_id'])->where('provider', 'META_CLOUD_API')->lockForUpdate()->first() ?? abort(404);
                $session = DB::table('whatsapp_qr_sessions')->where('channel_id', $channel)->first();
                abort_unless(! $source->is_active && $session->state === 'DISCONNECTED_BY_ADMIN' && ! $session->cleanup_pending, 422, 'Putuskan sesi QR sampai penghapusan selesai sebelum memindahkan koneksi. Histori tetap disimpan.');
                abort_unless($target->status === 'CONNECTED' && $target->is_active && $target->inbound_test_at && $target->outbound_test_at && $target->webhook_ready, 422, 'Channel Meta perlu terhubung dan lulus uji pesan masuk serta keluar.');
                abort_unless(CsRouter::phone($source->display_phone_number) === CsRouter::phone($target->display_phone_number), 422, 'Pilih channel Meta dengan nomor yang sama.');
                abort_if(DB::table('crm_messages')->whereIn('conversation_id', DB::table('crm_conversations')->whereIn('channel_id', [$channel, $target->id])->select('id'))->where('status', 'SENDING')->exists(), 409, 'Tunggu pengiriman yang sedang berlangsung selesai.');
                foreach (DB::table('crm_conversations')->where('channel_id', $target->id)->get() as $conversation) {
                    $existing = DB::table('crm_conversations')->where('channel_id', $channel)->where('wa_contact_id', $conversation->wa_contact_id)->first();
                    if ($existing) {
                        abort_unless($existing->lead_id === $conversation->lead_id, 422, 'Kemungkinan duplikat identitas ditemukan. Tinjau lead sebelum migrasi.');
                        DB::table('crm_messages')->where('conversation_id', $conversation->id)->update(['conversation_id' => $existing->id]);
                        DB::table('crm_conversations')->where('id', $existing->id)->update(['unread_count' => $existing->unread_count + $conversation->unread_count, 'version' => $existing->version + 1, 'last_message_at' => max($existing->last_message_at ?? '', $conversation->last_message_at ?? '') ?: null, 'last_inbound_at' => max($existing->last_inbound_at ?? '', $conversation->last_inbound_at ?? '') ?: null, 'service_window_expires_at' => max($existing->service_window_expires_at ?? '', $conversation->service_window_expires_at ?? '') ?: null]);
                        DB::table('crm_conversations')->where('id', $conversation->id)->update(['status' => 'CLOSED', 'merged_into_id' => $existing->id]);
                    } else {
                        DB::table('crm_conversations')->where('id', $conversation->id)->update(['channel_id' => $channel]);
                    }
                }
                $values = array_intersect_key((array) $target, array_flip(['provider', 'connection_method', 'business_id', 'waba_id', 'phone_number_id', 'display_phone_number', 'verified_name', 'quality_rating', 'access_token', 'credential_expires_at', 'permission_snapshot', 'webhook_ready', 'webhook_verified_at', 'connected_by', 'connected_at', 'last_health_check_at', 'inbound_test_at', 'outbound_test_at']));
                DB::table('whatsapp_channels')->where('id', $target->id)->update(['phone_number_id' => 'migrated-'.Str::uuid(), 'status' => 'DISCONNECTED', 'is_active' => false, 'access_token' => Crypt::encryptString(''), 'updated_at' => now()]);
                DB::table('whatsapp_channels')->where('id', $channel)->update($values + ['status' => 'CONNECTED', 'is_active' => true, 'last_error' => null, 'updated_at' => now()]);
                DB::table('meta_auth_sessions')->where('channel_id', $target->id)->update(['channel_id' => $channel]);
                DB::table('whatsapp_connection_tests')->where('channel_id', $target->id)->update(['channel_id' => $channel]);
                foreach (DB::table('crm_whatsapp_templates')->where('channel_id', $target->id)->get() as $template) {
                    $templateValues = (array) $template;
                    unset($templateValues['id']);
                    $templateValues['channel_id'] = $channel;
                    DB::table('crm_whatsapp_templates')->updateOrInsert(['channel_id' => $channel, 'name' => $template->name, 'language' => $template->language], $templateValues);
                }
                DB::table('whatsapp_qr_sessions')->where('channel_id', $channel)->update(['state' => 'MIGRATED', 'activated' => false, 'pairing_id' => null, 'webhook_credential' => null]);
                $this->sessions->audit($channel, 'MIGRATED_TO_META');

                return ['channelId' => $channel, 'provider' => 'META_CLOUD_API', 'message' => 'Channel dipindahkan ke Meta. Lead, owner, dan histori tetap tersimpan.'];
            });
        }));
    }

    public function diagnostics(int $channel): JsonResponse
    {
        return $this->result(function () use ($channel): array {
            $this->sessions->channel($channel);

            return ['events' => DB::table('whatsapp_channel_health_events')->where('channel_id', $channel)->latest('id')->limit(50)->get(['state', 'error_code', 'created_at'])];
        });
    }

    public function webhook(Request $request): Response
    {
        abort_unless(config('whatsapp-qr.enabled'), 503);
        abort_if(strlen($request->getContent()) > 2000000, 413);
        $data = $request->json()->all();
        $session = DB::table('whatsapp_qr_sessions')->where('gateway_session', $data['session'] ?? '')->first();
        abort_unless($session && $session->webhook_credential, 403);
        $secret = Crypt::decryptString($session->webhook_credential);
        abort_unless($request->header('X-Webhook-Hmac-Algorithm') === 'sha512' && hash_equals(hash_hmac('sha512', $request->getContent(), $secret), (string) $request->header('X-Webhook-Hmac')), 403);
        abort_unless(in_array($data['event'] ?? '', ['message', 'message.ack', 'session.status'], true), 422);
        DB::table('crm_webhook_events')->insertOrIgnore(['provider' => 'QR_WEB_SESSION', 'channel_id' => $session->channel_id, 'event_key' => hash('sha256', $request->getContent()), 'payload' => Crypt::encryptString($request->getContent()), 'created_at' => now(), 'updated_at' => now()]);

        return response('EVENT_RECEIVED', 200);
    }
}
