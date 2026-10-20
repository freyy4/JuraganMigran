<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TrackingHub
{
    public function consent(Request $request, bool $analytics, bool $advertising): int
    {
        $session = app(SiteAttribution::class)->session($request);
        DB::table('tracking_consent_snapshots')->where('anonymous_session_id', $session)->whereNull('withdrawn_at')->update(['withdrawn_at' => now()]);
        $id = DB::table('tracking_consent_snapshots')->insertGetId(['anonymous_session_id' => $session, 'analytics' => $analytics, 'advertising' => $advertising, 'policy_version' => 'JM-TRACKING-1', 'created_at' => now(), 'updated_at' => now()]);
        $request->session()->put('tracking_consent_id', $id);

        return $id;
    }

    public function emit(string $name, string $key, array $references = [], ?int $consent = null, array $context = [], string $source = 'server', bool $test = false): object
    {
        if (! in_array($name, config('operations.events'), true)) {
            throw ValidationException::withMessages(['event' => 'Event tidak diizinkan.']);
        }
        if ($source === 'browser' && ! in_array($name, config('operations.browser_events'), true)) {
            throw ValidationException::withMessages(['event' => 'Milestone CRM hanya dapat dicatat oleh server.']);
        }
        $safe = array_intersect_key($context, array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'channel', 'path', 'click_captured_at', 'section_key', 'rule_id']));
        foreach ($safe as $field => $value) {
            if (! is_string($value) || strlen($value) > 500) {
                unset($safe[$field]);
            }
        }
        if (isset($safe['path'])) {
            $safe['path'] = parse_url($safe['path'], PHP_URL_PATH) ?: '/';
        }

        return DB::transaction(function () use ($name, $key, $references, $consent, $safe, $source, $test): object {
            $fields = array_intersect_key($references, array_flip(['lead_id', 'application_id', 'funnel_id', 'funnel_version_id', 'vacancy_id', 'attribution_id']));
            DB::table('canonical_tracking_events')->insertOrIgnore($fields + ['event_id' => (string) Str::uuid(), 'idempotency_key' => $key, 'canonical_event' => $name, 'source' => $source, 'is_test' => $test, 'consent_snapshot_id' => $consent, 'safe_context' => json_encode($safe), 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $event = DB::table('canonical_tracking_events')->where('idempotency_key', $key)->first();
            if (DB::table('tracking_outbox')->where('event_id', $event->id)->exists()) {
                return $event;
            }
            $snapshot = $event->consent_snapshot_id ? DB::table('tracking_consent_snapshots')->find($event->consent_snapshot_id) : null;
            if (! $snapshot || ! $snapshot->advertising || $snapshot->withdrawn_at) {
                return $event;
            }
            $maps = [];
            if ($event->funnel_version_id) {
                $version = DB::table('funnel_versions')->find($event->funnel_version_id);
                $schema = json_decode($version?->schema_snapshot ?? '{}', true);
                $maps = isset($safe['rule_id']) ? (collect($schema['tracking']['rules'] ?? [])->firstWhere('id', $safe['rule_id'])['resolved_mappings'] ?? []) : ($schema['tracking']['mappings'] ?? []);
                if ($source === 'server' && $name === 'WHATSAPP_CLICK') {
                    foreach ($schema['tracking']['rules'] ?? [] as $rule) {
                        if ($rule['enabled'] && $rule['trigger_type'] === 'WHATSAPP_CLICK' && in_array($rule['section_key'], ['whatsapp', 'thank_you'])) {
                            $maps = array_merge($maps, $rule['resolved_mappings'] ?? []);
                        }
                    }
                }
                if (isset($schema['tracking']['enabled']) && ! $schema['tracking']['enabled']) {
                    $maps = [];
                }
            }
            if (! $event->funnel_version_id || $source === 'server' && ! in_array($name, TrackingConfiguration::PUBLIC_EVENTS, true)) {
                $maps = DB::table('tracking_event_mappings')->where('canonical_event', $name)->where('active', true)->get()->map(fn ($m) => ['destination_id' => $m->destination_id, 'event' => $m->canonical_event, 'provider_event' => $m->provider_event, 'version' => $m->version])->all();
            }
            foreach ($maps as $map) {
                if (($map['event'] ?? '') !== $name) {
                    continue;
                }
                $dest = DB::table('tracking_destinations')->find($map['destination_id']);
                if (! $dest || ! app(TrackingConfiguration::class)->available($dest) || ! $dest->server_enabled) {
                    continue;
                }
                DB::table('tracking_outbox')->insertOrIgnore(['event_id' => $event->id, 'destination_id' => $dest->id, 'provider_event' => $map['provider_event'], 'mapping_version' => $map['version'] ?? 1, 'status' => 'PENDING', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }

            return $event;
        });
    }

    public function fromRequest(Request $r, string $name, string $key, array $refs = [], bool $test = false): object
    {
        $summary = app(SiteAttribution::class)->summary($r);
        $last = $summary['last_touch'];
        $context = ($last['context'] ?? []) + ['click_captured_at' => $last['captured_at'] ?? now()->toISOString(), 'channel' => $last['channel'] ?? 'UNKNOWN', 'path' => '/'.trim($r->path(), '/')];

        return $this->emit($name, $key, $refs + ['attribution_id' => $last['id'] ?? null], $r->session()->get('tracking_consent_id'), $context, 'server', $test);
    }

    public function leadMilestone(int $lead, string $event, string $key): ?object
    {
        $submission = DB::table('lead_submissions')->where('lead_id', $lead)->where('is_test', false)->latest('id')->first();
        if (! $submission) {
            return null;
        }
        $prior = DB::table('canonical_tracking_events')->where('lead_id', $lead)->where('is_test', false)->whereNotNull('consent_snapshot_id')->latest('id')->first();

        return $this->emit($event, $key, ['lead_id' => $lead, 'funnel_version_id' => $submission->version_id, 'funnel_id' => $submission->funnel_id, 'vacancy_id' => $submission->vacancy_id, 'attribution_id' => $prior?->attribution_id], $prior?->consent_snapshot_id, json_decode($prior?->safe_context ?? '{}', true));
    }

    public function milestone(int $application, string $event, string $key): ?object
    {
        $submission = DB::table('lead_submissions')->where('application_id', $application)->where('is_test', false)->latest('id')->first();
        if (! $submission) {
            return null;
        }
        $prior = DB::table('canonical_tracking_events')->where('lead_id', $submission->lead_id)->where('is_test', false)->whereNotNull('consent_snapshot_id')->latest('id')->first();
        $context = json_decode($prior?->safe_context ?? '{}', true);

        return $this->emit($event, $key, ['application_id' => $application, 'lead_id' => $submission->lead_id, 'funnel_id' => $submission->funnel_id, 'vacancy_id' => $submission->vacancy_id, 'attribution_id' => $prior?->attribution_id], $prior?->consent_snapshot_id, $context);
    }

    public function stage(int $application, int $stage, int $history): void
    {
        $code = DB::table('pipeline_stages')->where('id', $stage)->value('code');
        foreach (DB::table('tracking_event_mappings')->where('active', true)->where('stage_code', $code)->get()->unique('canonical_event') as $map) {
            $this->milestone($application, $map->canonical_event, 'stage-'.$history.'-'.$map->canonical_event);
        }
    }

    public function browserConfig(?int $version = null, ?string $ruleId = null, ?string $eventName = null): array
    {
        if ($version) {
            $schema = json_decode(DB::table('funnel_versions')->where('id', $version)->value('schema_snapshot') ?? '{}', true);
            if (collect($schema['fields'] ?? [])->contains(fn ($field) => ! in_array($field['sensitivity'] ?? 'GENERAL', ['GENERAL', 'CONTACT']))) {
                return [];
            }
        }
        $maps = $version ? (json_decode(DB::table('funnel_versions')->where('id', $version)->value('schema_snapshot') ?? '{}', true)['tracking']['mappings'] ?? []) : DB::table('tracking_event_mappings')->where('active', true)->get()->map(fn ($m) => ['destination_id' => $m->destination_id, 'event' => $m->canonical_event, 'provider_event' => $m->provider_event])->all();
        if ($version && isset($schema['tracking']['enabled']) && ! $schema['tracking']['enabled']) {
            return [];
        }
        if ($ruleId) {
            $maps = collect($schema['tracking']['rules'] ?? [])->firstWhere('id', $ruleId)['resolved_mappings'] ?? [];
        }
        $destinations = [];
        foreach ($maps as $map) {
            $dest = DB::table('tracking_destinations')->find($map['destination_id']);
            if ($eventName && $map['event'] !== $eventName) {
                continue;
            }
            if (! $dest || ! app(TrackingConfiguration::class)->available($dest) || ! $dest->browser_enabled || ($dest->active_from && Carbon::parse($dest->active_from)->isFuture()) || ($dest->active_until && Carbon::parse($dest->active_until)->isPast()) || ! in_array($map['event'], [...config('operations.browser_events'), 'FORM_SUBMIT', 'LEAD_CREATED'])) {
                continue;
            }
            if ($dest->provider === 'GOOGLE' && str_starts_with($dest->pixel_reference, 'AW-') && empty($map['conversion_label'])) {
                continue;
            }
            if ($dest->provider === 'GOOGLE' && $dest->server_enabled && str_starts_with($dest->pixel_reference, 'AW-')) {
                continue;
            }
            $destinations[] = ['google_conversion' => str_starts_with($dest->pixel_reference, 'AW-'), 'conversion_label' => $map['conversion_label'] ?? null, 'id' => $dest->id, 'provider' => $dest->provider, 'pixel' => $dest->pixel_reference, 'event' => $map['event'], 'provider_event' => $map['provider_event']];
        }

        return $destinations;
    }

    public function clientDispatches(object $event, array $destinations): array
    {
        $pending = [];
        foreach ($destinations as $destination) {
            if (DB::table('tracking_client_deliveries')->insertOrIgnore(['event_id' => $event->id, 'destination_id' => $destination['id'], 'provider_event' => $destination['provider_event'], 'status' => 'QUEUED_CLIENT', 'created_at' => now()])) {
                $pending[] = $destination;
            }
        }

        return $pending;
    }

    public function deliver(int $outboxId): void
    {
        $row = DB::transaction(function () use ($outboxId): ?object {
            $row = DB::table('tracking_outbox')->where('id', $outboxId)->lockForUpdate()->first();
            if (! $row || in_array($row->status, ['SENT', 'DEAD', 'SKIPPED']) || Carbon::parse($row->next_attempt_at)->isFuture() || ($row->leased_until && Carbon::parse($row->leased_until)->isFuture())) {
                return null;
            }
            DB::table('tracking_outbox')->where('id', $outboxId)->update(['status' => 'PROCESSING', 'attempts' => $row->attempts + 1, 'leased_until' => now()->addMinutes(5)]);
            $row->attempts++;

            return $row;
        });
        if (! $row) {
            return;
        }
        $event = DB::table('canonical_tracking_events')->find($row->event_id);
        $dest = DB::table('tracking_destinations')->find($row->destination_id);
        $consent = DB::table('tracking_consent_snapshots')->find($event->consent_snapshot_id);
        if (! $consent || ! $consent->advertising || $consent->withdrawn_at || ! app(TrackingConfiguration::class)->available($dest, parse_url(config('app.url'), PHP_URL_HOST)) || ! $dest->server_enabled || ($dest->active_from && Carbon::parse($dest->active_from)->isFuture()) || ($dest->active_until && Carbon::parse($dest->active_until)->isPast())) {
            $result = ['ok' => false, 'skipped' => true, 'error' => 'CONSENT_OR_DESTINATION_DISABLED', 'status' => null];
        } elseif ($event->is_test) {
            $result = ['ok' => false, 'skipped' => true, 'error' => 'TEST_LOCAL_ONLY', 'status' => null];
        } else {
            try {
                $result = app(TrackingAdapters::class)->forProvider($dest->provider)->deliver($dest, $event, $row->provider_event);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'status' => null, 'error' => 'PROVIDER_CONNECTION_ERROR'];
            }
        }
        DB::transaction(function () use ($row, $dest, $result): void {
            DB::table('tracking_delivery_attempts')->insert(['outbox_id' => $row->id, 'attempt' => $row->attempts, 'http_status' => $result['status'], 'result' => $result['ok'] ? 'SUCCESS' : $result['error'], 'created_at' => now()]);
            $status = $result['ok'] ? 'SENT' : (($result['skipped'] ?? false) ? 'SKIPPED' : ($row->attempts >= config('operations.tracking.max_attempts') ? 'DEAD' : 'RETRY'));
            DB::table('tracking_outbox')->where('id', $row->id)->update(['status' => $status, 'leased_until' => null, 'sent_at' => $result['ok'] ? now() : null, 'last_error' => $result['ok'] ? null : $result['error'], 'next_attempt_at' => now()->addSeconds(min(3600, 60 * (2 ** ($row->attempts - 1)))), 'updated_at' => now()]);
            DB::table('tracking_destinations')->where('id', $dest->id)->update(['health' => $result['ok'] ? 'CONNECTED' : (($result['skipped'] ?? false) ? 'DISABLED' : 'ERROR'), 'last_success_at' => $result['ok'] ? now() : $dest->last_success_at, 'last_error' => $result['ok'] ? null : $result['error'], 'updated_at' => now()]);
        });
    }
}
