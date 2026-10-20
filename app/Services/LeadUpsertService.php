<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeadUpsertService
{
    public function upsert(string $name, string $phone, ?string $email = null, string $source = 'UNKNOWN'): object
    {
        return DB::transaction(function () use ($name, $phone, $email, $source): object {
            $identity = app(IdentityResolutionService::class);
            $normalized = CsRouter::phone($phone);
            $email = $identity->email($email);
            $lead = $identity->resolve($phone);
            if (! $lead) {
                $inserted = DB::table('acquisition_leads')->insertOrIgnore(['public_token' => (string) Str::uuid(), 'name' => $name, 'normalized_phone' => $normalized, 'email' => $email, 'crm_source' => $source, 'crm_stage_entered_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $lead = DB::table('acquisition_leads')->where('normalized_phone', $normalized)->lockForUpdate()->first();
                $lead->contact_id = $identity->attachLegacy($lead);
                if ($inserted) {
                    app(AttributionService::class)->record($lead->id, 'created-'.$lead->id, 'LEAD_CREATED', $source);
                    app(ConversationCrm::class)->activity($lead->id, 'lead_created', 'Lead dibuat melalui '.$source.'.');
                }
            } elseif ($email && ! $lead->email) {
                DB::table('acquisition_leads')->where('id', $lead->id)->update(['email' => $email, 'updated_at' => now()]);
            }
            if ($email) {
                DB::table('crm_contact_identities')->insertOrIgnore(['contact_id' => $lead->contact_id, 'identity_type' => 'EMAIL', 'raw_value' => $email, 'normalized_value' => $email, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(DuplicateResolutionService::class)->suggest($lead->id);

            return DB::table('acquisition_leads')->find($lead->id);
        }, 3);
    }

    public function interest(int $lead, int $vacancy): void
    {
        DB::table('crm_lead_interests')->insertOrIgnore(['lead_id' => $lead, 'vacancy_id' => $vacancy, 'created_at' => now(), 'updated_at' => now()]);
    }
}
