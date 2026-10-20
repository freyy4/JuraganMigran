<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MetaConnect
{
    public function __construct(private MetaGraph $graph) {}

    public function redirectUri(): string
    {
        return config('meta-connect.redirect_uri') ?: route('meta-connect.callback');
    }

    public function preflight(): array
    {
        $ready = (bool) (config('conversation.app_id') && config('conversation.app_secret') && config('conversation.verify_token') && config('app.key') && preg_match('/^v[0-9]+\.[0-9]+$/', config('conversation.graph_version')) && parse_url($this->redirectUri(), PHP_URL_SCHEME) === 'https' && $this->redirectUri() === route('meta-connect.callback') && parse_url(route('whatsapp.receive'), PHP_URL_SCHEME) === 'https');

        return ['ready' => $ready, 'code' => $ready ? null : 'META_APP_NOT_CONFIGURED', 'message' => $ready ? 'Siap untuk login Meta.' : MetaConnectFailure::messages()['META_APP_NOT_CONFIGURED']];
    }

    public function audit(string $operation, string $result, ?int $channel = null, array $metadata = []): void
    {
        DB::table('whatsapp_connection_audits')->insert(['channel_id' => $channel, 'actor_user_id' => auth()->id(), 'operation' => $operation, 'result' => $result, 'metadata' => json_encode($metadata), 'created_at' => now()]);
        app(AccessPolicy::class)->audit(auth()->user(), 'meta-connect.'.$operation, ['result' => $result, 'channel_id' => $channel] + $metadata);
    }

    public function start(User $user, ?int $channel): array
    {
        if (! $this->preflight()['ready']) {
            throw new MetaConnectFailure('META_APP_NOT_CONFIGURED');
        }
        if ($channel) {
            $existing = DB::table('whatsapp_channels')->find($channel) ?? abort(404);
            if ($existing->provider !== 'META_CLOUD_API') {
                throw new MetaConnectFailure('ASSET_NOT_ALLOWED');
            }
        }
        $state = Str::random(64);
        $id = (string) Str::uuid();
        DB::table('meta_auth_sessions')->insert(['id' => $id, 'user_id' => $user->id, 'channel_id' => $channel, 'state_hash' => hash('sha256', $state), 'status' => 'AUTH_PENDING', 'expires_at' => now()->addMinutes(config('meta-connect.session_minutes')), 'auth_expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);
        $url = 'https://www.facebook.com/'.config('conversation.graph_version').'/dialog/oauth?'.http_build_query(['client_id' => config('conversation.app_id'), 'redirect_uri' => $this->redirectUri(), 'response_type' => 'code', 'scope' => implode(',', config('meta-connect.permissions')), 'state' => $state, 'auth_type' => 'rerequest']);
        $this->audit('auth_started', 'AUTH_PENDING', $channel, ['session_id' => $id]);

        return ['sessionId' => $id, 'authorizationUrl' => $url, 'expiresAt' => now()->addMinutes(15)->toIso8601String()];
    }

    public function callback(User $user, string $state, ?string $code, bool $cancelled): string
    {
        $session = DB::transaction(function () use ($user, $state): object {
            $session = DB::table('meta_auth_sessions')->where('state_hash', hash('sha256', $state))->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $session || $session->status !== 'AUTH_PENDING' || now()->gte($session->auth_expires_at)) {
                throw new MetaConnectFailure('AUTH_STATE_INVALID');
            }
            DB::table('meta_auth_sessions')->where('id', $session->id)->update(['status' => 'EXCHANGING', 'updated_at' => now()]);

            return $session;
        });
        try {
            if ($cancelled || ! $code) {
                throw new MetaConnectFailure('AUTH_CANCELLED');
            }
            $response = $this->graph->request('GET', 'oauth/access_token', '', ['client_id' => config('conversation.app_id'), 'client_secret' => config('conversation.app_secret'), 'redirect_uri' => $this->redirectUri(), 'code' => $code]);
            $token = $response['access_token'] ?? null;
            if (! $token) {
                throw new MetaConnectFailure('REAUTH_REQUIRED');
            }
            $debug = $this->graph->request('GET', 'debug_token', config('conversation.app_id').'|'.config('conversation.app_secret'), ['input_token' => $token]);
            if (! ($debug['data']['is_valid'] ?? false) || (string) ($debug['data']['app_id'] ?? '') !== (string) config('conversation.app_id')) {
                throw new MetaConnectFailure('REAUTH_REQUIRED');
            }
            $expiry = (int) ($debug['data']['expires_at'] ?? 0);
            DB::table('meta_auth_sessions')->where('id', $session->id)->update(['credential' => Crypt::encryptString($token), 'credential_expires_at' => $expiry ? Carbon::createFromTimestampUTC($expiry) : null, 'status' => 'ASSET_SELECTION_REQUIRED', 'updated_at' => now()]);
            $this->graph->permissions($token);
            $this->audit('auth_completed', 'AUTH_SUCCESS', $session->channel_id, ['session_id' => $session->id]);
        } catch (MetaConnectFailure $error) {
            DB::table('meta_auth_sessions')->where('id', $session->id)->update(['status' => $error->diagnostic === 'META_PERMISSION_REQUIRED' ? 'PERMISSION_REQUIRED' : 'FAILED', 'failure_code' => $error->diagnostic, 'updated_at' => now()]);
            $this->audit('auth_completed', $error->diagnostic, $session->channel_id);
        }

        return $session->id;
    }

    public function session(User $user, string $id, bool $credential = true): object
    {
        $session = DB::table('meta_auth_sessions')->where('id', $id)->where('user_id', $user->id)->first() ?? abort(404);
        if (now()->gte($session->expires_at)) {
            throw new MetaConnectFailure('AUTH_SESSION_EXPIRED');
        }
        if ($credential && (! $session->credential || ($session->credential_expires_at && now()->gte($session->credential_expires_at)))) {
            throw new MetaConnectFailure('REAUTH_REQUIRED');
        }

        return $session;
    }

    public function businesses(object $session): array
    {
        $items = $this->graph->listing('me/businesses', Crypt::decryptString($session->credential), ['fields' => 'id,name']);

        return array_map(fn ($item) => ['id' => (string) $item['id'], 'name' => $item['name']], $items);
    }

    public function wabas(object $session, string $business): array
    {
        if (! collect($this->businesses($session))->contains('id', $business)) {
            throw new MetaConnectFailure('ASSET_NOT_ALLOWED');
        }

        return array_map(fn ($item) => ['id' => (string) $item['id'], 'name' => $item['name'] ?? 'WhatsApp Business'], $this->graph->listing($business.'/owned_whatsapp_business_accounts', Crypt::decryptString($session->credential), ['fields' => 'id,name']));
    }

    public function phones(object $session, string $business, string $waba): array
    {
        if (! collect($this->wabas($session, $business))->contains('id', $waba)) {
            throw new MetaConnectFailure('ASSET_NOT_ALLOWED');
        }
        $rows = $this->graph->listing($waba.'/phone_numbers', Crypt::decryptString($session->credential), ['fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status,platform_type']);

        return array_map(fn ($item) => array_intersect_key($item, array_flip(['id', 'display_phone_number', 'verified_name', 'quality_rating', 'code_verification_status', 'platform_type'])), $rows);
    }

    public function select(object $session, array $selection): array
    {
        if (! collect($this->businesses($session))->contains('id', $selection['businessId'])) {
            throw new MetaConnectFailure('ASSET_NOT_ALLOWED');
        }
        if (! empty($selection['wabaId']) && ! collect($this->wabas($session, $selection['businessId']))->contains('id', $selection['wabaId'])) {
            throw new MetaConnectFailure('ASSET_NOT_ALLOWED');
        }
        if (! empty($selection['phoneNumberId']) && ! collect($this->phones($session, $selection['businessId'], $selection['wabaId']))->contains('id', $selection['phoneNumberId'])) {
            throw new MetaConnectFailure('ASSET_NOT_ALLOWED');
        }
        DB::table('meta_auth_sessions')->where('id', $session->id)->update(['selection' => json_encode($selection), 'updated_at' => now()]);
        $this->audit('asset_selected', 'SUCCESS', $session->channel_id, ['session_id' => $session->id]);

        return $selection;
    }

    private function webhook(string $token, string $waba, bool $subscribe): void
    {
        if ($subscribe) {
            $response = $this->graph->request('POST', $waba.'/subscribed_apps', $token);
            if (! ($response['success'] ?? false)) {
                throw new MetaConnectFailure('WEBHOOK_SUBSCRIBE_FAILED');
            }
        }
        $apps = $this->graph->listing($waba.'/subscribed_apps', $token);
        if (! collect($apps)->contains(fn ($app) => (string) ($app['whatsapp_business_api_data']['id'] ?? $app['id'] ?? '') === (string) config('conversation.app_id'))) {
            throw new MetaConnectFailure('WEBHOOK_SUBSCRIBE_FAILED');
        }
        $subscriptions = $this->graph->listing(config('conversation.app_id').'/subscriptions', config('conversation.app_id').'|'.config('conversation.app_secret'));
        $valid = collect($subscriptions)->contains(function ($subscription): bool {
            $fields = array_map(fn ($field) => is_array($field) ? ($field['name'] ?? '') : $field, $subscription['fields'] ?? []);

            return ($subscription['object'] ?? '') === 'whatsapp_business_account' && ($subscription['active'] ?? false) && rtrim($subscription['callback_url'] ?? '', '/') === rtrim(route('whatsapp.receive'), '/') && in_array('messages', $fields, true);
        });
        if (! $valid) {
            throw new MetaConnectFailure('WEBHOOK_SUBSCRIBE_FAILED');
        }
    }

    public function finalize(User $user, object $session, array $data): object
    {
        if ($session->completed_at && $session->channel_id) {
            return DB::table('whatsapp_channels')->find($session->channel_id);
        }
        if (! $session->credential || ($session->credential_expires_at && now()->gte($session->credential_expires_at)) || in_array($session->status, ['FAILED', 'DISCONNECTED', 'PERMISSION_REQUIRED', 'REAUTH_REQUIRED'], true)) {
            throw new MetaConnectFailure('REAUTH_REQUIRED');
        }
        $claimed = DB::table('meta_auth_sessions')->where('id', $session->id)->where(fn ($q) => $q->whereNull('processing_at')->orWhere('processing_at', '<', now()->subMinutes(2)))->update(['processing_at' => now(), 'status' => 'WEBHOOK_SETUP']);
        if (! $claimed) {
            throw new MetaConnectFailure('CONNECTION_BUSY', 409);
        }
        try {
            $token = Crypt::decryptString($session->credential);
            $permissions = $this->graph->permissions($token);
            $phone = collect($this->phones($session, $data['businessId'], $data['wabaId']))->firstWhere('id', $data['phoneNumberId']);
            if (! $phone) {
                throw new MetaConnectFailure('ASSET_NOT_ALLOWED');
            }
            if (($phone['code_verification_status'] ?? '') !== 'VERIFIED') {
                throw new MetaConnectFailure('PHONE_VERIFICATION_REQUIRED');
            }
            if (($phone['platform_type'] ?? '') !== 'CLOUD_API') {
                throw new MetaConnectFailure('PHONE_REGISTRATION_REQUIRED');
            }
            $previous = $session->channel_id ? DB::table('whatsapp_channels')->find($session->channel_id) : null;
            if ($previous && $previous->phone_number_id !== $data['phoneNumberId'] && ! ($data['confirmNewChannel'] ?? false)) {
                throw new MetaConnectFailure('ASSET_CHANGE_CONFIRMATION_REQUIRED', 409);
            }
            $this->webhook($token, $data['wabaId'], true);
            $channel = DB::transaction(function () use ($session, $user, $data, $phone, $permissions): object {
                $current = DB::table('meta_auth_sessions')->where('id', $session->id)->lockForUpdate()->first();
                if (! $current->credential || $current->status === 'DISCONNECTED') {
                    throw new MetaConnectFailure('REAUTH_REQUIRED');
                }
                $existing = DB::table('whatsapp_channels')->where('phone_number_id', $data['phoneNumberId'])->lockForUpdate()->first();
                $values = ['business_id' => $data['businessId'], 'waba_id' => $data['wabaId'], 'phone_number_id' => $data['phoneNumberId'], 'display_phone_number' => $phone['display_phone_number'], 'verified_name' => $phone['verified_name'] ?? null, 'quality_rating' => $phone['quality_rating'] ?? null, 'access_token' => $session->credential, 'credential_expires_at' => $session->credential_expires_at, 'permission_snapshot' => json_encode($permissions), 'webhook_ready' => true, 'webhook_verified_at' => now(), 'connection_method' => 'META_CONNECT', 'connected_by' => $user->id, 'last_health_check_at' => now(), 'last_error' => null, 'is_active' => true, 'updated_at' => now()];
                $values['status'] = $existing?->status === 'CONNECTED' ? 'CONNECTED' : 'TEST_REQUIRED';
                if ($existing) {
                    DB::table('whatsapp_channels')->where('id', $existing->id)->update($values);
                    $id = $existing->id;
                } else {
                    $id = DB::table('whatsapp_channels')->insertGetId($values + ['name' => $data['channelName'], 'created_at' => now()]);
                }
                DB::table('meta_auth_sessions')->where('id', $session->id)->update(['channel_id' => $id, 'status' => $values['status'], 'selection' => json_encode(array_intersect_key($data, array_flip(['businessId', 'wabaId', 'phoneNumberId']))), 'credential' => null, 'completed_at' => now(), 'failure_code' => null]);
                $this->audit('connection_finalized', $values['status'], $id);

                return DB::table('whatsapp_channels')->find($id);
            });
            try {
                app(WhatsAppCloud::class)->templates($channel);
            } catch (\Throwable) {
                $this->audit('template_sync', 'UNAVAILABLE', $channel->id);
            }

            return $channel;
        } catch (MetaConnectFailure $error) {
            DB::table('meta_auth_sessions')->where('id', $session->id)->update(['status' => match ($error->diagnostic) {
                'PHONE_REGISTRATION_REQUIRED','PHONE_VERIFICATION_REQUIRED' => $error->diagnostic, 'META_PERMISSION_REQUIRED' => 'PERMISSION_REQUIRED', 'REAUTH_REQUIRED' => 'REAUTH_REQUIRED', default => 'ASSET_SELECTION_REQUIRED'
            }, 'failure_code' => $error->diagnostic]);
            $this->audit('connection_finalized', $error->diagnostic, $session->channel_id);
            throw $error;
        } finally {
            DB::table('meta_auth_sessions')->where('id', $session->id)->update(['processing_at' => null]);
        }
    }

    public function health(object $channel): object
    {
        if (($channel->provider ?? 'META_CLOUD_API') !== 'META_CLOUD_API') {
            throw new MetaConnectFailure('ASSET_NOT_ALLOWED');
        }
        if (! $channel->is_active || $channel->status === 'DISCONNECTED') {
            throw new MetaConnectFailure('CHANNEL_DISCONNECTED');
        }
        try {
            if ($channel->credential_expires_at && now()->gte($channel->credential_expires_at)) {
                throw new MetaConnectFailure('REAUTH_REQUIRED');
            }
            $token = Crypt::decryptString($channel->access_token);
            $permissions = $this->graph->permissions($token);
            $phone = $this->graph->request('GET', $channel->phone_number_id, $token, ['fields' => 'id,verified_name,quality_rating,code_verification_status,platform_type']);
            if (($phone['code_verification_status'] ?? '') !== 'VERIFIED') {
                throw new MetaConnectFailure('PHONE_VERIFICATION_REQUIRED');
            }
            if (($phone['platform_type'] ?? '') !== 'CLOUD_API') {
                throw new MetaConnectFailure('PHONE_REGISTRATION_REQUIRED');
            }
            $this->webhook($token, $channel->waba_id, false);
            $status = $channel->inbound_test_at && $channel->outbound_test_at && $channel->connected_at ? 'CONNECTED' : 'TEST_REQUIRED';
            DB::table('whatsapp_channels')->where('id', $channel->id)->where('is_active', true)->update(['status' => $status, 'webhook_ready' => true, 'webhook_verified_at' => now(), 'permission_snapshot' => json_encode($permissions), 'quality_rating' => $phone['quality_rating'] ?? null, 'verified_name' => $phone['verified_name'] ?? null, 'last_error' => null, 'last_health_check_at' => now()]);
            $this->audit('health_checked', $status, $channel->id);
        } catch (MetaConnectFailure $error) {
            DB::table('whatsapp_channels')->where('id', $channel->id)->where('is_active', true)->update(['status' => $error->diagnostic === 'REAUTH_REQUIRED' ? 'REAUTH_REQUIRED' : 'DEGRADED', 'webhook_ready' => false, 'last_error' => $error->diagnostic, 'last_health_check_at' => now()]);
            $this->audit('health_checked', $error->diagnostic, $channel->id);
            throw $error;
        }

        return DB::table('whatsapp_channels')->find($channel->id);
    }

    public function safeChannel(object $channel): array
    {
        return array_intersect_key((array) $channel, array_flip(['id', 'name', 'display_phone_number', 'verified_name', 'quality_rating', 'status', 'connection_method', 'team_id', 'routing_method', 'prefer_existing_owner', 'strict_owner', 'business_hours', 'is_active', 'webhook_ready', 'last_webhook_at', 'last_health_check_at', 'last_error', 'inbound_test_at', 'outbound_test_at', 'connected_at']));
    }
}
