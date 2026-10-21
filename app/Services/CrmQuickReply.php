<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CrmQuickReply
{
    public const VARIABLES = ['nama', 'program', 'negara', 'nama_cs'];

    public static function render(string $body, object $lead, string $agent): string
    {
        $interest = DB::table('crm_lead_interests as interest')
            ->join('vacancies as vacancy', 'vacancy.id', '=', 'interest.vacancy_id')
            ->join('job_orders as job', 'job.id', '=', 'vacancy.job_order_id')
            ->join('countries as country', 'country.id', '=', 'job.country_id')
            ->where('interest.lead_id', $lead->id)->orderByDesc('interest.id')
            ->first(['vacancy.title as program', 'country.name as country']);

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/u', fn (array $match): string => match ($match[1]) {
            'nama' => $lead->name,
            'program' => $interest?->program ?? 'program yang diminati',
            'negara' => $interest?->country ?? 'negara tujuan',
            'nama_cs' => $agent,
            default => $match[0],
        }, $body);
    }
}
