<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AttributionService
{
    public function record(int $lead, string $key, string $event, string $source = 'UNKNOWN', array $context = [], ?string $at = null): void
    {
        $row = DB::table('acquisition_leads')->find($lead);
        $safe = array_intersect_key($context, array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'referral_code', 'funnel_slug', 'funnel_id', 'form_id', 'version', 'gclid', 'fbclid', 'ttclid', 'gbraid', 'wbraid', 'anonymous_session_id', 'landing_url', 'channel', 'campaign_id']));
        $safe = array_filter($safe, fn ($v) => is_scalar($v) && mb_strlen((string) $v) <= 500);
        if (isset($safe['landing_url'])) {
            $url = parse_url($safe['landing_url']);
            $safe['landing_url'] = isset($url['host']) ? ($url['scheme'] ?? 'https').'://'.$url['host'].($url['path'] ?? '/') : ($url['path'] ?? '/');
        }
        DB::table('crm_attribution_touchpoints')->insertOrIgnore(['event_key' => $key, 'contact_id' => $row->contact_id, 'lead_id' => $lead, 'anonymous_session_id' => $safe['anonymous_session_id'] ?? null, 'event_type' => $event, 'channel' => $safe['channel'] ?? ($event === 'WHATSAPP_INBOUND' ? 'WHATSAPP' : 'FORM'), 'source' => $safe['utm_source'] ?? $source, 'context' => json_encode($safe), 'event_at' => $at ?? now(), 'created_at' => now()]);
    }

    public function summary(int $lead): array
    {
        $touches = DB::table('crm_attribution_touchpoints')->where('lead_id', $lead)->orderBy('event_at')->orderBy('id')->get();

        return ['first' => $touches->first(), 'creation' => $touches->firstWhere('event_type', 'LEAD_CREATED') ?? $touches->firstWhere('event_type', 'FORM_SUBMITTED'), 'latest' => $touches->last(), 'conversion' => $touches->where('event_type', 'LEAD_CONVERTED')->last()];
    }
}
