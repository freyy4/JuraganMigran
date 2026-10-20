<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TrackingConfiguration
{
    public const PUBLIC_EVENTS = ['PAGE_VIEW', 'VIEW_VACANCY', 'CTA_CLICK', 'FORM_START', 'FORM_STEP_COMPLETED', 'FORM_SUBMIT', 'LEAD_CREATED', 'THANK_YOU_VIEW', 'WHATSAPP_CLICK'];

    public const TRIGGERS = [
        'SECTION_VISIBLE' => ['PAGE_VIEW', 'VIEW_VACANCY'],
        'BUTTON_CLICK' => ['CTA_CLICK', 'VIEW_VACANCY'],
        'LINK_CLICK' => ['CTA_CLICK', 'VIEW_VACANCY'],
        'FORM_START' => ['FORM_START'],
        'FORM_SUBMIT_SUCCESS' => ['FORM_SUBMIT', 'LEAD_CREATED'],
        'THANK_YOU_VIEW' => ['THANK_YOU_VIEW'],
        'WHATSAPP_CLICK' => ['WHATSAPP_CLICK'],
    ];

    public function catalog(): array
    {
        return array_map(fn ($event) => ['canonical_event' => $event, 'browser_allowed' => ! in_array($event, ['FORM_SUBMIT', 'LEAD_CREATED']), 'server_allowed' => true, 'category' => 'PUBLIC_ACQUISITION'], self::PUBLIC_EVENTS);
    }

    public function defaultMapping(string $provider, string $event): ?string
    {
        return match ($provider) {
            'META' => match ($event) {
                'PAGE_VIEW', 'FUNNEL_VIEW' => 'PageView', 'VIEW_VACANCY' => 'ViewContent', 'FORM_SUBMIT', 'LEAD_CREATED' => 'Lead', 'WHATSAPP_CLICK' => 'Contact', default => $event,
            },
            'TIKTOK' => match ($event) {
                'PAGE_VIEW', 'FUNNEL_VIEW', 'VIEW_VACANCY' => 'ViewContent', 'FORM_SUBMIT', 'LEAD_CREATED' => 'SubmitForm', 'WHATSAPP_CLICK' => 'Contact', default => $event,
            },
            default => null,
        };
    }

    public function validate(array $tracking, array $schema): array
    {
        if (is_array($tracking['rules'] ?? null)) {
            foreach ($tracking['rules'] as $index => $rule) {
                if (is_array($rule)) {
                    unset($tracking['rules'][$index]['resolved_mappings']);
                }
            }
        }
        $data = Validator::make($tracking, [
            'enabled' => 'sometimes|boolean', 'destination_ids' => 'sometimes|array|max:3', 'destination_ids.*' => 'integer|distinct|exists:tracking_destinations,id',
            'events' => 'sometimes|array|max:20', 'events.*' => ['distinct', Rule::in(self::PUBLIC_EVENTS)],
            'mappings' => 'sometimes|array|max:50', 'mappings.*' => 'array:destination_id,event,provider_event,version,conversion_label', 'mappings.*.destination_id' => 'required|integer|exists:tracking_destinations,id', 'mappings.*.event' => ['required', Rule::in(config('operations.events'))], 'mappings.*.provider_event' => 'required|regex:/^[A-Za-z0-9_\/-]{1,100}$/', 'mappings.*.version' => 'sometimes|integer|min:1', 'mappings.*.conversion_label' => 'nullable|regex:/^[A-Za-z0-9_-]{1,100}$/',
            'rules' => 'sometimes|array|max:50', 'rules.*' => 'array:id,section_key,trigger_type,canonical_event,destination_ids,parameter_map,consent_required,fire_policy,enabled',
            'rules.*.id' => 'required|regex:/^[a-zA-Z0-9_-]{1,40}$/|distinct', 'rules.*.section_key' => ['required', Rule::in(array_merge(array_column($schema['blocks'] ?? [], 'id'), ['form', 'thank_you', 'whatsapp']))],
            'rules.*.trigger_type' => ['required', Rule::in(array_keys(self::TRIGGERS))], 'rules.*.canonical_event' => ['required', Rule::in(self::PUBLIC_EVENTS)],
            'rules.*.destination_ids' => 'required|array|min:1|max:3', 'rules.*.destination_ids.*' => 'integer|exists:tracking_destinations,id',
            'rules.*.parameter_map' => 'sometimes|array:funnel_id,funnel_version_id,vacancy_public_id,page_type,section_key,event_id,timestamp',
            'rules.*.parameter_map.*' => ['string', Rule::in(['{{funnel_id}}', '{{funnel_version_id}}', '{{vacancy_public_id}}', '{{page_type}}', '{{section_key}}', '{{event_id}}', '{{timestamp}}'])],
            'rules.*.consent_required' => 'sometimes|accepted', 'rules.*.fire_policy' => ['required', Rule::in(['PAGE', 'SESSION', 'ALWAYS'])], 'rules.*.enabled' => 'required|boolean',
        ])->validate();
        if (array_diff(array_keys($tracking), ['enabled', 'destination_ids', 'events', 'mappings', 'rules'])) {
            throw ValidationException::withMessages(['tracking' => 'Konfigurasi tracking tidak dikenal.']);
        }
        foreach ($data['rules'] ?? [] as $rule) {
            if (count(array_unique($rule['destination_ids'])) !== count($rule['destination_ids'])) {
                throw ValidationException::withMessages(['tracking.rules' => 'Destination dalam satu aturan section tidak boleh duplikat.']);
            }
            if (! in_array($rule['canonical_event'], self::TRIGGERS[$rule['trigger_type']], true)) {
                throw ValidationException::withMessages(['tracking.rules' => 'Event tidak sesuai pemicu. Lead hanya dicatat setelah formulir berhasil tersimpan.']);
            }
            if (($rule['trigger_type'] === 'FORM_START' || $rule['trigger_type'] === 'FORM_SUBMIT_SUCCESS') && $rule['section_key'] !== 'form' || $rule['trigger_type'] === 'THANK_YOU_VIEW' && $rule['section_key'] !== 'thank_you' || $rule['trigger_type'] === 'WHATSAPP_CLICK' && ! in_array($rule['section_key'], ['whatsapp', 'thank_you', ...array_column($schema['blocks'] ?? [], 'id')], true)) {
                throw ValidationException::withMessages(['tracking.rules' => 'Section tidak sesuai pemicu.']);
            }
        }
        $ids = array_unique(array_merge($data['destination_ids'] ?? [], ...array_column($data['rules'] ?? [], 'destination_ids')));
        $platforms = DB::table('tracking_destinations')->whereIn('id', $data['destination_ids'] ?? [])->pluck('provider');
        if ($platforms->unique()->count() !== $platforms->count()) {
            throw ValidationException::withMessages(['tracking.destination_ids' => 'Pilih satu destination per platform untuk menghindari tag ganda.']);
        }
        // New assignments must be active; existing references can be disabled later and produce publish warnings.
        foreach ($ids as $id) {
            if (! DB::table('tracking_destinations')->where('id', $id)->exists()) {
                throw ValidationException::withMessages(['tracking' => 'Destination tidak tersedia.']);
            }
        }

        return $data;
    }

    public function assertLegacyMappingPermission(array $before, array $after, bool $canManage): void
    {
        if (! array_key_exists('destination_ids', $after) && ! empty($after['mappings']) && ($before['mappings'] ?? []) !== $after['mappings']) {
            abort_unless($canManage, 403, 'Perubahan mapping vendor memerlukan izin Event Mapping Manage.');
        }
    }

    public function assertNewAssignmentsActive(array $before, array $after): void
    {
        $references = fn ($tracking) => array_unique(array_merge($tracking['destination_ids'] ?? [], array_column($tracking['mappings'] ?? [], 'destination_id'), ...array_column($tracking['rules'] ?? [], 'destination_ids')));
        $added = array_diff($references($after), $references($before));
        if (DB::table('tracking_destinations')->whereIn('id', $added)->where('active', false)->exists()) {
            throw ValidationException::withMessages(['tracking' => 'Destination nonaktif tidak dapat ditambahkan. Aktifkan dahulu melalui Pengaturan Tracking.']);
        }
    }

    public function mappings(array $schema, ?string $ruleId = null): array
    {
        $tracking = $schema['tracking'] ?? [];
        if (isset($tracking['enabled']) && ! $tracking['enabled']) {
            return [];
        }
        $legacyMaps = ! $ruleId && ! array_key_exists('destination_ids', $tracking) ? ($tracking['mappings'] ?? []) : [];
        $rules = $ruleId ? array_values(array_filter($tracking['rules'] ?? [], fn ($rule) => $rule['id'] === $ruleId && $rule['enabled'])) : [];
        $maps = [];
        $ids = $ruleId ? ($rules[0]['destination_ids'] ?? []) : ($tracking['destination_ids'] ?? []);
        $events = $ruleId ? (isset($rules[0]) ? [$rules[0]['canonical_event']] : []) : ($tracking['events'] ?? []);
        // Conversion rules are also evaluated by the server only after a successful transaction.
        if (! $ruleId) {
            foreach ($tracking['rules'] ?? [] as $rule) {
                if ($rule['enabled'] && $rule['trigger_type'] === 'FORM_SUBMIT_SUCCESS') {
                    foreach ($rule['destination_ids'] as $id) {
                        $maps = array_merge($maps, $this->mappedEvents([$id], [$rule['canonical_event']]));
                    }
                }
            }
        }

        return array_values(collect(array_merge($legacyMaps, $maps, $this->mappedEvents($ids, $events)))->unique(fn ($map) => $map['destination_id'].'-'.$map['event'])->all());
    }

    private function mappedEvents(array $ids, array $events): array
    {
        $maps = [];
        foreach (DB::table('tracking_destinations')->whereIn('id', $ids)->get() as $destination) {
            foreach ($events as $event) {
                $mapping = DB::table('tracking_event_mappings')->where('destination_id', $destination->id)->where('canonical_event', $event)->where('active', true)->latest('version')->first();
                $vendor = $mapping?->provider_event ?? $this->defaultMapping($destination->provider, $event);
                if ($destination->provider === 'GOOGLE' && ! str_starts_with($destination->pixel_reference, 'AW-')) {
                    $vendor ??= strtolower($event);
                }
                if ($vendor) {
                    $maps[] = ['destination_id' => $destination->id, 'event' => $event, 'provider_event' => $vendor, 'conversion_label' => $mapping?->conversion_label, 'version' => $mapping?->version ?? 1];
                }
            }
        }

        return $maps;
    }

    public function freeze(array $schema): array
    {
        if (isset($schema['tracking'])) {
            $schema['tracking']['mappings'] = $this->mappings($schema);
            foreach ($schema['tracking']['rules'] ?? [] as $index => $rule) {
                $schema['tracking']['rules'][$index]['resolved_mappings'] = $this->mappings($schema, $rule['id']);
            }
        }

        return $schema;
    }

    public function validDestination(object $destination): bool
    {
        return match ($destination->provider) {
            'META' => (bool) preg_match('/^[0-9]{1,100}$/', $destination->pixel_reference ?? ''),
            'TIKTOK' => (bool) preg_match('/^[A-Za-z0-9-]{1,100}$/', $destination->pixel_reference ?? ''),
            'GOOGLE' => (bool) preg_match('/^(?:AW|G|GT)-[A-Za-z0-9]+$/', $destination->pixel_reference ?? ''),
            default => false,
        };
    }

    public function available(object $destination, ?string $host = null): bool
    {
        if (! $this->validDestination($destination) || ! $destination->active || $destination->active_from && now()->lt($destination->active_from) || $destination->active_until && now()->gt($destination->active_until)) {
            return false;
        }
        $expected = app()->environment('staging') ? 'STAGING' : 'PRODUCTION';
        if (($destination->environment ?? 'PRODUCTION') !== $expected) {
            return false;
        }
        $domains = json_decode($destination->allowed_domains ?? '[]', true) ?? [];
        $host ??= request()->getHost();

        return ! $domains || in_array(strtolower($host), $domains, true);
    }

    public function summary(array $schema): array
    {
        $tracking = $schema['tracking'] ?? [];
        $maps = $this->mappings($schema);
        foreach ($tracking['rules'] ?? [] as $rule) {
            if ($rule['enabled']) {
                $maps = array_merge($maps, $this->mappings($schema, $rule['id']));
            }
        }
        $ids = array_unique(array_merge(array_column($maps, 'destination_id'), $tracking['destination_ids'] ?? [], ...array_column($tracking['rules'] ?? [], 'destination_ids')));
        $destinations = DB::table('tracking_destinations')->whereIn('id', $ids)->get(['id', 'display_name', 'provider', 'pixel_reference', 'allowed_domains', 'active_from', 'active_until', 'active', 'health', 'environment', 'browser_enabled', 'server_enabled']);
        $summary = ['destinations' => $destinations, 'events' => array_values(array_unique(array_column($maps, 'event'))), 'rules' => $tracking['rules'] ?? [], 'consent' => 'Marketing — wajib persetujuan', 'issues' => []];
        if (! $ids || isset($tracking['enabled']) && ! $tracking['enabled']) {
            $summary['issues'][] = ['severity' => 'info', 'message' => 'Tracking belum diaktifkan. Funnel tetap dapat dipublikasikan tanpa ads tracking.'];

            return $summary;
        }
        foreach ($destinations->groupBy(fn ($destination) => $destination->provider.'-'.$destination->pixel_reference) as $group) {
            if ($group->count() > 1) {
                $summary['issues'][] = ['severity' => 'warning', 'message' => 'Pixel/Tag yang sama dipakai beberapa destination. Periksa agar event tidak dihitung ganda.'];
            }
        }
        foreach ($ids as $id) {
            $destination = $destinations->firstWhere('id', $id);
            if (! $destination) {
                $summary['issues'][] = ['severity' => 'blocker', 'message' => 'Destination tracking #'.$id.' tidak tersedia.'];

                continue;
            }
            if (! $this->validDestination($destination)) {
                $summary['issues'][] = ['severity' => 'blocker', 'message' => $destination->display_name.': format Pixel/Tag tidak valid. Perbaiki di Pengaturan Tracking.'];
            } elseif (! $this->available($destination)) {
                $summary['issues'][] = ['severity' => 'warning', 'message' => $destination->display_name.' nonaktif atau tidak sesuai domain/environment. Event tidak akan dikirim.'];
            } elseif (in_array($destination->health, ['ERROR', 'NOT_CONFIGURED', 'WARNING', 'LOCAL_VALIDATED'])) {
                $summary['issues'][] = ['severity' => 'warning', 'message' => $destination->display_name.' belum terverifikasi di vendor atau perlu diperiksa di Diagnostics.'];
            }
            $events = in_array($id, $tracking['destination_ids'] ?? []) ? ($tracking['events'] ?? []) : [];
            foreach ($tracking['rules'] ?? [] as $rule) {
                if ($rule['enabled'] && in_array($id, $rule['destination_ids'])) {
                    $events[] = $rule['canonical_event'];
                }
            }
            foreach (array_unique($events) as $event) {
                $mapping = collect($maps)->first(fn ($map) => $map['destination_id'] == $id && $map['event'] === $event);
                if ($destination->provider === 'GOOGLE' && str_starts_with($destination->pixel_reference, 'AW-') && (! $mapping || $destination->browser_enabled && ! $destination->server_enabled && empty($mapping['conversion_label']))) {
                    $summary['issues'][] = ['severity' => 'blocker', 'message' => 'Pemetaan Google / conversion label untuk '.$event.' belum tersedia.'];
                }
            }
            $conversions = collect($maps)->where('destination_id', $id)->whereIn('event', ['FORM_SUBMIT', 'LEAD_CREATED'])->unique('event');
            if ($conversions->count() > 1 && $conversions->pluck('provider_event')->unique()->count() === 1) {
                $summary['issues'][] = ['severity' => 'warning', 'message' => $destination->display_name.': FORM_SUBMIT dan LEAD_CREATED memakai konversi yang sama. Pilih salah satu untuk menghindari hitungan ganda.'];
            }
        }

        return $summary;
    }
}
