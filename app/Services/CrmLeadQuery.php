<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CrmLeadQuery
{
    public function filters(Request $request): array
    {
        return $request->validate(['search' => 'nullable|string|max:100', 'stage' => ['nullable', Rule::in(array_keys(config('conversation.stages')))], 'owner' => 'nullable|integer', 'tag' => 'nullable|integer', 'overdue' => 'nullable|boolean', 'source' => 'nullable|string|max:100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'view' => 'nullable|integer', 'channel' => 'nullable|integer', 'vacancy_id' => 'nullable|integer', 'country_id' => 'nullable|integer', 'campaign' => 'nullable|string|max:100', 'unread' => 'nullable|boolean', 'mode' => 'nullable|in:kanban,list', 'preset' => 'nullable|in:active,mine,won,lost,no_task,overdue,unassigned', 'sort' => 'nullable|in:followup,created,name,activity', 'assignment_state' => 'nullable|in:assigned,unassigned', 'conversion_state' => 'nullable|in:converted,unconverted', 'followup_state' => 'nullable|in:none,scheduled,overdue', 'activity_from' => 'nullable|date_format:Y-m-d', 'activity_to' => 'nullable|date_format:Y-m-d|after_or_equal:activity_from', 'per_page' => 'nullable|integer|in:25,50,100', 'utm_medium' => 'nullable|string|max:100', 'column' => ['nullable', Rule::in(array_keys(config('conversation.stages')))], 'cursor' => 'nullable|string|max:1000']);
    }

    public function query(Request $request, string $permission = 'crm.pipeline.read'): Builder
    {
        $filters = $this->filters($request);
        $q = app(ConversationCrm::class)->leads($request, $permission)->whereNull('archived_at');
        if ($search = $filters['search'] ?? null) {
            $q->where(function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')->orWhere('normalized_phone', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%');
                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search)->orWhere('candidate_id', (int) $search);
                }
            });
        }
        foreach (['stage' => 'crm_stage', 'owner' => 'assigned_cs_id'] as $input => $field) {
            if ($request->filled($input)) {
                $q->where($field, $filters[$input]);
            }
        }
        if ($request->boolean('overdue')) {
            $q->where('next_followup_at', '<', now());
        }
        if ($request->filled('tag')) {
            $q->whereIn('id', DB::table('crm_lead_tags')->where('tag_id', $filters['tag'])->select('lead_id'));
        }
        if ($request->filled('from')) {
            $q->where('created_at', '>=', Carbon::parse($filters['from'], 'Asia/Jakarta')->startOfDay()->utc());
        }
        if ($request->filled('to')) {
            $q->where('created_at', '<=', Carbon::parse($filters['to'], 'Asia/Jakarta')->endOfDay()->utc());
        }
        foreach (['source' => 'utm_source', 'campaign' => 'utm_campaign'] as $input => $attributionKey) {
            if ($request->filled($input)) {
                $q->where(function ($query) use ($input, $attributionKey, $filters): void {
                    if ($input === 'source') {
                        $query->where('crm_source', $filters[$input]);
                    }
                    $query->orWhereIn('id', DB::table('lead_submissions')->where('is_test', false)->where('attribution->'.$attributionKey, $filters[$input])->select('lead_id'))->orWhereIn('id', DB::table('crm_attribution_touchpoints')->where('context->'.$attributionKey, $filters[$input])->select('lead_id'));
                });
            }
        }
        if ($request->filled('channel') || $request->boolean('unread')) {
            $q->whereIn('id', app(ConversationCrm::class)->conversations($request)->when($request->filled('channel'), fn ($query) => $query->where('channel_id', $filters['channel']))->when($request->boolean('unread'), fn ($query) => $query->where('unread_count', '>', 0))->select('lead_id'));
        }
        if ($request->filled('vacancy_id') || $request->filled('country_id')) {
            $vacancies = DB::table('vacancies')->join('job_orders', 'job_orders.id', '=', 'vacancies.job_order_id')->when($request->filled('vacancy_id'), fn ($query) => $query->where('vacancies.id', $filters['vacancy_id']))->when($request->filled('country_id'), fn ($query) => $query->where('job_orders.country_id', $filters['country_id']))->select('vacancies.id');
            $q->where(fn ($query) => $query->whereIn('id', DB::table('lead_submissions')->where('is_test', false)->whereIn('vacancy_id', clone $vacancies)->select('lead_id'))->orWhereIn('id', DB::table('crm_lead_interests')->whereIn('vacancy_id', clone $vacancies)->select('lead_id')));
        }
        if ($request->filled('assignment_state')) {
            ($filters['assignment_state'] === 'unassigned') ? $q->whereNull('assigned_cs_id') : $q->whereNotNull('assigned_cs_id');
        }
        if ($request->filled('conversion_state')) {
            ($filters['conversion_state'] === 'converted') ? $q->whereNotNull('candidate_id') : $q->whereNull('candidate_id');
        }
        if ($request->filled('followup_state')) {
            match ($filters['followup_state']) {
                'none' => $q->whereNull('next_followup_at'),
                'overdue' => $q->where('next_followup_at', '<', now()),
                default => $q->whereNotNull('next_followup_at')->where('next_followup_at', '>=', now()),
            };
        }
        if ($request->filled('activity_from')) {
            $q->where('updated_at', '>=', Carbon::parse($filters['activity_from'], 'Asia/Jakarta')->startOfDay()->utc());
        }
        if ($request->filled('activity_to')) {
            $q->where('updated_at', '<=', Carbon::parse($filters['activity_to'], 'Asia/Jakarta')->endOfDay()->utc());
        }
        $preset = $filters['preset'] ?? 'active';
        match ($preset) {
            'mine' => $q->where('assigned_cs_id', $request->user()->id),
            'unassigned' => $q->whereNull('assigned_cs_id'),
            'won' => $q->where('crm_stage', 'CONVERTED'),
            'lost' => $q->where('crm_stage', 'LOST'),
            'no_task' => $q->whereNotIn('id', DB::table('crm_follow_ups')->where('status', 'SCHEDULED')->select('lead_id')),
            'overdue' => $q->where('next_followup_at', '<', now()),
            default => null,
        };
        if ($request->filled('utm_medium')) {
            $q->whereIn('id', DB::table('lead_submissions')->where('is_test', false)->where('attribution->utm_medium', $filters['utm_medium'])->select('lead_id'));
        }

        return $q;
    }
}
