<?php

namespace App\Http\Controllers;

use App\Services\AccessPolicy;
use App\Services\FunnelSchema;
use App\Services\SiteAttribution;
use App\Services\TrackingAdapters;
use App\Services\TrackingConfiguration;
use App\Services\TrackingHub;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TrackingController extends Controller
{
    public function settings(Request $request): View|JsonResponse
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'provider' => 'nullable|in:META,TIKTOK,GOOGLE', 'status' => 'nullable|in:active,inactive,error,unverified', 'environment' => 'nullable|in:PRODUCTION,STAGING']);
        $query = DB::table('tracking_destinations as d')->leftJoin('users as u', 'u.id', '=', 'd.created_by')->select('d.id', 'd.provider', 'd.display_name', 'd.pixel_reference', 'd.account_reference', 'd.environment', 'd.active', 'd.browser_enabled', 'd.server_enabled', 'd.consent_category', 'd.allowed_domains', 'd.notes', 'd.health', 'd.last_verified_at', 'd.last_error', 'd.created_at', 'u.name as creator');
        if (! empty($filters['q'])) {
            $query->where(fn ($q) => $q->where('d.display_name', 'like', '%'.$filters['q'].'%')->orWhere('d.pixel_reference', 'like', '%'.$filters['q'].'%'));
        }
        foreach (['provider', 'environment'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('d.'.$field, $filters[$field]);
            }
        }
        match ($filters['status'] ?? '') {
            'active' => $query->where('d.active', true), 'inactive' => $query->where('d.active', false), 'error' => $query->where('d.health', 'ERROR'), 'unverified' => $query->whereNull('d.last_verified_at'), default => null,
        };
        $destinations = $query->latest('d.id')->paginate(20)->withQueryString();
        $usage = [];
        foreach (DB::table('application_funnels as f')->leftJoin('funnel_versions as v', 'v.id', '=', 'f.active_version_id')->get(['f.id', 'f.draft_schema', 'f.active_version_id', 'v.schema_snapshot']) as $funnel) {
            $schemas = [json_decode($funnel->draft_schema, true)];
            if ($funnel->active_version_id) {
                $schemas[] = json_decode($funnel->schema_snapshot ?? '{}', true);
            }
            foreach ($schemas as $schema) {
                $tracking = $schema['tracking'] ?? [];
                foreach (array_unique(array_merge($tracking['destination_ids'] ?? [], array_column($tracking['mappings'] ?? [], 'destination_id'), ...array_column($tracking['rules'] ?? [], 'destination_ids'))) as $id) {
                    $usage[$id][$funnel->id] = true;
                }
            }
        }
        $data = ['destinations' => $destinations, 'usage' => array_map('count', $usage), 'summary' => DB::table('tracking_destinations')->select('provider')->selectRaw('COUNT(*) as total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_count')->groupBy('provider')->get(), 'catalog' => app(TrackingConfiguration::class)->catalog(), 'mappings' => DB::table('tracking_event_mappings')->where('active', true)->get()];

        return ($request->expectsJson() || $request->is('api/*')) ? response()->json($data) : view('tracking.settings', $data);
    }

    public function catalog(): JsonResponse
    {
        return response()->json(['events' => app(TrackingConfiguration::class)->catalog(), 'triggers' => TrackingConfiguration::TRIGGERS]);
    }

    public function testDestination(Request $request, int $destination): JsonResponse
    {
        $data = $request->validate(['mode' => 'required|in:connection,event']);
        $row = DB::table('tracking_destinations')->find($destination) ?? abort(404);
        $secret = app(TrackingAdapters::class)->secret($row);
        $warnings = [];
        if (! $row->active) {
            $warnings[] = 'Destination nonaktif.';
        }
        if ($row->server_enabled && empty($secret['access_token'])) {
            $warnings[] = 'Kredensial server belum tersedia.';
        }
        if ($row->server_enabled && $row->provider === 'GOOGLE' && (empty($secret['developer_token']) || ! $row->account_reference)) {
            $warnings[] = 'Google Ads memerlukan developer token dan ID akun.';
        }
        // Local validation must never claim a vendor has accepted a connection or conversion.
        $status = $warnings ? 'WARNING' : 'LOCAL_VALIDATED';
        $connection = $data['mode'] === 'connection' ? app(TrackingAdapters::class)->checkConnection($row) : null;
        if ($connection) {
            $status = $row->active ? $connection['status'] : 'DISABLED';
        }
        DB::table('tracking_destinations')->where('id', $destination)->update(['health' => $connection || $warnings ? $status : $row->health, 'last_verified_at' => $connection ? now() : $row->last_verified_at, 'updated_at' => now()]);
        $event = null;
        if ($data['mode'] === 'event') {
            $event = app(TrackingHub::class)->emit('PAGE_VIEW', 'destination-test-'.Str::uuid(), [], null, [], 'server', true);
        }
        app(AccessPolicy::class)->audit($request->user(), 'tracking.destination_test', ['destination_id' => $destination, 'mode' => $data['mode'], 'result' => $status]);

        return response()->json(['status' => $status, 'warnings' => $warnings, 'event_id' => $event?->event_id, 'is_test' => true, 'delivery' => $connection ? 'READ_ONLY_CONNECTION_CHECK' : 'LOCAL_VALIDATION_ONLY', 'message' => $connection['message'] ?? 'Validasi lokal selesai. Verifikasi penerimaan vendor melalui Events Manager / Tag Assistant. Tidak mengirim konversi produksi.']);
    }

    public function index(Request $r): View|JsonResponse
    {
        $r->validate(['event' => ['nullable', Rule::in(config('operations.events'))], 'status' => 'nullable|in:PENDING,PROCESSING,RETRY,DEAD,SENT,SKIPPED'], config('site.validation_messages'));
        $events = DB::table('canonical_tracking_events')->where('is_test', false);
        if ($r->filled('event')) {
            $events->where('canonical_event', $r->input('event'));
        }
        $attempts = DB::table('tracking_outbox as o')->join('canonical_tracking_events as e', 'e.id', '=', 'o.event_id')->join('tracking_destinations as d', 'd.id', '=', 'o.destination_id')->select('o.id', 'o.status', 'o.attempts', 'o.last_error', 'o.sent_at', 'o.next_attempt_at', 'e.canonical_event', 'e.is_test', 'e.event_id', 'd.display_name');
        if ($r->filled('event')) {
            $attempts->where('e.canonical_event', $r->input('event'));
        }
        if ($r->filled('status')) {
            $attempts->where('o.status', $r->input('status'));
        }
        $data = ['test_events' => DB::table('canonical_tracking_events')->where('is_test', true)->select('event_id', 'canonical_event', 'source', 'funnel_id', 'safe_context', 'occurred_at')->latest('id')->paginate(20, ['*'], 'test_page')->withQueryString(), 'last_test' => DB::table('canonical_tracking_events')->where('is_test', true)->latest('id')->first(['event_id', 'canonical_event', 'source', 'occurred_at']), 'destinations' => DB::table('tracking_destinations')->select('id', 'provider', 'display_name', 'account_reference', 'pixel_reference', 'active', 'browser_enabled', 'server_enabled', 'health', 'last_success_at', 'last_error', 'version', 'active_from', 'active_until')->get(), 'mappings' => DB::table('tracking_event_mappings')->where('active', true)->get(), 'counts' => (clone $events)->select('canonical_event')->selectRaw('COUNT(*) as total')->groupBy('canonical_event')->get(), 'channels' => DB::table('canonical_tracking_events')->where('is_test', false)->select('safe_context')->latest('id')->limit(2000)->get()->groupBy(fn ($e) => json_decode($e->safe_context, true)['channel'] ?? 'UNKNOWN')->map->count(), 'events' => $events->select('event_id', 'canonical_event', 'source', 'occurred_at')->latest('id')->paginate(20), 'attempts' => $attempts->latest('o.id')->paginate(20, ['*'], 'delivery_page')->withQueryString(), 'logs' => DB::table('tracking_delivery_attempts')->latest('id')->paginate(20, ['*'], 'log_page'), 'stages' => DB::table('pipeline_stages')->where('is_active', true)->get(), 'client_deliveries' => DB::table('tracking_client_deliveries as c')->join('canonical_tracking_events as e', 'e.id', '=', 'c.event_id')->join('tracking_destinations as d', 'd.id', '=', 'c.destination_id')->select('e.event_id', 'e.canonical_event', 'd.display_name', 'c.provider_event', 'c.status', 'c.created_at')->latest('c.id')->paginate(20, ['*'], 'client_page'), 'outbox_counts' => DB::table('tracking_outbox')->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->get()];

        return ($r->expectsJson() || $r->is('api/*')) ? response()->json($data) : view('tracking.index', $data);
    }

    public function destination(Request $r, ?int $destination = null): JsonResponse|RedirectResponse
    {
        abort_unless($r->user()->hasPermission($destination ? 'tracking.destination.update' : 'tracking.destination.create'), 403);
        $data = $r->validate(['environment' => 'sometimes|in:PRODUCTION,STAGING', 'notes' => 'nullable|string|max:2000', 'allowed_domains' => 'sometimes|array|max:20', 'allowed_domains.*' => ['required', 'string', 'max:253', 'regex:/^(?:localhost|[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?)*)$/'], 'access_token' => 'nullable|string|max:10000', 'developer_token' => 'nullable|string|max:1000', 'provider' => 'required|in:META,GOOGLE,TIKTOK', 'display_name' => 'required|string|max:255', 'account_reference' => 'nullable|regex:/^[0-9]{1,30}$/', 'pixel_reference' => 'required|regex:/^[A-Za-z0-9-]{1,100}$/', 'secret_reference' => 'nullable|regex:/^JM_TRACKING_[A-Z0-9_]{1,80}$/', 'browser_enabled' => 'sometimes|boolean', 'server_enabled' => 'sometimes|boolean', 'active' => 'sometimes|boolean', 'active_from' => 'nullable|date', 'active_until' => 'nullable|date|after:active_from'], config('site.validation_messages'));
        $secret = array_filter(Arr::only($data, ['access_token', 'developer_token']), fn ($value) => $value !== null && $value !== '');
        unset($data['access_token'], $data['developer_token']);
        $prior = $destination ? DB::table('tracking_destinations')->find($destination) : null;
        if ($secret || $r->filled('secret_reference') && $r->input('secret_reference') !== ($prior->secret_reference ?? null)) {
            abort_unless($r->user()->hasPermission('tracking.secret.manage'), 403);
        }
        if ($secret) {
            $existing = $prior ? app(TrackingAdapters::class)->secret($prior) : [];
            $data['encrypted_secret'] = Crypt::encryptString(json_encode(array_merge($existing, $secret)));
        }
        if (! $r->user()->hasPermission('tracking.secret.manage')) {
            unset($data['secret_reference']);
        }
        if (isset($data['allowed_domains'])) {
            $data['allowed_domains'] = json_encode(array_values(array_unique(array_map('strtolower', $data['allowed_domains']))));
        }
        $data['updated_by'] = $r->user()->id;
        if ($data['provider'] === 'META' && ! ctype_digit($data['pixel_reference'])) {
            throw ValidationException::withMessages(['pixel_reference' => 'ID Pixel Meta harus angka.']);
        }
        if ($data['provider'] === 'GOOGLE' && ! preg_match('/^(?:AW|G|GT)-[A-Za-z0-9]+$/', $data['pixel_reference'])) {
            throw ValidationException::withMessages(['pixel_reference' => 'Gunakan Google tag ID, misalnya AW-123456789, G-ABC123 atau GT-ABC123.']);
        }
        if ($data['provider'] === 'GOOGLE' && $r->boolean('server_enabled') && ! str_starts_with($data['pixel_reference'], 'AW-')) {
            throw ValidationException::withMessages(['pixel_reference' => 'Server Google Ads membutuhkan destination AW-. Google tag G-/GT- dipakai untuk client-side.']);
        }
        if ($data['provider'] === 'GOOGLE' && $r->boolean('server_enabled') && empty($data['account_reference'])) {
            throw ValidationException::withMessages(['account_reference' => 'Isi ID akun Google Ads untuk konversi server.']);
        }
        $data += ['browser_enabled' => $r->boolean('browser_enabled'), 'server_enabled' => $r->boolean('server_enabled'), 'active' => $r->boolean('active')];
        foreach (['active_from', 'active_until'] as $key) {
            $data[$key] = ! empty($data[$key]) ? Carbon::parse($data[$key], 'Asia/Jakarta')->utc() : null;
        }
        $id = DB::transaction(function () use ($destination, $data): int {
            if ($destination) {
                $row = DB::table('tracking_destinations')->where('id', $destination)->lockForUpdate()->first() ?? abort(404);
                abort_unless($row->provider === $data['provider'], 422, 'Provider tujuan tidak dapat diganti.');
                DB::table('tracking_destinations')->where('id', $destination)->update($data + ['version' => $row->version + 1, 'health' => $data['active'] ? 'NOT_CONFIGURED' : 'DISABLED', 'updated_at' => now()]);

                return $destination;
            }

            return DB::table('tracking_destinations')->insertGetId($data + ['created_by' => $data['updated_by'], 'created_at' => now(), 'updated_at' => now()]);
        });
        $safe = Arr::except($data, ['encrypted_secret', 'secret_reference']);
        app(AccessPolicy::class)->audit($r->user(), 'tracking.destination_saved', ['destination_id' => $id, 'configuration' => $safe, 'before' => $prior ? Arr::only((array) $prior, array_keys($safe)) : null, 'after' => $safe, 'secret_rotated' => (bool) $secret]);

        return ($r->expectsJson() || $r->is('api/*')) ? response()->json(['id' => $id, 'duplicate' => DB::table('tracking_destinations')->where('provider', $data['provider'])->where('pixel_reference', $data['pixel_reference'])->where('id', '!=', $id)->exists()]) : back()->with('success', 'Tujuan tracking disimpan. Kredensial tetap berada di lingkungan server.');
    }

    public function mapping(Request $r, ?int $mapping = null): JsonResponse|RedirectResponse
    {
        if ($mapping) {
            $prior = DB::table('tracking_event_mappings')->find($mapping) ?? abort(404);
            $r->merge(['destination_id' => $prior->destination_id, 'canonical_event' => $prior->canonical_event]);
        }
        $data = $r->validate(['conversion_label' => 'nullable|regex:/^[A-Za-z0-9_-]{1,100}$/', 'destination_id' => 'required|integer|exists:tracking_destinations,id', 'canonical_event' => ['required', Rule::in(config('operations.events'))], 'provider_event' => 'required|regex:/^[A-Za-z0-9_\/-]{1,100}$/', 'stage_code' => 'nullable|string|exists:pipeline_stages,code'], config('site.validation_messages'));
        if (! empty($data['stage_code']) && ! in_array($data['canonical_event'], ['QUALIFIED_LEAD', 'MCU_REACHED', 'LPK_JOINED', 'FLIGHT_READY', 'DEPARTED'], true)) {
            throw ValidationException::withMessages(['stage_code' => 'Pilih milestone CRM untuk pemetaan tahap.']);
        }
        $destination = DB::table('tracking_destinations')->find($data['destination_id']);
        if ($destination->provider === 'GOOGLE' && ! ctype_digit($data['provider_event'])) {
            throw ValidationException::withMessages(['provider_event' => 'Gunakan ID conversion action Google Ads.']);
        }
        $id = DB::transaction(function () use ($data): int {
            $row = DB::table('tracking_destinations')->where('id', $data['destination_id'])->lockForUpdate()->first();
            $version = DB::table('tracking_event_mappings')->where('destination_id', $row->id)->where('canonical_event', $data['canonical_event'])->max('version') + 1;
            DB::table('tracking_event_mappings')->where('destination_id', $row->id)->where('canonical_event', $data['canonical_event'])->update(['active' => false]);

            return DB::table('tracking_event_mappings')->insertGetId($data + ['version' => $version, 'created_at' => now(), 'updated_at' => now()]);
        });
        app(AccessPolicy::class)->audit($r->user(), 'tracking.mapping_versioned', ['mapping_id' => $id, 'configuration' => $data]);

        return ($r->expectsJson() || $r->is('api/*')) ? response()->json(['id' => $id]) : back()->with('success', 'Versi pemetaan event disimpan.');
    }

    public function consent(Request $r): JsonResponse
    {
        $data = $r->validate(['analytics' => 'required|boolean', 'advertising' => 'required|boolean'], config('site.validation_messages'));
        $id = app(TrackingHub::class)->consent($r, $data['analytics'], $data['advertising']);

        return response()->json(['id' => $id, 'analytics' => $data['analytics'], 'advertising' => $data['advertising']]);
    }

    public function event(Request $r): JsonResponse
    {
        $data = $r->validate(['event' => ['required', Rule::in(config('operations.browser_events'))], 'event_id' => 'required|uuid', 'version_id' => 'nullable|integer|min:1', 'funnel_slug' => 'nullable|string|max:255', 'rule_id' => 'nullable|regex:/^[a-zA-Z0-9_-]{1,40}$/', 'page_id' => 'nullable|uuid', 'step' => 'nullable|string|max:100', 'context' => 'prohibited', 'lead_id' => 'prohibited', 'application_id' => 'prohibited'], config('site.validation_messages'));
        $consent = DB::table('tracking_consent_snapshots')->where('id', $r->session()->get('tracking_consent_id'))->whereNull('withdrawn_at')->first();
        if (! $consent || (! $consent->analytics && ! $consent->advertising)) {
            return response()->json(['ok' => true, 'skipped' => true]);
        }
        $refs = [];
        if (! empty($data['funnel_slug'])) {
            $f = DB::table('application_funnels')->where('slug', $data['funnel_slug'])->where('status', 'published')->whereNotNull('active_version_id')->first() ?? abort(404);
            $version = DB::table('funnel_versions')->where('id', $data['version_id'] ?? $f->active_version_id)->where('funnel_id', $f->id)->first() ?? abort(404);
            $refs = ['funnel_id' => $f->id, 'funnel_version_id' => $version->id, 'vacancy_id' => $version->vacancy_id];
        }
        $rule = null;
        if (! empty($data['rule_id'])) {
            abort_unless(isset($version), 422);
            $schema = json_decode($version->schema_snapshot, true);
            $rule = collect($schema['tracking']['rules'] ?? [])->firstWhere('id', $data['rule_id']);
            abort_unless($rule && $rule['enabled'] && ($schema['tracking']['enabled'] ?? true) && $rule['canonical_event'] === $data['event'] && ! in_array($rule['trigger_type'], ['FORM_SUBMIT_SUCCESS']), 422);
        }
        $summary = app(SiteAttribution::class)->summary($r);
        $last = $summary['last_touch'];
        $context = ($last['context'] ?? []) + ['channel' => $last['channel'] ?? 'UNKNOWN', 'path' => parse_url($r->headers->get('referer', ''), PHP_URL_PATH) ?: '/'];
        $session = app(SiteAttribution::class)->session($r);
        $key = 'browser-'.$session.'-'.$data['event_id'];
        if ($rule && $rule['fire_policy'] !== 'ALWAYS') {
            $key = 'rule-'.$session.'-'.$version->id.'-'.$rule['id'].($rule['fire_policy'] === 'PAGE' ? '-'.($data['page_id'] ?? $data['event_id']) : '');
        }
        if ($rule) {
            $context += ['rule_id' => $rule['id'], 'section_key' => $rule['section_key']];
        }
        $event = app(TrackingHub::class)->emit($data['event'], $key, $refs + ['attribution_id' => $last['id'] ?? null], $consent->id, $context, 'browser');

        $destinations = $consent->advertising ? app(TrackingHub::class)->browserConfig($refs['funnel_version_id'] ?? null, $rule['id'] ?? null, $data['event']) : [];

        return response()->json(['ok' => true, 'event_id' => $event->event_id, 'destinations' => app(TrackingHub::class)->clientDispatches($event, $destinations)]);
    }

    public function test(Request $r): JsonResponse|RedirectResponse
    {
        $data = $r->validate(['event' => ['required', Rule::in(config('operations.events'))], 'source' => 'required|in:browser,server'], config('site.validation_messages'));
        $event = app(TrackingHub::class)->emit($data['event'], 'test-'.Str::uuid(), [], null, [], $data['source'], true);
        app(AccessPolicy::class)->audit($r->user(), 'tracking.test_event', ['event_id' => $event->event_id]);

        return ($r->expectsJson() || $r->is('api/*')) ? response()->json(['event_id' => $event->event_id, 'is_test' => true, 'delivery' => 'LOCAL_VALIDATION_ONLY']) : back()->with('success', 'Event uji lokal tercatat dan dikecualikan dari KPI. Tidak dikirim ke akun iklan produksi.');
    }

    private function trackingToken(object $row): string
    {
        return hash('sha256', json_encode([$row->name, (int) $row->vacancy_id, json_decode($row->draft_schema, true), $row->status]));
    }

    private function trackingFunnel(Request $request, int $funnel, bool $write = false): object
    {
        $permission = $write ? 'funnel.manage_draft' : 'funnel.read';
        abort_unless($request->user()->hasPermission($permission), 403);
        $query = DB::table('application_funnels')->where('id', $funnel)->whereIn('vacancy_id', app(AccessPolicy::class)->vacancyIds($request->user(), $permission));
        if ($write) {
            $query->lockForUpdate();
        }

        return $query->first() ?? abort(404);
    }

    public function funnelConfiguration(Request $request, int $funnel): JsonResponse
    {
        $row = $this->trackingFunnel($request, $funnel);
        $schema = json_decode($row->draft_schema, true);

        return response()->json(['funnel_id' => $row->id, 'active_version_id' => $row->active_version_id, 'draft_token' => $this->trackingToken($row), 'tracking' => $schema['tracking'] ?? ['enabled' => false, 'destination_ids' => [], 'events' => [], 'rules' => []], 'summary' => app(TrackingConfiguration::class)->summary($schema), 'catalog' => app(TrackingConfiguration::class)->catalog(), 'triggers' => TrackingConfiguration::TRIGGERS]);
    }

    private function saveFunnelTracking(Request $request, int $funnel, \Closure $change, string $action = 'tracking.funnel_draft_saved'): array
    {
        abort_unless(($request->user()->hasPermission('funnel.tracking.update') || $request->user()->hasPermission('tracking.eventmap.manage')) && $request->user()->hasPermission('funnel.manage_draft'), 403);
        $request->validate(['draft_token' => 'required|string|size:64']);

        return DB::transaction(function () use ($request, $funnel, $change, $action): array {
            $row = $this->trackingFunnel($request, $funnel, true);
            abort_unless(hash_equals($this->trackingToken($row), $request->input('draft_token')), 409, 'Draft telah berubah. Muat ulang konfigurasi sebelum menyimpan.');
            $schema = json_decode($row->draft_schema, true);
            $before = $schema['tracking'] ?? [];
            $tracking = app(TrackingConfiguration::class)->validate($change($before, $schema), $schema);
            app(TrackingConfiguration::class)->assertNewAssignmentsActive($before, $tracking);
            app(TrackingConfiguration::class)->assertLegacyMappingPermission($before, $tracking, $request->user()->hasPermission('tracking.event_map.manage') || $request->user()->hasPermission('tracking.eventmap.manage'));
            $schema['tracking'] = $tracking;
            DB::table('application_funnels')->where('id', $funnel)->update(['draft_schema' => json_encode($schema), 'updated_at' => now()]);
            app(AccessPolicy::class)->audit($request->user(), $action, ['funnel_id' => $funnel, 'before' => $before, 'after' => $tracking]);
            $row->draft_schema = json_encode($schema);

            return ['ok' => true, 'tracking' => $tracking, 'draft_token' => $this->trackingToken($row), 'summary' => app(TrackingConfiguration::class)->summary($schema)];
        });
    }

    public function funnel(Request $request, int $funnel): JsonResponse|RedirectResponse
    {
        if (array_key_exists('mappings', $request->all()) && $request->input('mappings') === null) {
            $request->merge(['mappings' => []]);
        }
        $data = $request->validate(['tracking' => 'sometimes|array', 'mappings' => $request->has('tracking') ? 'sometimes|array|max:50' : 'present|array|max:50', 'mappings.*.destination_id' => 'required|integer|exists:tracking_destinations,id', 'mappings.*.event' => ['required', Rule::in(config('operations.events'))], 'mappings.*.provider_event' => 'required|regex:/^[A-Za-z0-9_\/-]{1,100}$/']);
        $result = $this->saveFunnelTracking($request, $funnel, function () use ($data): array {
            if (isset($data['tracking'])) {
                return $data['tracking'];
            }
            $maps = $data['mappings'] ?? [];
            foreach ($maps as &$map) {
                $destination = DB::table('tracking_destinations')->find($map['destination_id']);
                if ($destination->provider === 'GOOGLE' && ! ctype_digit($map['provider_event'])) {
                    throw ValidationException::withMessages(['mappings' => 'Conversion action Google harus berupa ID angka.']);
                }
                $map['version'] = $destination->version;
            }
            unset($map);

            return ['mappings' => $maps];
        });

        return ($request->expectsJson() || $request->is('api/*')) ? response()->json($result) : to_route('funnels.builder', ['funnel' => $funnel, 'tab' => 'tracking'])->with('success', 'Pemetaan disimpan pada draft. Terbitkan funnel untuk mengaktifkannya.');
    }

    public function addSectionRule(Request $request, int $funnel, string $section): JsonResponse
    {
        $data = $request->validate(['rule' => 'required|array']);
        $result = $this->saveFunnelTracking($request, $funnel, function (array $tracking) use ($data, $section): array {
            $rule = $data['rule'];
            if (isset($rule['section_key']) && $rule['section_key'] !== $section) {
                throw ValidationException::withMessages(['rule.section_key' => 'Section tidak sesuai alamat konfigurasi.']);
            }
            $rule['section_key'] = $section;
            $rule['id'] ??= (string) Str::uuid();
            $tracking['rules'][] = $rule;

            return $tracking;
        }, 'tracking.section_rule_added');

        return response()->json($result, 201);
    }

    public function removeSectionRule(Request $request, int $funnel, string $section, string $rule): JsonResponse
    {
        $result = $this->saveFunnelTracking($request, $funnel, function (array $tracking) use ($section, $rule): array {
            $found = collect($tracking['rules'] ?? [])->first(fn ($item) => $item['id'] === $rule && $item['section_key'] === $section);
            abort_unless($found, 404);
            $tracking['rules'] = array_values(array_filter($tracking['rules'], fn ($item) => $item['id'] !== $rule));

            return $tracking;
        }, 'tracking.section_rule_removed');

        return response()->json($result);
    }

    public function testFunnel(Request $request, int $funnel): JsonResponse
    {
        $data = $request->validate(['schema' => 'required|json|max:2000000', 'draft_token' => 'required|string|size:64', 'rule_id' => 'nullable|regex:/^[A-Za-z0-9_-]{1,40}$/']);
        $row = $this->trackingFunnel($request, $funnel, true);
        abort_unless(hash_equals($this->trackingToken($row), $data['draft_token']), 409, 'Draft telah berubah. Tunggu penyimpanan selesai dan coba kembali.');
        $input = json_decode($data['schema'], true);
        if (! is_array($input)) {
            throw ValidationException::withMessages(['schema' => 'Schema pengujian harus berupa objek.']);
        }
        $schema = app(FunnelSchema::class)->validate($input);
        $rule = isset($data['rule_id']) ? collect($schema['tracking']['rules'] ?? [])->firstWhere('id', $data['rule_id']) : null;
        if (isset($data['rule_id'])) {
            abort_unless($rule, 404);
        }
        $eventName = $rule['canonical_event'] ?? ($schema['tracking']['events'][0] ?? 'PAGE_VIEW');
        $event = app(TrackingHub::class)->emit($eventName, 'funnel-test-'.Str::uuid(), ['funnel_id' => $row->id, 'vacancy_id' => $row->vacancy_id], null, $rule ? ['section_key' => $rule['section_key']] : [], in_array($eventName, config('operations.browser_events'), true) ? 'browser' : 'server', true);
        $summary = app(TrackingConfiguration::class)->summary($schema);
        app(AccessPolicy::class)->audit($request->user(), 'tracking.funnel_test', ['funnel_id' => $row->id, 'event_id' => $event->event_id, 'rule_id' => $rule['id'] ?? null]);

        return response()->json(['is_test' => true, 'delivery' => 'LOCAL_VALIDATION_ONLY', 'event_id' => $event->event_id, 'canonical_event' => $eventName, 'summary' => $summary, 'mappings' => app(TrackingConfiguration::class)->mappings($schema, $rule['id'] ?? null), 'message' => 'Event uji tercatat di Diagnostics. Tidak membuat lead, mengirim konversi vendor, atau mengubah draft.']);
    }

    public function retry(Request $r, int $outbox): RedirectResponse|JsonResponse
    {
        $row = DB::table('tracking_outbox')->find($outbox) ?? abort(404);
        abort_unless(in_array($row->status, ['DEAD', 'RETRY']), 422);
        DB::table('tracking_outbox')->where('id', $outbox)->whereIn('status', ['DEAD', 'RETRY'])->update(['status' => 'PENDING', 'attempts' => 0, 'next_attempt_at' => now(), 'leased_until' => null]);
        app(AccessPolicy::class)->audit($r->user(), 'tracking.retry', ['outbox_id' => $outbox]);

        return ($r->expectsJson() || $r->is('api/*')) ? response()->json(['ok' => true]) : back()->with('success', 'Event masuk antrean ulang.');
    }
}
