<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class GoogleTrackingAdapter implements TrackingAdapter
{
    public function deliver(object $destination, object $event, string $providerEvent): array
    {
        $secret = app(TrackingAdapters::class)->secret($destination);
        if (empty($secret['access_token']) || empty($secret['developer_token'])) {
            return ['ok' => false, 'status' => null, 'error' => 'NOT_CONFIGURED'];
        }
        $context = json_decode($event->safe_context, true);
        $click = array_intersect_key($context, array_flip(['gclid', 'gbraid', 'wbraid']));
        if (! $click) {
            return ['ok' => false, 'status' => null, 'error' => 'MISSING_CLICK_ID'];
        }
        $conversion = ['conversionAction' => 'customers/'.$destination->account_reference.'/conversionActions/'.$providerEvent, 'conversionDateTime' => Carbon::parse($event->occurred_at)->utc()->format('Y-m-d H:i:sP'), 'orderId' => $event->event_id, 'consent' => ['adUserData' => 'GRANTED', 'adPersonalization' => 'DENIED']] + $click;
        $version = config('operations.tracking.google_version');
        if (! preg_match('/^v\d+$/', $version)) {
            throw new \RuntimeException('API version');
        }$request = Http::withToken($secret['access_token'])->withHeaders(['developer-token' => $secret['developer_token']])->timeout(12)->connectTimeout(5);
        if (! empty($secret['login_customer_id'])) {
            $request = $request->withHeaders(['login-customer-id' => $secret['login_customer_id']]);
        }
        $response = $request->post('https://googleads.googleapis.com/'.$version.'/customers/'.$destination->account_reference.':uploadClickConversions', ['conversions' => [$conversion], 'partialFailure' => true]);

        return app(TrackingAdapters::class)->result($response, ! $response->json('partialFailureError') && count($response->json('results') ?? []) > 0);
    }
}
