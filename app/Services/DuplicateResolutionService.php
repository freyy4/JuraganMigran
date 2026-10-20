<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DuplicateResolutionService
{
    public function suggest(int $id): void
    {
        $lead = DB::table('acquisition_leads')->find($id);
        $prefix = mb_substr(trim($lead->name), 0, 4);
        $query = DB::table('acquisition_leads')->where('id', '!=', $id)->whereNull('merged_into_id')->where(function ($q) use ($lead, $prefix): void {
            $q->where('name', 'like', $prefix.'%')->orWhere('normalized_phone', 'like', substr($lead->normalized_phone, 0, 8).'%');
            if ($lead->email) {
                $q->orWhere('email', 'like', mb_substr($lead->email, 0, 4).'%');
            }
        });
        foreach ($query->orderBy('id')->limit(200)->get() as $other) {
            $email = $lead->email && mb_strtolower($lead->email) === mb_strtolower($other->email ?? '');
            similar_text(mb_strtolower($lead->name), mb_strtolower($other->name), $similarity);
            $phoneNear = levenshtein($lead->normalized_phone, $other->normalized_phone) === 1;
            $emailNear = $lead->email && $other->email && levenshtein(mb_strtolower($lead->email), mb_strtolower($other->email)) === 1;
            if (! $email && $similarity < 99 && ! ($similarity >= 80 && ($phoneNear || $emailNear))) {
                continue;
            }
            $confidence = $email ? 95 : ($phoneNear || $emailNear ? 87 : 75);
            DB::table('crm_duplicate_candidates')->insertOrIgnore(['primary_lead_id' => min($id, $other->id), 'secondary_lead_id' => max($id, $other->id), 'confidence' => $confidence, 'reason' => $email ? 'Email sama; nomor berbeda, perlu verifikasi.' : 'Nama serupa; bandingkan nomor dan histori sebelum merge.', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function merge(int $primary, int $secondary, int $actor, string $reason): void
    {
        abort_if($primary === $secondary, 422);
        DB::transaction(function () use ($primary, $secondary, $actor, $reason): void {
            $rows = DB::table('acquisition_leads')->whereIn('id', [$primary, $secondary])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $a = $rows[$primary] ?? abort(404);
            $b = $rows[$secondary] ?? abort(404);
            abort_if($a->merged_into_id || $b->merged_into_id, 409, 'Lead sudah digabungkan. Muat ulang.');
            abort_if($a->candidate_id && $b->candidate_id && $a->candidate_id !== $b->candidate_id, 422, 'Candidate berbeda; perlu rekonsiliasi profil formal sebelum merge.');
            foreach (['lead_submissions', 'crm_conversations', 'crm_follow_ups', 'crm_activities', 'followup_events', 'cs_assignment_events', 'site_attribution_touches', 'site_contact_requests', 'crm_attribution_touchpoints'] as $table) {
                DB::table($table)->where('lead_id', $secondary)->update(['lead_id' => $primary]);
            }
            foreach (['crm_lead_tags', 'crm_lead_interests'] as $table) {
                foreach (DB::table($table)->where('lead_id', $secondary)->get() as $item) {
                    $values = (array) $item;
                    unset($values['id']);
                    $values['lead_id'] = $primary;
                    DB::table($table)->insertOrIgnore($values);
                    if ($table === 'crm_lead_interests' && $item->application_id) {
                        DB::table($table)->where('lead_id', $primary)->where('vacancy_id', $item->vacancy_id)->whereNull('application_id')->update(['application_id' => $item->application_id]);
                    }
                }
            }
            if ($a->contact_id && $b->contact_id && $a->contact_id !== $b->contact_id) {
                DB::table('crm_contacts')->where('id', $b->contact_id)->update(['status' => 'MERGED', 'updated_at' => now()]);
                DB::table('crm_contact_identities')->where('contact_id', $b->contact_id)->update(['contact_id' => $a->contact_id]);
                DB::table('crm_attribution_touchpoints')->where('lead_id', $primary)->update(['contact_id' => $a->contact_id]);
            }
            if ($a->contact_id) {
                DB::table('crm_contacts')->where('id', $a->contact_id)->update(['candidate_id' => $a->candidate_id ?? $b->candidate_id]);
            }
            DB::table('acquisition_leads')->where('id', $primary)->update(['candidate_id' => $a->candidate_id ?? $b->candidate_id, 'status' => ($a->candidate_id || $b->candidate_id) ? 'converted' : $a->status, 'crm_stage' => ($a->candidate_id || $b->candidate_id) ? 'CONVERTED' : $a->crm_stage, 'converted_at' => $a->converted_at ?? $b->converted_at, 'assigned_cs_id' => $a->assigned_cs_id ?? $b->assigned_cs_id, 'crm_version' => DB::raw('crm_version + 1'), 'updated_at' => now()]);
            DB::table('acquisition_leads')->where('id', $secondary)->update(['merged_into_id' => $primary, 'archived_at' => now(), 'crm_version' => DB::raw('crm_version + 1'), 'updated_at' => now()]);
            DB::table('crm_lead_merge_records')->insert(['primary_lead_id' => $primary, 'secondary_lead_id' => $secondary, 'actor_user_id' => $actor, 'reason' => $reason, 'snapshot_encrypted' => Crypt::encryptString(json_encode([$a, $b])), 'merged_at' => now()]);
            DB::table('crm_duplicate_candidates')->where('primary_lead_id', min($primary, $secondary))->where('secondary_lead_id', max($primary, $secondary))->update(['status' => 'MERGED', 'updated_at' => now()]);
            $otherPairs = DB::table('crm_duplicate_candidates')->where('status', 'PENDING')->where(fn ($q) => $q->where('primary_lead_id', $secondary)->orWhere('secondary_lead_id', $secondary))->get();
            foreach ($otherPairs as $pair) {
                $other = $pair->primary_lead_id === $secondary ? $pair->secondary_lead_id : $pair->primary_lead_id;
                if ($other !== $primary) {
                    DB::table('crm_duplicate_candidates')->insertOrIgnore(['primary_lead_id' => min($primary, $other), 'secondary_lead_id' => max($primary, $other), 'confidence' => $pair->confidence, 'reason' => $pair->reason, 'status' => 'PENDING', 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('crm_duplicate_candidates')->where('id', $pair->id)->update(['status' => 'REPLACED', 'updated_at' => now()]);
            }
            app(ConversationCrm::class)->nextFollowUp($primary);
            app(ConversationCrm::class)->activity($primary, 'duplicate_merged', 'Lead #'.$secondary.' digabungkan: '.$reason, $actor);
        });
    }
}
