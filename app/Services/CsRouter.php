<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CsRouter
{
    public static function phone(string $phone): string
    {
        if (! preg_match('/^[+0-9\s().-]+$/', $phone)) {
            throw ValidationException::withMessages(['whatsapp' => 'Nomor WhatsApp hanya boleh memuat angka dan pemisah nomor.']);
        }
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($phone, '08')) {
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
        return app(AssignmentPolicy::class)->assignForm($leadId, $vacancyId, $submissionId);
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
