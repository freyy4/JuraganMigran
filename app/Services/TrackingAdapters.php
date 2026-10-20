<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class TrackingAdapters
{
    public function forProvider(string $provider): TrackingAdapter
    {
        return match ($provider) {
            'META' => app(MetaTrackingAdapter::class),'GOOGLE' => app(GoogleTrackingAdapter::class),'TIKTOK' => app(TikTokTrackingAdapter::class),default => throw new \InvalidArgumentException('Provider tidak tersedia.')
        };
    }

    public function secret(object $dest): array
    {
        if (! empty($dest->encrypted_secret)) {
            try {
                return json_decode(Crypt::decryptString($dest->encrypted_secret), true) ?? [];
            } catch (\Throwable) {
                return [];
            }
        }
        if (! preg_match('/^JM_TRACKING_[A-Z0-9_]+$/', $dest->secret_reference ?? '')) {
            return [];
        }$value = env($dest->secret_reference);
        if (! is_string($value)) {
            return [];
        }

        return str_starts_with($value, '{') ? (json_decode($value, true) ?? []) : ['access_token' => $value];
    }

    public function checkConnection(object $destination): array
    {
        $secret = $this->secret($destination);
        if (empty($secret['access_token'])) {
            return ['status' => 'WARNING', 'message' => 'Token server belum tersedia. Pixel browser perlu diperiksa melalui Events Manager / Tag Assistant.'];
        }
        try {
            $http = Http::timeout(10)->connectTimeout(4)->acceptJson();
            if ($destination->provider === 'META') {
                $version = config('operations.tracking.meta_version');
                if (! preg_match('/^v[0-9]+\.[0-9]+$/', $version)) {
                    return ['status' => 'ERROR', 'message' => 'Versi API Meta tidak valid.'];
                }
                $response = $http->withToken($secret['access_token'])->get('https://graph.facebook.com/'.$version.'/'.$destination->pixel_reference, ['fields' => 'id']);
                $accepted = $response->successful() && (string) $response->json('id') === $destination->pixel_reference;
            } elseif ($destination->provider === 'GOOGLE') {
                if (empty($secret['developer_token']) || empty($destination->account_reference)) {
                    return ['status' => 'WARNING', 'message' => 'ID akun dan developer token Google diperlukan.'];
                }
                $version = config('operations.tracking.google_version');
                if (! preg_match('/^v[0-9]+$/', $version)) {
                    return ['status' => 'ERROR', 'message' => 'Versi API Google tidak valid.'];
                }
                $response = $http->withToken($secret['access_token'])->withHeaders(['developer-token' => $secret['developer_token']])->get('https://googleads.googleapis.com/'.$version.'/customers:listAccessibleCustomers');
                $accepted = $response->successful() && in_array('customers/'.$destination->account_reference, $response->json('resourceNames') ?? [], true);
            } else {
                if (empty($destination->account_reference)) {
                    return ['status' => 'WARNING', 'message' => 'Isi advertiser ID TikTok untuk memeriksa akses API.'];
                }
                $response = $http->withHeaders(['Access-Token' => $secret['access_token']])->get('https://business-api.tiktok.com/open_api/v1.3/pixel/list/', ['advertiser_id' => $destination->account_reference, 'page_size' => 50]);
                $accepted = $response->successful() && $response->json('code') === 0;
            }

            return ['status' => $accepted ? 'HEALTHY' : 'ERROR', 'message' => $accepted ? 'Akses baca API vendor berhasil. Status ini tidak membuktikan penerimaan event konversi; periksa diagnostics vendor.' : 'Akses API ditolak atau akun/Pixel tidak cocok. Periksa ID, izin token, dan diagnostics vendor.'];
        } catch (\Throwable) {
            return ['status' => 'ERROR', 'message' => 'Koneksi vendor tidak tersedia. Periksa jaringan dan coba kembali. Pendaftaran tetap berfungsi.'];
        }
    }

    public function result(Response $response, bool $accepted = true): array
    {
        return ['ok' => $response->successful() && $accepted, 'status' => $response->status(), 'error' => $response->successful() ? 'PROVIDER_REJECTED' : 'HTTP_'.$response->status()];
    }
}
