<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConversionService
{
    public function convert(Request $request, int $lead, int $vacancy): Application
    {
        return DB::transaction(function () use ($request, $lead, $vacancy): Application {
            $row = DB::table('acquisition_leads')->where('id', $lead)->lockForUpdate()->first();
            if (! in_array($row->crm_stage, ['QUALIFIED', 'CONVERTED'], true)) {
                throw ValidationException::withMessages(['status' => 'Lead harus Qualified sebelum dikonversi.']);
            }
            if (DB::table('crm_duplicate_candidates')->where('status', 'PENDING')->where('confidence', '>=', 85)->where(fn ($q) => $q->where('primary_lead_id', $lead)->orWhere('secondary_lead_id', $lead))->exists()) {
                throw ValidationException::withMessages(['status' => 'Tinjau saran duplikat berkeyakinan tinggi sebelum konversi.']);
            }
            app(LeadUpsertService::class)->interest($lead, $vacancy);
            if (! $row->contact_id) {
                $row->contact_id = app(IdentityResolutionService::class)->attachLegacy($row);
            }
            $candidateId = $row->candidate_id ?? DB::table('crm_contacts')->where('id', $row->contact_id)->value('candidate_id');
            $candidate = $candidateId ? Candidate::withoutGlobalScope('access')->findOrFail($candidateId) : null;
            if (! $candidate) {
                $digits = ltrim($row->normalized_phone, '+');
                $local = str_starts_with($digits, '62') ? '0'.substr($digits, 2) : $digits;
                $normalized = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(whatsapp, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '')";
                $people = Person::whereRaw($normalized.' IN (?, ?)', [$digits, $local])->lockForUpdate()->limit(2)->get();
                abort_if($people->count() > 1, 422, 'Beberapa profil cocok. Tinjau duplikasi sebelum konversi.');
                $person = $people->first();
                $candidates = $person ? Candidate::withoutGlobalScope('access')->where('person_id', $person->id)->limit(2)->get() : collect();
                abort_if($candidates->count() > 1, 422, 'Beberapa candidate cocok. Tinjau duplikasi.');
                $candidate = $candidates->first();
                if (! $candidate) {
                    $person ??= Person::create(['full_name' => $row->name, 'whatsapp' => $row->normalized_phone, 'email' => $row->email]);
                    $candidate = $person->candidates()->create(['candidate_code' => 'CPMI-'.Str::ulid(), 'status' => 'candidate', 'registered_at' => now(), 'created_by' => $request->user()->id]);
                }
            }
            $application = Application::withoutGlobalScope('access')->where('candidate_id', $candidate->id)->where('vacancy_id', $vacancy)->whereNotIn('status', ['closed', 'cancelled', 'rejected'])->first();
            $application ??= Application::create(['application_code' => 'APP-'.Str::ulid(), 'candidate_id' => $candidate->id, 'vacancy_id' => $vacancy, 'assigned_cs_id' => $row->assigned_cs_id, 'status' => 'active', 'applied_at' => now()]);
            DB::table('crm_lead_interests')->updateOrInsert(['lead_id' => $lead, 'vacancy_id' => $vacancy], ['application_id' => $application->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('lead_submissions')->where('lead_id', $lead)->where('vacancy_id', $vacancy)->where('is_test', false)->update(['application_id' => $application->id]);
            DB::table('acquisition_leads')->where('id', $lead)->update(['candidate_id' => $candidate->id, 'status' => 'converted', 'crm_stage' => 'CONVERTED', 'converted_at' => $row->converted_at ?? now(), 'crm_version' => $row->crm_version + 1, 'updated_at' => now()]);
            if (! DB::table('lead_sources')->where('candidate_id', $candidate->id)->exists()) {
                $submission = DB::table('lead_submissions')->where('lead_id', $lead)->where('is_test', false)->orderBy('id')->first();
                $attribution = $submission ? json_decode($submission->attribution, true) : [];
                DB::table('lead_sources')->insert(['candidate_id' => $candidate->id, 'source' => $attribution['utm_source'] ?? $row->crm_source ?? 'funnel', 'medium' => $attribution['utm_medium'] ?? null, 'campaign' => $attribution['utm_campaign'] ?? null, 'captured_at' => $submission?->created_at ?? $row->created_at]);
            }
            DB::table('crm_contacts')->where('id', $row->contact_id)->update(['candidate_id' => $candidate->id, 'updated_at' => now()]);
            if (! $candidate->source_lead_id) {
                $candidate->forceFill(['source_lead_id' => $lead])->save();
            }
            app(AttributionService::class)->record($lead, 'conversion-'.$lead.'-'.$application->id, 'LEAD_CONVERTED', $row->crm_source ?: 'UNKNOWN');
            app(ConversationCrm::class)->activity($lead, 'converted', 'Candidate #'.$candidate->id.' · Lamaran #'.$application->id, $request->user()->id);

            return $application;
        });
    }
}
