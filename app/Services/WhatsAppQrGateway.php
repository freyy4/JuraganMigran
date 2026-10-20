<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class WhatsAppQrGateway implements WhatsAppProviderAdapter
{
    public function ready(): bool
    {
        $url = parse_url((string) config('whatsapp-qr.url'));

        return (bool) (config('whatsapp-qr.enabled') && config('whatsapp-qr.encrypted_storage') && config('whatsapp-qr.api_key') && config('whatsapp-qr.webhook_secret') && $url && empty($url['user']) && empty($url['query']) && (($url['scheme'] ?? '') === 'https' || (($url['scheme'] ?? '') === 'http' && in_array($url['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true))));
    }

    public function request(string $method, string $path, array $data = [], bool $cleanup = false): Response
    {
        if ((! $cleanup && ! $this->ready()) || ! config('whatsapp-qr.url') || ! config('whatsapp-qr.api_key')) {
            throw new WhatsAppProviderFailure('QR_NOT_CONFIGURED');
        }
        try {
            $response = Http::withHeaders(['X-Api-Key' => config('whatsapp-qr.api_key')])->acceptJson()->timeout(15)->withoutRedirecting()->send($method, rtrim(config('whatsapp-qr.url'), '/').'/api/'.$path, ['json' => $data]);
        } catch (ConnectionException) {
            throw new WhatsAppProviderFailure('WORKER_OFFLINE', $method === 'POST' && str_starts_with($path, 'send'));
        }
        if (! $response->successful() && ! ($cleanup && $response->status() === 404)) {
            throw new WhatsAppProviderFailure('GATEWAY_UNAVAILABLE', $method === 'POST' && str_starts_with($path, 'send') && $response->serverError());
        }

        return $response;
    }

    public function create(string $session, string $secret): void
    {
        $this->request('POST', 'sessions', ['name' => $session, 'start' => true, 'config' => ['debug' => false, 'ignore' => ['groups' => true, 'channels' => true, 'status' => true], 'webhooks' => [['url' => route('whatsapp-qr.webhook'), 'events' => ['message', 'message.ack', 'session.status'], 'hmac' => ['key' => $secret], 'retries' => ['policy' => 'exponential', 'delaySeconds' => 2, 'attempts' => 5]]]]]);
    }

    public function getHealth(object $channel): array
    {
        $session = DB::table('whatsapp_qr_sessions')->where('channel_id', $channel->id)->first() ?? throw new WhatsAppProviderFailure('REAUTH_REQUIRED');
        $result = $this->request('GET', 'sessions/'.$session->gateway_session)->json();

        return ['state' => match ($result['status'] ?? '') {
            'WORKING' => 'CONNECTED', 'SCAN_QR_CODE' => 'WAITING_FOR_SCAN', 'STARTING' => 'PAIRING', 'STOPPED' => 'RECONNECTING', 'FAILED' => 'REAUTH_REQUIRED', default => 'DEGRADED'
        }, 'account' => $result['me']['id'] ?? null, 'name' => mb_substr($result['me']['pushName'] ?? '', 0, 100)];
    }

    public function qr(object $session): string
    {
        $result = $this->request('GET', $session->gateway_session.'/auth/qr')->json();
        $data = base64_decode($result['data'] ?? '', true);
        if (($result['mimetype'] ?? '') !== 'image/png' || ! $data || strlen($data) > 200000 || ! str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            throw new WhatsAppProviderFailure('QR_EXPIRED');
        }

        return $data;
    }

    public function disconnect(object $channel): void
    {
        $session = DB::table('whatsapp_qr_sessions')->where('channel_id', $channel->id)->first();
        if ($session) {
            $this->request('DELETE', 'sessions/'.$session->gateway_session, [], true);
        }
    }

    public function sendMessage(object $channel, array $payload): string
    {
        return app(WhatsAppQrSessions::class)->lease($channel->id, fn () => $this->sendLeased($channel, $payload));
    }

    private function sendLeased(object $channel, array $payload): string
    {
        $session = DB::table('whatsapp_qr_sessions')->where('channel_id', $channel->id)->first();
        if (! $this->ready() || ! $session || $session->state !== 'CONNECTED' || ! $session->activated || ! $session->last_heartbeat_at || now()->diffInSeconds($session->last_heartbeat_at, true) > config('whatsapp-qr.heartbeat_seconds')) {
            throw new WhatsAppProviderFailure('REAUTH_REQUIRED');
        }
        $data = ['session' => $session->gateway_session, 'chatId' => $payload['to'].'@c.us'];
        if ($payload['type'] === 'text') {
            $path = 'sendText';
            $data['text'] = $payload['text']['body'];
        } elseif (in_array($payload['type'], ['image', 'document'], true) && isset($payload['_local_media'])) {
            $media = $payload['_local_media'];
            if (! str_starts_with($media['path'], 'crm-outbox/') || ! Storage::disk('local')->exists($media['path']) || Storage::disk('local')->size($media['path']) > 10485760) {
                throw new WhatsAppProviderFailure('ATTACHMENT_UNAVAILABLE');
            }
            $path = $payload['type'] === 'image' ? 'sendImage' : 'sendFile';
            $data['file'] = ['mimetype' => $media['mime'], 'filename' => $payload['type'] === 'image' ? 'image' : 'informasi.pdf', 'data' => base64_encode(Storage::disk('local')->get($media['path']))];
            $data['caption'] = $payload[$payload['type']]['caption'] ?? '';
        } else {
            throw new WhatsAppProviderFailure('PROVIDER_MISMATCH');
        }
        $id = $this->request('POST', $path, $data)->json('id');
        if (! is_string($id) || $id === '') {
            throw new WhatsAppProviderFailure('DELIVERY_UNCERTAIN', true);
        }

        return $id;
    }

    public function storeMedia(array $event): array
    {
        if (empty($event['media']['gateway_url'])) {
            return $event;
        }
        $external = 'qr:'.$event['channel_id'].':'.hash('sha256', $event['external']);
        if (DB::table('crm_messages')->where('external_message_id', $external)->exists()) {
            unset($event['media']);

            return $event;
        }
        $url = $event['media']['gateway_url'];
        $base = rtrim((string) config('whatsapp-qr.url'), '/');
        if (! str_starts_with($url, $base.'/api/files/') || str_contains($url, '..') || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT)) {
            throw new WhatsAppProviderFailure('ATTACHMENT_UNAVAILABLE');
        }
        try {
            $response = Http::withHeaders(['X-Api-Key' => config('whatsapp-qr.api_key')])->timeout(20)->withoutRedirecting()->get($url);
        } catch (ConnectionException) {
            throw new WhatsAppProviderFailure('ATTACHMENT_UNAVAILABLE');
        }
        $data = $response->body();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
        if (! $response->successful() || strlen($data) > 10485760 || ! in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true) || $mime !== $event['media']['mime_type']) {
            throw new WhatsAppProviderFailure('ATTACHMENT_UNAVAILABLE');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'jm-qr-media-');
        file_put_contents($temporary, $data);
        try {
            app(DocumentScanner::class)->inspect(new UploadedFile($temporary, 'attachment', $mime, null, true), 'attachment');
        } finally {
            @unlink($temporary);
        }
        $path = 'crm-inbound/'.hash('sha256', $external);
        Storage::disk('local')->put($path, $data);
        $event['media'] = ['local_path' => $path, 'mime_type' => $mime];

        return $event;
    }

    public function normalizeInbound(array $payload): array
    {
        $session = DB::table('whatsapp_qr_sessions')->where('gateway_session', $payload['session'] ?? '')->first();
        if (! $session) {
            return [];
        }
        $body = $payload['payload'] ?? [];
        if (($payload['event'] ?? '') === 'message.ack') {
            $status = match ((int) ($body['ack'] ?? 0)) {
                -1 => 'FAILED', 1 => 'SENT', 2 => 'DELIVERED', 3, 4 => 'READ', default => null
            };

            return $status && ! empty($body['id']) ? [['kind' => 'status', 'channel_id' => $session->channel_id, 'provider' => 'QR_WEB_SESSION', 'external' => $body['id'], 'status' => $status, 'timestamp' => (int) (($payload['timestamp'] ?? now()->getTimestampMs()) / 1000)]] : [];
        }
        if (($payload['event'] ?? '') !== 'message' || ! empty($body['fromMe']) || empty($body['id'])) {
            return [];
        }
        $sender = $body['from'] ?? '';
        if (preg_match('/^[0-9]{1,30}@lid$/', $sender)) {
            $mapping = $this->request('GET', $session->gateway_session.'/lids/'.rawurlencode($sender))->json();
            if (($mapping['lid'] ?? null) !== $sender || ! preg_match('/^[0-9]{7,15}@(c\.us|s\.whatsapp\.net)$/', $mapping['pn'] ?? '')) {
                throw new WhatsAppProviderFailure('CONTACT_UNRESOLVED');
            }
            $sender = $mapping['pn'];
        }
        if (! preg_match('/^([0-9]{7,15})@(c\.us|s\.whatsapp\.net)$/', $sender, $matches)) {
            return [];
        }
        $media = $body['media'] ?? null;
        $type = match ($media['mimetype'] ?? '') {
            'image/jpeg', 'image/png' => 'image', 'application/pdf' => 'document', default => ! empty($body['hasMedia']) ? 'attachment' : 'text'
        };

        return [['media' => in_array($type, ['image', 'document'], true) && ! empty($media['url']) ? ['gateway_url' => $media['url'], 'mime_type' => $media['mimetype']] : null, 'kind' => 'message', 'channel_id' => $session->channel_id, 'provider' => 'QR_WEB_SESSION', 'external' => $body['id'], 'from' => $matches[1], 'name' => mb_substr($body['_data']['notifyName'] ?? $matches[1], 0, 255), 'text' => mb_substr($body['body'] ?? '', 0, 10000), 'type' => $type, 'timestamp' => (int) ($body['timestamp'] ?? now()->timestamp)]];
    }
}
