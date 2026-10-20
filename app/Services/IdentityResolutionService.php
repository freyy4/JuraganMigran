<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IdentityResolutionService
{
    public function email(?string $email): ?string
    {
        $email = mb_strtolower(trim($email ?? ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public function attachLegacy(object $lead): int
    {
        if ($lead->contact_id) {
            return $lead->contact_id;
        }
        $contact = DB::table('crm_contacts')->insertGetId(['primary_name' => $lead->name, 'candidate_id' => $lead->candidate_id, 'created_at' => now(), 'updated_at' => now()]);
        try {
            $phone = CsRouter::phone($lead->normalized_phone);
            DB::table('crm_contact_identities')->insertOrIgnore(['contact_id' => $contact, 'identity_type' => 'PHONE', 'raw_value' => $lead->normalized_phone, 'normalized_value' => $phone, 'created_at' => now(), 'updated_at' => now()]);
            $existing = DB::table('crm_contact_identities')->where('identity_type', 'PHONE')->where('normalized_value', $phone)->first();
            if ($existing && $existing->contact_id !== $contact) {
                $other = DB::table('acquisition_leads')->where('contact_id', $existing->contact_id)->whereNull('merged_into_id')->first();
                if ($other) {
                    DB::table('crm_duplicate_candidates')->insertOrIgnore(['primary_lead_id' => min($lead->id, $other->id), 'secondary_lead_id' => max($lead->id, $other->id), 'confidence' => 100, 'reason' => 'Nomor historis cocok setelah normalisasi; bandingkan sebelum merge.', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        } catch (ValidationException) {
            // Invalid historical values stay available for manual repair.
        }
        if ($email = $this->email($lead->email)) {
            DB::table('crm_contact_identities')->insertOrIgnore(['contact_id' => $contact, 'identity_type' => 'EMAIL', 'raw_value' => $lead->email, 'normalized_value' => $email, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('acquisition_leads')->where('id', $lead->id)->update(['contact_id' => $contact]);

        return $contact;
    }

    public function resolve(string $phone): ?object
    {
        $phone = CsRouter::phone($phone);
        $identity = DB::table('crm_contact_identities')->where('identity_type', 'PHONE')->where('normalized_value', $phone)->first();
        if ($identity) {
            $lead = DB::table('acquisition_leads')->where('contact_id', $identity->contact_id)->whereNull('merged_into_id')->lockForUpdate()->first();
            if ($lead) {
                return $lead;
            }
        }
        $lead = DB::table('acquisition_leads')->where('normalized_phone', $phone)->lockForUpdate()->first();
        if ($lead?->merged_into_id) {
            $lead = DB::table('acquisition_leads')->where('id', $lead->merged_into_id)->lockForUpdate()->first();
        }
        if ($lead && ! $lead->contact_id) {
            $lead->contact_id = $this->attachLegacy($lead);
        }

        return $lead;
    }
}
