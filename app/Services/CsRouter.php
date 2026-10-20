<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CsRouter
{
    public static function phone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }
        if (! preg_match('/^[1-9][0-9]{8,14}$/', $phone)) {
            throw ValidationException::withMessages(['whatsapp' => 'Nomor WhatsApp tidak valid. Gunakan kode negara.']);
        }

        return '+'.$phone;
    }

    public function availableForVacancy(int $vacancyId): bool
    {
        return DB::table('cs_members')->join('cs_groups', 'cs_groups.id', '=', 'cs_members.group_id')
            ->join('cs_vacancy_mappings', 'cs_vacancy_mappings.group_id', '=', 'cs_groups.id')
            ->where('cs_vacancy_mappings.vacancy_id', $vacancyId)->where('cs_groups.is_active', true)->where('cs_members.is_active', true)
            ->where(fn ($query) => $query->whereNull('cs_members.user_id')->orWhereIn('cs_members.user_id', app(AssignmentPolicy::class)->activeUserIds()))->exists();
    }

    public function assign(int $leadId, int $vacancyId, ?int $submissionId = null): ?object
    {
        return DB::transaction(function () use ($leadId, $vacancyId, $submissionId): ?object {
            $lead = DB::table('acquisition_leads')->where('id', $leadId)->lockForUpdate()->first();
            app(AssignmentPolicy::class)->syncUnassignedConversations($leadId, $lead->assigned_cs_id);
            if ($lead->assigned_member_id) {
                return DB::table('cs_members')->find($lead->assigned_member_id);
            }
            if ($lead->assigned_cs_id) {
                $owner = DB::table('cs_members')->where('user_id', $lead->assigned_cs_id)->where('is_active', true)->first();
                if ($owner) {
                    DB::table('acquisition_leads')->where('id', $leadId)->update(['assigned_member_id' => $owner->id]);
                }

                return $owner;
            }
            $mapping = DB::table('cs_vacancy_mappings')->where('vacancy_id', $vacancyId)->first();
            $group = $mapping ? DB::table('cs_groups')->where('id', $mapping->group_id)->where('is_active', true)->lockForUpdate()->first() : null;
            $members = $group ? DB::table('cs_members')->where('group_id', $group->id)->where('is_active', true)->where(fn ($q) => $q->whereNull('user_id')->orWhereIn('user_id', app(AssignmentPolicy::class)->activeUserIds()))->orderBy('id')->get() : collect();
            $member = $members->first(fn ($member) => $member->id > ($group->last_member_id ?? 0)) ?? $members->first();
            if ($member) {
                DB::table('acquisition_leads')->where('id', $leadId)->update(['assigned_member_id' => $member->id, 'assigned_cs_id' => $member->user_id, 'updated_at' => now()]);
                DB::table('cs_groups')->where('id', $group->id)->update(['last_member_id' => $member->id, 'updated_at' => now()]);
                DB::table('cs_members')->where('id', $member->id)->update(['last_assigned_at' => now()]);
                app(AssignmentPolicy::class)->syncUnassignedConversations($leadId, $member->user_id);
            }
            DB::table('cs_assignment_events')->insert(['lead_id' => $leadId, 'member_id' => $member?->id, 'submission_id' => $submissionId, 'status' => $member ? 'assigned' : ($mapping ? 'no_active_cs' : 'unmapped_vacancy'), 'created_at' => now(), 'updated_at' => now()]);
            app(AccessPolicy::class)->audit(auth()->user(), 'cs.assignment', ['lead_id' => $leadId, 'member_id' => $member?->id, 'status' => $member ? 'assigned' : 'queued']);

            return $member;
        });
    }

    public function message(object $lead, object $vacancy, ?object $member, ?int $templateId = null, ?object $submission = null): string
    {
        $template = $templateId ? DB::table('whatsapp_templates')->where('id', $templateId)->where('status', 'active')->where(fn ($q) => $q->whereNull('vacancy_id')->orWhere('vacancy_id', $vacancy->id))->first() : null;
        $template ??= DB::table('whatsapp_templates')->where('vacancy_id', $vacancy->id)->where('status', 'active')->latest('id')->first();
        $template ??= DB::table('whatsapp_templates')->whereNull('vacancy_id')->where('status', 'active')->latest('id')->first();
        $body = $template?->body ?? 'Halo, saya {{name}}. Saya mendaftar untuk {{vacancy}}. Nomor referensi {{application_id}}. Mohon dibantu proses selanjutnya.';

        return strtr($body, ['{{name}}' => $lead->name, '{{phone}}' => $lead->normalized_phone, '{{vacancy}}' => $vacancy->title, '{{country}}' => $vacancy->jobOrder?->country?->name ?? '', '{{sponsor}}' => $vacancy->jobOrder?->company?->name ?? '', '{{stage}}' => 'Pendaftaran', '{{cs_name}}' => $member?->name ?? '', '{{candidate_id}}' => $lead->candidate_id ? 'CPMI terdaftar' : 'Belum dikonversi', '{{lead_id}}' => $lead->public_token, '{{application_id}}' => $submission?->public_token ?? $lead->public_token, '{{funnel_name}}' => $submission?->funnel_name ?? 'Juragan Migran']);
    }

    public function url(object $member, string $message): string
    {
        return 'https://wa.me/'.ltrim(self::phone($member->whatsapp), '+').'?text='.rawurlencode($message);
    }
}
