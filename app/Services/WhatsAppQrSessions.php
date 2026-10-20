<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WhatsAppQrSessions
{
    public function __construct(private WhatsAppQrGateway $gateway) {}

    public function channel(int $id): object
    {
        return DB::table('whatsapp_channels')->where('id', $id)->where('provider', 'QR_WEB_SESSION')->whereNull('removed_at')->first() ?? abort(404);
    }

    public function audit(int $channel, string $state, ?string $error = null): void
    {
        DB::table('whatsapp_channel_health_events')->insert(['channel_id' => $channel, 'state' => $state, 'error_code' => $error, 'metrics' => '{}', 'created_at' => now()]);
        app(AccessPolicy::class)->audit(auth()->user(), 'whatsapp-qr.'.$state, ['channel_id' => $channel, 'code' => $error]);
    }

    public function lease(int $id, callable $action): mixed
    {
        $owner = (string) Str::uuid();
        $claimed = DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', now()))->update(['lease_owner' => $owner, 'lease_expires_at' => now()->addSeconds(90)]);
        if (! $claimed) {
            throw new WhatsAppProviderFailure('LEASE_BUSY');
        }
        try {
            return $action();
        } finally {
            DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->where('lease_owner', $owner)->update(['lease_owner' => null, 'lease_expires_at' => null]);
        }
    }

    public function create(string $name): object
    {
        if (! $this->gateway->ready()) {
            throw new WhatsAppProviderFailure('QR_NOT_CONFIGURED');
        }
        if (! config('whatsapp-qr.multiple_sessions') && DB::table('whatsapp_qr_sessions')->whereIn('channel_id', DB::table('whatsapp_channels')->whereNull('removed_at')->select('id'))->exists()) {
            throw new WhatsAppProviderFailure('CHANNEL_CAPACITY');
        }
        $id = DB::transaction(function () use ($name): int {
            $id = DB::table('whatsapp_channels')->insertGetId(['name' => $name, 'provider' => 'QR_WEB_SESSION', 'connection_method' => 'QR_COMPATIBILITY', 'waba_id' => '', 'phone_number_id' => 'qr-'.Str::uuid(), 'display_phone_number' => '', 'access_token' => Crypt::encryptString(''), 'status' => 'NOT_CONNECTED', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('whatsapp_qr_sessions')->insert(['channel_id' => $id, 'gateway_session' => config('whatsapp-qr.multiple_sessions') ? 'jm_'.str_replace('-', '', (string) Str::uuid()) : 'default', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($id, 'created');

            return $id;
        });

        return $this->channel($id);
    }

    public function start(int $id, int $user, bool $refresh = false): array
    {
        $channel = $this->channel($id);
        if (! $this->gateway->ready()) {
            throw new WhatsAppProviderFailure('QR_NOT_CONFIGURED');
        }

        return $this->lease($id, function () use ($id, $user, $refresh, $channel): array {
            $session = DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->first();
            if (! $refresh && $session->pairing_id && $session->expires_at && now()->lt($session->expires_at)) {
                return $this->safe($channel, $session);
            }
            if ($session->state === 'CONNECTED' && ! $refresh) {
                return $this->safe($channel, $session);
            }
            if (! $refresh && $session->webhook_credential) {
                throw new WhatsAppProviderFailure('QR_EXPIRED');
            }
            if ($refresh && $session->state === 'CONNECTED') {
                throw new WhatsAppProviderFailure('LEASE_BUSY');
            }
            if ($session->webhook_credential || $session->cleanup_pending) {
                $this->gateway->disconnect($channel);
            }
            $secret = hash_hmac('sha256', (string) Str::uuid(), config('whatsapp-qr.webhook_secret'));
            $values = ['gateway_session' => config('whatsapp-qr.multiple_sessions') ? 'jm_'.str_replace('-', '', (string) Str::uuid()) : 'default', 'webhook_credential' => Crypt::encryptString($secret), 'pairing_id' => (string) Str::uuid(), 'pairing_user_id' => $user, 'state' => 'QR_GENERATING', 'expires_at' => null, 'activated' => false, 'reauth_required' => false, 'cleanup_pending' => false, 'last_error_code' => null, 'reconnect_attempts' => 0, 'updated_at' => now()];
            DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->update($values);
            DB::table('whatsapp_channels')->where('id', $id)->update(['status' => 'QR_GENERATING', 'is_active' => false]);
            try {
                $this->gateway->create($values['gateway_session'], $secret);
                $this->audit($id, 'qr_started');
            } catch (WhatsAppProviderFailure $error) {
                $this->state($id, 'FAILED', $error->diagnostic);
                throw $error;
            }

            return $this->refresh($id);
        });
    }

    public function state(int $id, string $state, ?string $error = null): void
    {
        $session = DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->first();
        if (! $session) {
            return;
        }
        DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->update(['state' => $state, 'last_error_code' => $error, 'reauth_required' => in_array($state, ['REAUTH_REQUIRED', 'SESSION_INVALID', 'DISCONNECTED_BY_PHONE'], true), 'updated_at' => now()]);
        DB::table('whatsapp_channels')->where('id', $id)->where('provider', 'QR_WEB_SESSION')->update(['status' => $state === 'CONNECTED' ? ($session->activated ? 'CONNECTED' : 'TEST_REQUIRED') : $state, 'last_error' => $error, 'updated_at' => now()]);
        if ($session->state !== $state || $session->last_error_code !== $error) {
            $this->audit($id, $state, $error);
        }
    }

    public function refresh(int $id): array
    {
        $channel = $this->channel($id);
        $session = DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->first();
        if (! $session->webhook_credential || $session->cleanup_pending || $session->state === 'DISCONNECTED_BY_ADMIN') {
            return $this->safe($channel, $session);
        }
        try {
            $result = $this->gateway->getHealth($channel);
            $values = ['last_heartbeat_at' => now(), 'last_error_code' => null];
            if ($result['state'] === 'CONNECTED') {
                if (! preg_match('/^([0-9]{7,15})@(c\.us|s\.whatsapp\.net)$/', $result['account'] ?? '', $matches)) {
                    throw new WhatsAppProviderFailure('REAUTH_REQUIRED');
                }
                $phone = CsRouter::phone($matches[1]);
                $duplicate = DB::table('whatsapp_channels')->where('id', '!=', $id)->where('is_active', true)->where('display_phone_number', $phone)->exists();
                if ($duplicate) {
                    $this->gateway->disconnect($channel);
                    throw new WhatsAppProviderFailure('DUPLICATE_PHONE');
                }
                $values += ['paired_at' => $session->paired_at ?: now(), 'expires_at' => null, 'pairing_id' => null, 'reconnect_attempts' => 0, 'next_retry_at' => null];
                DB::table('whatsapp_channels')->where('id', $id)->update(['display_phone_number' => $phone, 'verified_name' => $result['name'], 'is_active' => true]);
            } elseif ($result['state'] === 'WAITING_FOR_SCAN') {
                if ($session->expires_at && now()->gte($session->expires_at)) {
                    $result['state'] = 'QR_EXPIRED';
                } elseif (! $session->expires_at) {
                    $values['expires_at'] = now()->addSeconds(config('whatsapp-qr.pairing_seconds'));
                }
            }
            DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->update($values);
            $this->state($id, $result['state']);
        } catch (WhatsAppProviderFailure $error) {
            $this->state($id, $error->diagnostic === 'REAUTH_REQUIRED' ? 'REAUTH_REQUIRED' : 'DEGRADED', $error->diagnostic);
        }

        return $this->safe($this->channel($id), DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->first());
    }

    public function safe(object $channel, object $session): array
    {
        $expired = $session->expires_at && now()->gte($session->expires_at);
        $state = $expired && $session->state === 'WAITING_FOR_SCAN' ? 'QR_EXPIRED' : $session->state;
        $stale = ! $session->last_heartbeat_at || now()->diffInSeconds($session->last_heartbeat_at, true) > config('whatsapp-qr.heartbeat_seconds');

        return ['channel' => ['id' => $channel->id, 'name' => $channel->name, 'provider' => 'QR_WEB_SESSION', 'phone' => $channel->display_phone_number, 'displayName' => $channel->verified_name, 'teamId' => $channel->team_id, 'routing' => $channel->routing_method, 'status' => $channel->status, 'active' => (bool) $session->activated], 'state' => $state, 'health' => ! config('whatsapp-qr.enabled') ? 'MAINTENANCE' : ($stale ? 'OFFLINE' : ($state === 'CONNECTED' ? 'HEALTHY' : ($session->reauth_required ? 'REAUTH_REQUIRED' : 'DEGRADED'))), 'pairingSessionId' => $session->pairing_id, 'expiresAt' => $session->expires_at ? Carbon::parse($session->expires_at)->toIso8601String() : null, 'qrImageUrl' => $state === 'WAITING_FOR_SCAN' && $session->pairing_id ? route('whatsapp-qr.image', $session->pairing_id) : null, 'lastHeartbeatAt' => $session->last_heartbeat_at, 'lastInboundAt' => $session->last_inbound_at, 'lastOutboundAt' => $session->last_outbound_at, 'reconnectAttempts' => $session->reconnect_attempts, 'errorCode' => $session->last_error_code, 'cleanupPending' => (bool) $session->cleanup_pending, 'queueBacklog' => DB::table('crm_messages')->whereIn('conversation_id', DB::table('crm_conversations')->where('channel_id', $channel->id)->select('id'))->whereIn('status', ['QUEUED', 'SENDING'])->count()];
    }

    public function remove(int $id): array
    {
        $this->channel($id);

        return $this->lease($id, function () use ($id): array {
            return DB::transaction(function () use ($id): array {
                $channel = DB::table('whatsapp_channels')->where('id', $id)->whereNull('removed_at')->lockForUpdate()->first() ?? abort(404);
                $session = DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->lockForUpdate()->first();
                abort_unless(! $channel->is_active && $channel->status === 'DISCONNECTED_BY_ADMIN' && $session && $session->state === 'DISCONNECTED_BY_ADMIN' && ! $session->cleanup_pending && ! $session->webhook_credential, 422, 'Putuskan koneksi dan tunggu pembersihan sesi selesai sebelum menghapus nomor.');
                DB::table('whatsapp_channels')->where('id', $id)->update(['removed_at' => now(), 'updated_at' => now()]);
                DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->update(['gateway_session' => 'removed_'.$id.'_'.Str::uuid(), 'updated_at' => now()]);
                $this->audit($id, 'CHANNEL_REMOVED');

                return ['removed' => true];
            });
        });
    }

    public function disconnect(int $id): array
    {
        $channel = $this->channel($id);

        return $this->lease($id, function () use ($id, $channel): array {
            DB::transaction(function () use ($id): void {
                DB::table('whatsapp_channels')->where('id', $id)->lockForUpdate()->first();
                if (DB::table('crm_messages')->whereIn('conversation_id', DB::table('crm_conversations')->where('channel_id', $id)->select('id'))->where('status', 'SENDING')->exists()) {
                    throw new WhatsAppProviderFailure('LEASE_BUSY');
                }
                DB::table('whatsapp_channels')->where('id', $id)->update(['is_active' => false, 'status' => 'DISCONNECTED_BY_ADMIN']);
                DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->update(['state' => 'DISCONNECTED_BY_ADMIN', 'pairing_id' => null, 'expires_at' => null, 'activated' => false, 'webhook_credential' => null, 'cleanup_pending' => true]);
                $this->audit($id, 'DISCONNECTED_BY_ADMIN');
            });
            try {
                $this->gateway->disconnect($channel);
                DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->update(['cleanup_pending' => false]);
            } catch (WhatsAppProviderFailure) {
                $this->audit($id, 'cleanup_pending', 'WORKER_OFFLINE');
            }

            return $this->safe($this->channel($id), DB::table('whatsapp_qr_sessions')->where('channel_id', $id)->first());
        });
    }
}
