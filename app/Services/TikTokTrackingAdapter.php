<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class TikTokTrackingAdapter implements TrackingAdapter
{
    public function deliver(object $destination, object $event, string $providerEvent): array
    {
        $secret = app(TrackingAdapters::class)->secret($destination);
        if (empty($secret['access_token'])) {
            return ['ok' => false, 'status' => null, 'error' => 'NOT_CONFIGURED'];
        }$context = json_decode($event->safe_context, true);
        if (empty($context['ttclid'])) {
            return ['ok' => false, 'status' => null, 'error' => 'MISSING_CLICK_ID'];
        }
        $response = Http::withHeaders(['Access-Token' => $secret['access_token']])->timeout(12)->connectTimeout(5)->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', ['event_source' => 'web', 'event_source_id' => $destination->pixel_reference, 'data' => [['event' => $providerEvent, 'event_time' => Carbon::parse($event->occurred_at)->timestamp, 'event_id' => $event->event_id, 'user' => ['ttclid' => $context['ttclid']], 'page' => ['url' => url($context['path'] ?? '/')]]]]);

        return app(TrackingAdapters::class)->result($response, $response->json('code') === 0);
    }
}
