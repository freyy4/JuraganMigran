<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MetaGraph
{
    public function request(string $method, string $path, string $token, array $data = []): array
    {
        $version = config('conversation.graph_version');
        if (! preg_match('/^v[0-9]+\.[0-9]+$/', $version) || ! preg_match('~^[a-zA-Z0-9_]+(?:/[a-zA-Z0-9_]+)*$~', $path)) {
            throw new MetaConnectFailure('META_APP_NOT_CONFIGURED');
        }
        try {
            $client = Http::withToken($token)->acceptJson()->timeout(20)->withoutRedirecting();
            $response = $method === 'GET' ? $client->get('https://graph.facebook.com/'.$version.'/'.$path, $data) : $client->post('https://graph.facebook.com/'.$version.'/'.$path, $data);
        } catch (\Throwable) {
            throw new MetaConnectFailure('META_UNAVAILABLE', 503);
        }
        if (! $response->successful() || $response->json('error')) {
            $code = (int) $response->json('error.code');
            throw new MetaConnectFailure(match (true) {
                $code === 190 => 'REAUTH_REQUIRED', in_array($code, [10, 200], true) => 'META_PERMISSION_REQUIRED', default => 'META_UNAVAILABLE'
            });
        }

        return $response->json() ?? [];
    }

    public function listing(string $path, string $token, array $parameters = []): array
    {
        $rows = [];
        $after = null;
        do {
            $response = $this->request('GET', $path, $token, $parameters + array_filter(['limit' => 100, 'after' => $after]));
            $rows = [...$rows, ...($response['data'] ?? [])];
            $next = isset($response['paging']['next']) ? ($response['paging']['cursors']['after'] ?? null) : null;
            if ($next && ($next === $after || count($rows) >= 2000)) {
                throw new MetaConnectFailure('META_UNAVAILABLE');
            }
            $after = $next;
        } while ($after);

        return $rows;
    }

    public function permissions(string $token): array
    {
        $granted = array_column(array_filter($this->listing('me/permissions', $token), fn ($p) => ($p['status'] ?? '') === 'granted'), 'permission');
        if (array_diff(config('meta-connect.permissions'), $granted)) {
            throw new MetaConnectFailure('META_PERMISSION_REQUIRED');
        }

        return $granted;
    }
}
