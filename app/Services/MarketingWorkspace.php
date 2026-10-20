<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MarketingWorkspace
{
    /** @return array<int, int> */
    public function assetUsage(): array
    {
        $usage = DB::table('vacancies')->whereNotNull('site_asset_id')->select('site_asset_id')->selectRaw('COUNT(*) as total')->groupBy('site_asset_id')->pluck('total', 'site_asset_id')->all();
        foreach (['site_pages' => 'draft_schema', 'site_page_versions' => 'schema'] as $table => $column) {
            foreach (DB::table($table)->select('id', $column)->lazyById(200) as $row) {
                $schema = json_decode($row->{$column} ?? '{}', true);
                $ids = array_unique(array_filter([...array_column($schema['blocks'] ?? [], 'asset_id'), $schema['seo']['og_asset_id'] ?? null]));
                foreach ($ids as $id) {
                    $usage[(int) $id] = ($usage[(int) $id] ?? 0) + 1;
                }
            }
        }

        return $usage;
    }

    /** @return array<string, mixed> */
    public function website(Request $request): array
    {
        $data = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'path' => 'nullable|string|max:255', 'device' => 'nullable|in:mobile,desktop', 'channel' => 'nullable|string|max:100', 'compare' => 'nullable|boolean', 'export' => 'nullable|in:csv']);
        $from = Carbon::parse($data['from'] ?? now('Asia/Jakarta')->subDays(29)->toDateString(), 'Asia/Jakarta')->startOfDay();
        $to = Carbon::parse($data['to'] ?? now('Asia/Jakarta')->toDateString(), 'Asia/Jakarta')->startOfDay()->addDay();
        abort_if($to->diffInDays($from, true) > 366, 422, 'Pilih periode maksimal 366 hari.');
        $touch = DB::table('site_attribution_touches');
        foreach (['path', 'channel'] as $field) {
            if ($data[$field] ?? '') {
                $touch->where($field, $data[$field]);
            }
        }
        if ($data['device'] ?? '') {
            $touch->where('device_class', $data['device']);
        }
        $current = (clone $touch)->where('created_at', '>=', $from->copy()->utc())->where('created_at', '<', $to->copy()->utc());
        $events = DB::table('site_events')->where('is_test', false)->where('created_at', '>=', $from->copy()->utc())->where('created_at', '<', $to->copy()->utc());
        if ($data['path'] ?? '') {
            $events->where('path', $data['path']);
        }
        if (($data['device'] ?? '') || ($data['channel'] ?? '')) {
            $events->whereIn('anonymous_session_id', (clone $current)->select('anonymous_session_id'));
        }
        $counts = (clone $events)->select('event')->selectRaw('COUNT(*) AS total')->groupBy('event')->get();
        $sessions = (clone $current)->distinct()->count('anonymous_session_id');
        $previousStart = $from->copy()->subDays((int) $from->diffInDays($to));
        $previous = (clone $touch)->where('created_at', '>=', $previousStart->utc())->where('created_at', '<', $from->copy()->utc());
        $daily = (clone $current)->selectRaw('DATE(created_at) as day, COUNT(DISTINCT anonymous_session_id) as total')->groupByRaw('DATE(created_at)')->orderBy('day')->get();
        $previousDaily = (clone $previous)->selectRaw('DATE(created_at) as day, COUNT(DISTINCT anonymous_session_id) as total')->groupByRaw('DATE(created_at)')->orderBy('day')->get();
        $eventMap = $counts->pluck('total', 'event');
        $applySessions = (clone $events)->where('event', 'apply_click')->distinct()->count('anonymous_session_id');

        return ['from' => $from->toDateString(), 'to' => $to->copy()->subDay()->toDateString(), 'sessions' => $sessions, 'previousSessions' => (clone $previous)->distinct()->count('anonymous_session_id'), 'ctr' => $sessions ? round($applySessions / $sessions * 100, 1) : null, 'events' => $counts, 'eventMap' => $eventMap, 'daily' => $daily, 'previousDaily' => $previousDaily, 'channels' => (clone $current)->select('channel')->selectRaw('COUNT(DISTINCT anonymous_session_id) AS total')->groupBy('channel')->orderByDesc('total')->limit(15)->get(), 'touches' => (clone $current)->select('path')->selectRaw('COUNT(DISTINCT anonymous_session_id) AS total')->groupBy('path')->orderByDesc('total')->limit(15)->get(), 'devices' => (clone $current)->select('device_class')->selectRaw('COUNT(DISTINCT anonymous_session_id) AS total')->groupBy('device_class')->get(), 'contacts' => DB::table('site_contact_requests')->where('created_at', '>=', $from->copy()->utc())->where('created_at', '<', $to->copy()->utc())->count()];
    }

    /** @return array<string, mixed> */
    public function acquisition(Request $request): array
    {
        $allowed = app(AccessPolicy::class)->vacancyIds($request->user(), 'marketing.analytics.read', true);
        $query = DB::table('lead_submissions as s')->leftJoin('acquisition_leads as lead', 'lead.id', '=', 's.lead_id')->where('s.is_test', false)->whereIn('s.vacancy_id', $allowed);
        if ($request->filled('date_from')) {
            $query->where('s.created_at', '>=', Carbon::parse($request->date_from, 'Asia/Jakarta')->startOfDay()->utc());
        }
        if ($request->filled('date_to')) {
            $query->where('s.created_at', '<', Carbon::parse($request->date_to, 'Asia/Jakarta')->startOfDay()->addDay()->utc());
        }
        if ($request->filled('vacancy_id')) {
            $query->where('s.vacancy_id', $request->vacancy_id);
        }
        foreach (['campaign_id', 'source', 'medium'] as $field) {
            if ($request->filled($field)) {
                $key = ['source' => 'utm_source', 'medium' => 'utm_medium'][$field] ?? $field;
                $query->where('s.attribution->'.$key, (string) $request->input($field));
            }
        }
        if ($request->filled('search')) {
            $query->where('lead.name', 'like', '%'.$request->search.'%');
        }
        $funnelRows = (clone $query)->join('application_funnels as f', 'f.id', '=', 's.funnel_id')->select('f.name')->selectRaw('COUNT(DISTINCT s.lead_id) as total')->groupBy('f.name')->orderByDesc('total')->limit(10)->get();
        $vacancyRows = (clone $query)->join('vacancies as v', 'v.id', '=', 's.vacancy_id')->select('v.title as name')->selectRaw('COUNT(DISTINCT s.lead_id) as total')->groupBy('v.title')->orderByDesc('total')->limit(10)->get();

        $leads = (clone $query)->distinct()->count('s.lead_id');
        $qualified = (clone $query)->whereIn('lead.status', ['qualified', 'converted'])->distinct()->count('s.lead_id');
        $previous = null;
        if ($request->boolean('compare') && $request->filled('date_from') && $request->filled('date_to')) {
            $from = Carbon::parse($request->date_from, 'Asia/Jakarta');
            $to = Carbon::parse($request->date_to, 'Asia/Jakarta');
            $days = (int) $from->diffInDays($to) + 1;
            $previousRequest = clone $request;
            $previousRequest->merge(['compare' => 0, 'date_from' => $from->copy()->subDays($days)->toDateString(), 'date_to' => $from->copy()->subDay()->toDateString()]);
            $previous = $this->acquisition($previousRequest);
        }

        return ['previous' => $previous, 'qualified' => $qualified, 'conversion' => $leads ? round($qualified / $leads * 100, 1) : null, 'leads' => (clone $query)->distinct()->count('s.lead_id'), 'candidates' => (clone $query)->distinct()->count('lead.candidate_id'), 'submissions' => (clone $query)->count(), 'funnels' => $funnelRows, 'vacancies' => $vacancyRows];
    }
}
