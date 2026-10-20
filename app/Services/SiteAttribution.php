<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SiteAttribution
{
    public function session(Request $request): string
    {
        if (! $request->session()->has('site_anonymous_id')) {
            $request->session()->put('site_anonymous_id', (string) Str::uuid());
        }

        return $request->session()->get('site_anonymous_id');
    }

    public function capture(Request $request, ?int $vacancy = null): void
    {
        $context = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'referral_code', 'gclid', 'fbclid', 'gbraid', 'wbraid', 'ttclid'] as $key) {
            $value = $request->input($key);
            if (is_string($value) && strlen($value) <= 255) {
                $context[$key] = $value;
            }
        }
        $host = parse_url($request->headers->get('referer', ''), PHP_URL_HOST) ?: null;
        $medium = strtolower($context['utm_medium'] ?? '');
        $channel = match (true) {
            ! empty($context['referral_code']) => 'REFERRAL',
            in_array($medium, ['cpc', 'ppc', 'paid_search']) || ! empty($context['gclid']) => 'PAID_SEARCH',
            in_array($medium, ['paid_social', 'social_paid']) => 'PAID_SOCIAL',
            $medium === 'email' => 'EMAIL', $medium === 'partner' => 'PARTNER', $medium === 'qr' => 'OFFLINE_QR',
            $host && preg_match('/(^|\.)(google\.[a-z.]+|bing\.com|duckduckgo\.com|yahoo\.[a-z.]+)$/i', $host) => 'ORGANIC_SEARCH',
            $host && preg_match('/(^|\.)(facebook\.com|instagram\.com|tiktok\.com|linkedin\.com)$/i', $host) => 'ORGANIC_SOCIAL',
            ! empty($context) => 'OTHER', ! $host => 'DIRECT', default => 'UNKNOWN',
        };
        if ($host === parse_url($request->root(), PHP_URL_HOST) && ! $context && $request->session()->has('site_last_touch')) {
            return;
        }
        $id = DB::table('site_attribution_touches')->insertGetId(['anonymous_session_id' => $this->session($request), 'vacancy_id' => $vacancy, 'path' => '/'.trim($request->path(), '/'), 'referrer_host' => $host, 'channel' => $channel, 'context' => json_encode($context), 'device_class' => preg_match('/mobile|android|iphone/i', $request->userAgent() ?? '') ? 'mobile' : 'desktop', 'created_at' => now(), 'updated_at' => now()]);
        if (! $request->session()->has('site_first_touch')) {
            $request->session()->put('site_first_touch', $id);
        }
        $request->session()->put('site_last_touch', $id);
    }

    public function summary(Request $request): array
    {
        $touches = DB::table('site_attribution_touches')->where('anonymous_session_id', $this->session($request))->orderBy('id')->get(['id', 'channel', 'context', 'path', 'created_at']);
        $safe = fn ($touch) => $touch ? ['id' => $touch->id, 'channel' => $touch->channel, 'path' => $touch->path, 'captured_at' => $touch->created_at, 'context' => json_decode($touch->context, true)] : null;

        return ['first_touch' => $safe($touches->first()), 'last_touch' => $safe($touches->last()), 'assisted_touch_ids' => $touches->pluck('id')->all()];
    }

    public function link(Request $request, int $lead): void
    {
        DB::table('site_attribution_touches')->where('anonymous_session_id', $this->session($request))->whereNull('lead_id')->update(['lead_id' => $lead]);
    }

    public function event(Request $request, string $event, array $context = [], ?int $vacancy = null): void
    {
        $path = '/'.trim($request->path(), '/');
        if ($request->routeIs('public.events')) {
            $referrer = $request->headers->get('referer', '');
            if (parse_url($referrer, PHP_URL_HOST) === $request->getHost()) {
                $path = substr(parse_url($referrer, PHP_URL_PATH) ?: '/', 0, 500);
            }
        }
        DB::table('site_events')->insert(['anonymous_session_id' => $this->session($request), 'vacancy_id' => $vacancy, 'event' => $event, 'path' => $path, 'context' => json_encode(array_intersect_key($context, array_flip(['results', 'device', 'lcp', 'cls', 'inp', 'ttfb']))), 'is_test' => false, 'created_at' => now(), 'updated_at' => now()]);
    }
}
