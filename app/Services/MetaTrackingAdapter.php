<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class MetaTrackingAdapter implements TrackingAdapter
{
    public function deliver(object $destination, object $event, string $providerEvent): array
    {
        $secret = app(TrackingAdapters::class)->secret($destination);
        if (empty($secret['access_token'])) {
            return ['ok' => false, 'status' => null, 'error' => 'NOT_CONFIGURED'];
        }
        $context = json_decode($event->safe_context, true);
        $user = [];
        if (! empty($context['fbclid'])) {
            $user['fbc'] = 'fb.1.'.(Carbon::parse($context['click_captured_at'] ?? $event->occurred_at)->timestamp * 1000).'.'.$context['fbclid'];
        }
        if (! $user) {
            return ['ok' => false, 'status' => null, 'error' => 'MISSING_MATCHING_CONTEXT'];
        }
        $payload = ['event_name' => $providerEvent, 'event_time' => Carbon::parse($event->occurred_at)->timestamp, 'event_id' => $event->event_id, 'action_source' => 'website', 'event_source_url' => url($context['path'] ?? '/'), 'user_data' => $user];
        $version = config('operations.tracking.meta_version');
        if (! preg_match('/^v\d+\.\d+$/', $version)) {
            throw new \RuntimeException('API version');
        }
        $response = Http::timeout(12)->connectTimeout(5)->post('https://graph.facebook.com/'.$version.'/'.$destination->pixel_reference.'/events', ['data' => [$payload], 'access_token' => $secret['access_token']]);

        return app(TrackingAdapters::class)->result($response, ($response->json('events_received') ?? 0) > 0);
    }
}
