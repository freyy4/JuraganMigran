<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ConversationCrm;
use App\Services\CsRouter;
use App\Services\MetaConnect;
use App\Services\MetaConnectFailure;
use App\Services\MetaGraph;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MetaConnectController extends Controller
{
    public function __construct(private MetaConnect $connect, private MetaGraph $graph) {}

    private function result(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (MetaConnectFailure $error) {
            $this->connect->audit('request_failed', $error->diagnostic);

            return response()->json(['code' => $error->diagnostic, 'message' => $error->getMessage()], $error->httpStatus);
        }
    }

    public function preflight(): JsonResponse
    {
        return response()->json($this->connect->preflight());
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['channelId' => 'nullable|integer|exists:whatsapp_channels,id']);

        return $this->result(fn () => $this->connect->start($request->user(), $data['channelId'] ?? null));
    }

    public function callback(Request $request): View
    {
        $request->validate(['state' => 'required|string|max:200', 'code' => 'nullable|string|max:4000', 'error' => 'nullable|string|max:200']);
        try {
            $id = $this->connect->callback($request->user(), $request->string('state')->toString(), $request->input('code'), $request->filled('error'));

            $session = DB::table('meta_auth_sessions')->find($id);
            $failure = $session->failure_code ? (MetaConnectFailure::messages()[$session->failure_code] ?? 'Koneksi belum selesai.').' ('.$session->failure_code.')' : null;

            return view('conversation.meta-callback', ['sessionId' => $id, 'failure' => $failure]);
        } catch (MetaConnectFailure $error) {
            $this->connect->audit('callback_rejected', $error->diagnostic);

            return view('conversation.meta-callback', ['sessionId' => null, 'failure' => $error->getMessage().' ('.$error->diagnostic.')']);
        }
    }

    public function state(Request $request, string $session): JsonResponse
    {
        return $this->result(function () use ($request, $session): array {
            $row = $this->connect->session($request->user(), $session, false);

            return ['sessionId' => $row->id, 'status' => $row->status, 'selection' => json_decode($row->selection ?? '{}', true), 'failureCode' => $row->failure_code, 'message' => MetaConnectFailure::messages()[$row->failure_code] ?? null, 'channel' => $row->completed_at && $row->channel_id ? $this->connect->safeChannel(DB::table('whatsapp_channels')->find($row->channel_id)) : null];
        });
    }

    public function businesses(Request $request, string $session): JsonResponse
    {
        return $this->result(fn () => ['items' => $this->connect->businesses($this->connect->session($request->user(), $session)), 'emptyCode' => 'NO_ELIGIBLE_BUSINESS']);
    }

    public function wabas(Request $request, string $session): JsonResponse
    {
        $data = $request->validate(['businessId' => 'required|regex:/^[0-9]+$/|max:100']);

        return $this->result(fn () => ['items' => $this->connect->wabas($this->connect->session($request->user(), $session), $data['businessId']), 'emptyCode' => 'WABA_NOT_FOUND']);
    }

    public function phones(Request $request, string $session): JsonResponse
    {
        $data = $request->validate(['businessId' => 'required|regex:/^[0-9]+$/|max:100', 'wabaId' => 'required|regex:/^[0-9]+$/|max:100']);

        return $this->result(fn () => ['items' => $this->connect->phones($this->connect->session($request->user(), $session), $data['businessId'], $data['wabaId']), 'emptyCode' => 'PHONE_NOT_FOUND']);
    }

    public function select(Request $request, string $session): JsonResponse
    {
        $data = $request->validate(['businessId' => 'required|regex:/^[0-9]+$/|max:100', 'wabaId' => 'nullable|regex:/^[0-9]+$/|max:100|required_with:phoneNumberId', 'phoneNumberId' => 'nullable|regex:/^[0-9]+$/|max:100|required_with:wabaId']);

        return $this->result(fn () => ['selection' => $this->connect->select($this->connect->session($request->user(), $session), $data)]);
    }

    public function finalize(Request $request, string $session): JsonResponse
    {
        $data = $request->validate(['businessId' => 'required|regex:/^[0-9]+$/|max:100', 'wabaId' => 'required|regex:/^[0-9]+$/|max:100', 'phoneNumberId' => 'required|regex:/^[0-9]+$/|max:100', 'channelName' => 'required|string|max:100', 'confirmNewChannel' => 'nullable|boolean']);

        return $this->result(fn () => ['channel' => $this->connect->safeChannel($this->connect->finalize($request->user(), $this->connect->session($request->user(), $session, false), $data))]);
    }

    public function register(Request $request, string $session): JsonResponse
    {
        $data = $request->validate(['businessId' => 'required|regex:/^[0-9]+$/|max:100', 'wabaId' => 'required|regex:/^[0-9]+$/|max:100', 'phoneNumberId' => 'required|regex:/^[0-9]+$/|max:100', 'pin' => 'required|digits:6']);

        return $this->result(function () use ($request, $session, $data): array {
            $row = $this->connect->session($request->user(), $session);
            $phone = collect($this->connect->phones($row, $data['businessId'], $data['wabaId']))->firstWhere('id', $data['phoneNumberId']);
            if (! $phone) {
                throw new MetaConnectFailure('ASSET_NOT_ALLOWED');
            }
            if (($phone['code_verification_status'] ?? '') !== 'VERIFIED') {
                throw new MetaConnectFailure('PHONE_VERIFICATION_REQUIRED');
            }
            $response = $this->graph->request('POST', $data['phoneNumberId'].'/register', Crypt::decryptString($row->credential), ['messaging_product' => 'whatsapp', 'pin' => $data['pin']]);
            if (! ($response['success'] ?? false)) {
                throw new MetaConnectFailure('PHONE_REGISTRATION_REQUIRED');
            }
            DB::table('meta_auth_sessions')->where('id', $session)->update(['status' => 'ASSET_SELECTION_REQUIRED', 'failure_code' => null]);
            $this->connect->audit('phone_registered', 'SUCCESS', $row->channel_id, ['phone_number_id' => $data['phoneNumberId']]);

            return ['registered' => true];
        });
    }

    public function health(int $channel): JsonResponse
    {
        return $this->result(fn () => ['channel' => $this->connect->safeChannel($this->connect->health(DB::table('whatsapp_channels')->where('provider', 'META_CLOUD_API')->find($channel) ?? abort(404)))]);
    }

    public function disconnect(Request $request, int $channel): JsonResponse
    {
        $request->validate(['confirm' => 'accepted']);

        return $this->result(function () use ($channel): array {
            DB::transaction(function () use ($channel): void {
                DB::table('whatsapp_channels')->where('id', $channel)->where('provider', 'META_CLOUD_API')->lockForUpdate()->first() ?? abort(404);
                if (DB::table('crm_messages')->whereIn('conversation_id', DB::table('crm_conversations')->where('channel_id', $channel)->select('id'))->where('status', 'SENDING')->exists()) {
                    throw new MetaConnectFailure('CONNECTION_BUSY', 409);
                }
                DB::table('whatsapp_channels')->where('id', $channel)->update(['is_active' => false, 'status' => 'DISCONNECTED', 'webhook_ready' => false, 'access_token' => Crypt::encryptString(''), 'updated_at' => now()]);
                DB::table('meta_auth_sessions')->where('channel_id', $channel)->update(['credential' => null, 'status' => 'DISCONNECTED']);
                $this->connect->audit('disconnected', 'DISCONNECTED', $channel);
            });

            return ['channel' => $this->connect->safeChannel(DB::table('whatsapp_channels')->where('provider', 'META_CLOUD_API')->find($channel))];
        });
    }

    public function testMessage(Request $request, int $channel): JsonResponse
    {
        $data = $request->validate(['requestId' => 'required|uuid', 'recipient' => 'required|string|max:30', 'consent' => 'accepted', 'templateId' => 'nullable|integer', 'variables' => 'nullable|array|max:20', 'variables.*' => 'required|string|max:500']);

        return $this->result(function () use ($request, $channel, $data): array {
            $row = DB::table('whatsapp_channels')->where('provider', 'META_CLOUD_API')->find($channel) ?? abort(404);
            if (! $row->is_active || ! $row->webhook_ready || ! in_array($row->status, ['CONNECTED', 'TEST_REQUIRED'], true)) {
                throw new MetaConnectFailure('WEBHOOK_SUBSCRIBE_FAILED');
            }
            $recipient = ltrim(CsRouter::phone($data['recipient']), '+');
            $payload = ['messaging_product' => 'whatsapp', 'to' => $recipient];
            $window = DB::table('crm_conversations')->where('channel_id', $channel)->where('wa_contact_id', $recipient)->where('service_window_expires_at', '>', now())->exists();
            if ($window) {
                $payload += ['type' => 'text', 'text' => ['body' => 'TEST JM — koneksi WhatsApp Juragan Migran berhasil.']];
            } else {
                $template = DB::table('crm_whatsapp_templates')->where('id', $data['templateId'] ?? 0)->where('channel_id', $channel)->where('status', 'APPROVED')->first();
                if (! $template) {
                    throw new MetaConnectFailure('TEST_TEMPLATE_REQUIRED');
                }
                $components = json_decode($template->components, true);
                if (collect($components)->contains(fn ($c) => ! in_array($c['type'], ['BODY', 'FOOTER'], true))) {
                    throw new MetaConnectFailure('TEST_TEMPLATE_REQUIRED');
                }
                $body = collect($components)->firstWhere('type', 'BODY')['text'] ?? '';
                preg_match_all('/\{\{([0-9]+)\}\}/', $body, $matches);
                if (count(array_unique($matches[1])) !== count($data['variables'] ?? [])) {
                    throw new MetaConnectFailure('TEST_TEMPLATE_REQUIRED');
                }
                $payload += ['type' => 'template', 'template' => ['name' => $template->name, 'language' => ['code' => $template->language]]];
                if (! empty($data['variables'])) {
                    $payload['template']['components'] = [['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => $v], $data['variables'])]];
                }
            }
            $created = DB::table('whatsapp_connection_tests')->insertOrIgnore(['id' => $data['requestId'], 'channel_id' => $channel, 'user_id' => $request->user()->id, 'recipient_masked' => '••••'.substr($recipient, -4), 'created_at' => now(), 'updated_at' => now()]);
            if (! $created) {
                $existing = DB::table('whatsapp_connection_tests')->where('id', $data['requestId'])->where('channel_id', $channel)->where('user_id', $request->user()->id)->first() ?? abort(409);

                return ['test' => (array) $existing];
            }
            try {
                $response = $this->graph->request('POST', $row->phone_number_id.'/messages', Crypt::decryptString($row->access_token), $payload);
                $external = $response['messages'][0]['id'] ?? null;
                if (! $external) {
                    throw new MetaConnectFailure('MESSAGE_TEST_FAILED');
                }
                DB::table('whatsapp_connection_tests')->where('id', $data['requestId'])->update(['external_message_id' => $external, 'status' => 'SENT', 'status_at' => now()]);
                DB::table('whatsapp_channels')->where('id', $channel)->update(['outbound_test_at' => now()]);
                $this->connect->audit('outbound_test', 'SENT', $channel);
                app(ConversationCrm::class)->delivery($external, $channel);
            } catch (MetaConnectFailure $error) {
                DB::table('whatsapp_connection_tests')->where('id', $data['requestId'])->update(['status' => $error->diagnostic === 'META_UNAVAILABLE' ? 'UNKNOWN' : 'FAILED', 'error_code' => $error->diagnostic]);
                $this->connect->audit('outbound_test', $error->diagnostic, $channel);
                throw $error;
            }

            return ['test' => (array) DB::table('whatsapp_connection_tests')->find($data['requestId'])];
        });
    }

    public function tests(int $channel): JsonResponse
    {
        $row = DB::table('whatsapp_channels')->where('provider', 'META_CLOUD_API')->find($channel) ?? abort(404);

        return response()->json(['channel' => $this->connect->safeChannel($row), 'tests' => DB::table('whatsapp_connection_tests')->where('channel_id', $channel)->orderByDesc('created_at')->limit(10)->get(['id', 'status', 'error_code', 'recipient_masked', 'created_at']), 'templates' => DB::table('crm_whatsapp_templates')->where('channel_id', $channel)->where('status', 'APPROVED')->get(['id', 'name', 'language', 'components'])]);
    }

    public function routing(Request $request, int $channel): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'team_id' => 'nullable|integer|min:1', 'routing_method' => 'required|in:ROUND_ROBIN,LEAST_ACTIVE,MANUAL', 'prefer_existing_owner' => 'required|boolean', 'strict_owner' => 'required|boolean', 'hours_start' => 'nullable|date_format:H:i|required_with:hours_end', 'hours_end' => 'nullable|date_format:H:i|after:hours_start|required_with:hours_start']);

        return $this->result(function () use ($channel, $data): array {
            $row = $this->connect->health(DB::table('whatsapp_channels')->where('provider', 'META_CLOUD_API')->find($channel) ?? abort(404));
            if (! $row->inbound_test_at || ! $row->outbound_test_at) {
                throw new MetaConnectFailure('TEST_REQUIRED');
            }
            if (! empty($data['team_id']) && ! collect($this->teams())->contains('id', $data['team_id'])) {
                throw new MetaConnectFailure('ASSET_NOT_ALLOWED');
            }
            $values = array_intersect_key($data, array_flip(['name', 'team_id', 'routing_method', 'prefer_existing_owner', 'strict_owner']));
            $values['team_id'] ??= null;
            $values['business_hours'] = ! empty($data['hours_start']) ? json_encode(['days' => [1, 2, 3, 4, 5], 'start' => $data['hours_start'], 'end' => $data['hours_end']]) : null;
            $values += ['status' => 'CONNECTED', 'connected_at' => now(), 'updated_at' => now()];
            DB::table('whatsapp_channels')->where('id', $channel)->where('is_active', true)->update($values);
            $this->connect->audit('routing_configured', 'CONNECTED', $channel);

            return ['channel' => $this->connect->safeChannel(DB::table('whatsapp_channels')->where('provider', 'META_CLOUD_API')->find($channel))];
        });
    }

    public function teams(): array
    {
        return User::where('is_active', true)->whereNotNull('team_id')->get()->filter(fn ($user) => $user->hasPermission('crm.conversation.reply'))->groupBy('team_id')->map(fn ($users, $id) => ['id' => (int) $id, 'name' => 'Tim CS '.$id, 'members' => $users->count()])->values()->all();
    }
}
