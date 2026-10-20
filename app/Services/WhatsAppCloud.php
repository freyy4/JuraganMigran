<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class WhatsAppCloud implements WhatsAppProviderAdapter
{
    private function endpoint(string $id, string $edge = ''): string
    {
        $version = config('conversation.graph_version');
        if (! preg_match('/^v\d+\.\d+$/', $version) || ! ctype_digit($id)) {
            throw new RuntimeException('Invalid provider configuration.');
        }

        return 'https://graph.facebook.com/'.$version.'/'.$id.($edge ? '/'.$edge : '');
    }

    public function connect(string $code, string $waba, string $phone): array
    {
        $response = Http::timeout(20)->get('https://graph.facebook.com/'.config('conversation.graph_version').'/oauth/access_token', ['client_id' => config('conversation.app_id'), 'client_secret' => config('conversation.app_secret'), 'code' => $code]);
        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException('Otorisasi Meta gagal. Ulangi proses koneksi.');
        }
        $token = $response->json('access_token');
        $numbers = Http::withToken($token)->timeout(15)->get($this->endpoint($waba, 'phone_numbers'), ['fields' => 'id,display_phone_number,verified_name', 'limit' => 100]);
        $number = collect($numbers->json('data', []))->firstWhere('id', $phone);
        if (! $numbers->successful() || ! $number) {
            throw new RuntimeException('Nomor tidak cocok dengan akun bisnis yang diotorisasi.');
        }
        $subscribe = Http::withToken($token)->timeout(15)->post($this->endpoint($waba, 'subscribed_apps'));
        if (! $subscribe->successful()) {
            throw new RuntimeException('Aplikasi belum dapat berlangganan webhook akun bisnis.');
        }

        return ['access_token' => Crypt::encryptString($token), 'waba_id' => $waba, 'phone_number_id' => $phone, 'display_phone_number' => $number['display_phone_number'], 'name' => $number['verified_name'] ?? 'WhatsApp Business', 'status' => 'CONFIGURED', 'updated_at' => now()];
    }

    public function register(object $channel, string $pin): void
    {
        $response = Http::withToken(Crypt::decryptString($channel->access_token))->timeout(20)->post($this->endpoint($channel->phone_number_id, 'register'), ['messaging_product' => 'whatsapp', 'pin' => $pin]);
        if (! $response->successful() || ! $response->json('success')) {
            throw new RuntimeException('Registrasi nomor gagal.');
        }
    }

    public function inspect(object $channel): void
    {
        app(MetaConnect::class)->health($channel);
    }

    public function templates(object $channel): int
    {
        $templates = [];
        $after = null;
        do {
            $response = Http::withToken(Crypt::decryptString($channel->access_token))->timeout(20)->get($this->endpoint($channel->waba_id, 'message_templates'), array_filter(['limit' => 100, 'fields' => 'name,language,status,category,components', 'after' => $after]));
            if (! $response->successful()) {
                throw new RuntimeException('Sinkronisasi template gagal.');
            }
            $templates = [...$templates, ...$response->json('data', [])];
            $after = $response->json('paging.next') ? $response->json('paging.cursors.after') : null;
            if ($after && count($templates) >= 2000) {
                throw new RuntimeException('Jumlah template melampaui batas sinkronisasi.');
            }
        } while ($after);
        DB::transaction(function () use ($channel, $templates): void {
            DB::table('crm_whatsapp_templates')->where('channel_id', $channel->id)->update(['status' => 'STALE']);
            foreach ($templates as $template) {
                DB::table('crm_whatsapp_templates')->updateOrInsert(['channel_id' => $channel->id, 'name' => $template['name'], 'language' => $template['language']], ['status' => $template['status'], 'category' => $template['category'], 'components' => json_encode($template['components'] ?? []), 'last_synced_at' => now()]);
            }
        });

        return count($templates);
    }

    public function downloadMedia(object $channel, string $mediaId): array
    {
        $token = Crypt::decryptString($channel->access_token);
        $metadata = Http::withToken($token)->timeout(15)->get($this->endpoint($mediaId));
        $mime = $metadata->json('mime_type');
        if (! $metadata->successful() || ! in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true) || (int) $metadata->json('file_size') > 10 * 1024 * 1024) {
            throw new RuntimeException('Lampiran tidak didukung atau melebihi 10 MB.');
        }
        $url = $metadata->json('url');
        $host = parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! ($host === 'lookaside.fbsbx.com' || $host === 'graph.facebook.com' || str_ends_with($host ?? '', '.fbcdn.net'))) {
            throw new RuntimeException('Alamat media provider tidak valid.');
        }
        $response = Http::withToken($token)->withoutRedirecting()->withOptions(['stream' => true])->timeout(30)->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Media tidak tersedia.');
        }
        $stream = $response->toPsrResponse()->getBody();
        $data = '';
        while (! $stream->eof()) {
            $data .= $stream->read(65536);
            if (strlen($data) > 10 * 1024 * 1024) {
                $stream->close();
                throw new RuntimeException('Lampiran melampaui batas.');
            }
        }

        return ['data' => $data, 'mime' => $mime];
    }

    public function send(int $id): void
    {
        app(WhatsAppOutbox::class)->send($id);
    }

    public function sendMessage(object $channel, array $payload): string
    {
        if (isset($payload['_local_media'])) {
            $media = $payload['_local_media'];
            if (! Storage::disk('local')->exists($media['path'])) {
                throw new WhatsAppProviderFailure('ATTACHMENT_UNAVAILABLE');
            }
            $handle = Storage::disk('local')->readStream($media['path']);
            try {
                $upload = Http::withToken(Crypt::decryptString($channel->access_token))->timeout(30)->attach('file', $handle, $payload['type'] === 'document' ? 'informasi.pdf' : 'image', ['Content-Type' => $media['mime']])->post($this->endpoint($channel->phone_number_id, 'media'), ['messaging_product' => 'whatsapp', 'type' => $media['mime']]);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
            if (! $upload->successful() || ! $upload->json('id')) {
                throw new WhatsAppProviderFailure('ATTACHMENT_UPLOAD_FAILED');
            }
            $payload[$payload['type']]['id'] = $upload->json('id');
            unset($payload['_local_media']);
        }
        $response = Http::withToken(Crypt::decryptString($channel->access_token))->timeout(20)->post($this->endpoint($channel->phone_number_id, 'messages'), $payload);
        if (! $response->successful() || ! $response->json('messages.0.id')) {
            throw new WhatsAppProviderFailure('META_'.($response->json('error.code') ?? $response->status()), $response->serverError());
        }

        return $response->json('messages.0.id');
    }

    public function getHealth(object $channel): array
    {
        return app(MetaConnect::class)->safeChannel(app(MetaConnect::class)->health($channel));
    }

    public function disconnect(object $channel): void
    {
        DB::table('whatsapp_channels')->where('id', $channel->id)->update(['is_active' => false, 'status' => 'DISCONNECTED', 'access_token' => Crypt::encryptString('')]);
    }

    public function normalizeInbound(array $payload): array
    {
        $events = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $channel = DB::table('whatsapp_channels')->where('provider', 'META_CLOUD_API')->where('phone_number_id', $value['metadata']['phone_number_id'] ?? '')->first();
                if (! $channel) {
                    continue;
                }
                foreach ($value['messages'] ?? [] as $message) {
                    $contact = collect($value['contacts'] ?? [])->firstWhere('wa_id', $message['from'] ?? '');
                    $events[] = ['kind' => 'message', 'provider' => 'META_CLOUD_API', 'channel_id' => $channel->id, 'external' => $message['id'] ?? '', 'from' => $message['from'] ?? '', 'name' => $contact['profile']['name'] ?? null, 'type' => $message['type'] ?? 'unknown', 'text' => $message['text']['body'] ?? $message['button']['text'] ?? $message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? '['.($message['type'] ?? 'unknown').' diterima; pratinjau belum tersedia]', 'media' => in_array($message['type'] ?? '', ['image', 'document'], true) ? $message[$message['type']] : null, 'timestamp' => (int) ($message['timestamp'] ?? now()->timestamp)];
                }
                foreach ($value['statuses'] ?? [] as $status) {
                    if (in_array(strtoupper($status['status'] ?? ''), ['SENT', 'DELIVERED', 'READ', 'FAILED'], true)) {
                        $events[] = ['kind' => 'status', 'provider' => 'META_CLOUD_API', 'channel_id' => $channel->id, 'external' => $status['id'], 'status' => strtoupper($status['status']), 'timestamp' => (int) $status['timestamp'], 'error_code' => isset($status['errors'][0]['code']) ? (string) $status['errors'][0]['code'] : null];
                    }
                }
            }
        }

        return $events;
    }
}
