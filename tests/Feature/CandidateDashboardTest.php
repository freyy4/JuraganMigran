<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\DocumentType;
use App\Models\Interview;
use App\Models\MedicalEvent;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\CandidateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CandidateDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function lead(Candidate $candidate, User $user): int
    {
        return DB::table('acquisition_leads')->insertGetId(['public_token' => (string) Str::uuid(), 'candidate_id' => $candidate->id, 'name' => 'Atqiya Nur Azzahra', 'normalized_phone' => '+6281234567890', 'assigned_cs_id' => $user->id, 'status' => 'converted', 'followup_status' => 'NOT_CONTACTED', 'crm_source' => 'Website', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_dashboard_uses_real_readiness_documents_and_scoped_followups(): void
    {
        Storage::fake('documents');
        $user = User::factory()->create(['name' => 'Budi Santoso']);
        $this->actingAs($user);
        $stage = PipelineStage::factory()->create(['code' => 'DOCUMENTS', 'name' => 'Proses Dokumen']);
        $application = Application::factory()->create(['assigned_cs_id' => $user->id, 'current_stage_id' => $stage->id]);
        $candidate = $application->candidate;
        $candidate->update(['preferences' => ['work_sector' => 'Asisten Rumah Tangga', 'departure_readiness' => 'READY', 'salary_target' => 'HKD 4,870', 'contract_duration' => '2 Tahun', 'departure_estimate' => 'Apr – Mei 2027']]);
        $candidate->person->update(['full_name' => 'Atqiya Nur Azzahra', 'nik' => '3275016508050003', 'whatsapp' => '081234567890', 'email' => 'atqiya@example.test', 'birth_place' => 'Bandung', 'birth_date' => '2005-08-15', 'gender' => 'female', 'address' => 'Kp. Sukamaju No.12, Cimahi, Jawa Barat', 'education_level' => 'SMA/SMK', 'marital_status' => 'single']);
        $candidate->contacts()->create(['name' => 'Ahmad Hidayat', 'relationship' => 'Ayah Kandung', 'whatsapp' => '081298765432', 'is_primary' => true, 'is_emergency' => true, 'contact_type' => 'family']);
        MedicalEvent::factory()->create(['application_id' => $application->id, 'status' => 'completed', 'clearance_status' => 'fit', 'completed_at' => now(), 'expires_at' => now()->addMonths(6)]);
        Interview::factory()->create(['application_id' => $application->id, 'scheduled_at' => now()->addWeek()]);
        foreach (['KTP', 'KK', 'Paspor', 'SKCK', 'Surat Sehat', 'Sertifikat Pelatihan', 'Kontrak Kerja', 'Visa Kerja'] as $index => $name) {
            $type = DocumentType::factory()->create(['name' => $name]);
            $application->vacancy->documentRequirements()->create(['document_type_id' => $type->id, 'is_required' => true]);
            if ($index < 6) {
                Storage::disk('documents')->put('test-'.$index.'.pdf', 'Synthetic QA document');
                CandidateDocument::create(['candidate_id' => $candidate->id, 'application_id' => $application->id, 'document_type_id' => $type->id, 'file_path' => 'test-'.$index.'.pdf', 'status' => 'verified', 'version' => 1, 'expires_at' => now()->addYear()]);
            }
        }
        $lead = $this->lead($candidate, $user);
        foreach (['Upload SKCK', 'Follow up hasil medical', 'Persiapan wawancara user', 'Cek kelengkapan paspor'] as $index => $note) {
            DB::table('crm_follow_ups')->insert(['lead_id' => $lead, 'assigned_user_id' => $user->id, 'status' => 'SCHEDULED', 'type' => 'CHAT', 'note' => $note, 'due_at' => now()->addDays($index + 1), 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['Dokumen SKCK diterima', 'Hasil medical checkup', 'Screening selesai', 'Biodata diperbarui', 'CPMI mendaftar dari website'] as $index => $summary) {
            DB::table('crm_activities')->insert(['lead_id' => $lead, 'event_type' => 'stage_changed', 'summary' => $summary, 'created_at' => now()->subDays($index)]);
        }
        $response = $this->get(route('candidates.show', $candidate))->assertOk()->assertSee('Upload SKCK')->assertSee('6 dari 8 dokumen')->assertSee('https://wa.me/6281298765432', false)->assertSee('Jadwal Ulang')->assertViewHas('readiness', fn ($value) => $value['score'] === 70 && $value['factors']['medical']['ready'])->assertViewHas('nextActions', fn ($rows) => $rows->count() === 4 && $rows->first()->canManage);
        MedicalEvent::query()->update(['expires_at' => now()->subDay()]);
        $this->get(route('candidates.show', $candidate))->assertOk()->assertViewHas('readiness', fn ($value) => $value['score'] === 45 && ! $value['factors']['medical']['ready']);
        if ($directory = getenv('CPMI_QA_FIXTURES')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/detail.html', $response->getContent());
            file_put_contents($directory.'/registry.html', $this->get(route('candidates.index'))->getContent());
            if ($careDirectory = getenv('CARE_QA_FIXTURES')) {
                if (! is_dir($careDirectory)) {
                    mkdir($careDirectory, 0777, true);
                }
                file_put_contents($careDirectory.'/care.html', $this->get(route('care.show', $candidate))->assertOk()->getContent());
            }

        }
    }

    public function test_reschedule_is_atomic_preserves_the_task_and_rejects_stale_schedule(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $lead = $this->lead(Candidate::factory()->create(), $user);
        $due = now()->addDay()->startOfMinute()->toDateTimeString();
        $id = DB::table('crm_follow_ups')->insertGetId(['lead_id' => $lead, 'assigned_user_id' => $user->id, 'due_at' => $due, 'type' => 'CHAT', 'status' => 'SCHEDULED', 'created_at' => now(), 'updated_at' => now()]);
        $url = route('crm.finish-followup', [$lead, $id]);
        $data = ['status' => 'RESCHEDULED', 'outcome' => 'CPMI meminta jadwal baru', 'due_at' => '2027-01-12T09:00', 'expected_due_at' => $due];
        $this->post($url, $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('crm_follow_ups', ['id' => $id, 'status' => 'SCHEDULED', 'due_at' => '2027-01-12 02:00:00', 'completed_at' => null]);
        $this->assertDatabaseCount('crm_follow_ups', 1);
        $this->post($url, $data)->assertSessionHasErrors('due_at');
        $this->post($url, ['status' => 'COMPLETED', 'outcome' => 'Kontak berhasil'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('crm_follow_ups', ['id' => $id, 'status' => 'COMPLETED']);
        $this->post($url, $data)->assertNotFound();
    }

    public function test_readiness_weights_are_configurable_and_empty_candidate_has_zero_score(): void
    {
        $this->actingAs(User::factory()->create());
        $candidate = Candidate::factory()->create();
        $this->get(route('candidates.show', $candidate))->assertOk()->assertViewHas('readiness', fn ($value) => $value['score'] === 0)->assertSee('Belum ada kontak keluarga / darurat.');
        config(['candidate-dashboard.readiness_weights' => ['documents' => 0, 'medical' => 0, 'interview' => 0, 'preferences' => 0, 'departure' => 100]]);
        $candidate->update(['preferences' => ['departure_readiness' => 'READY']]);
        $this->get(route('candidates.show', $candidate))->assertOk()->assertViewHas('readiness', fn ($value) => $value['score'] === 100);
        $this->assertSame('https://wa.me/6281234567890', CandidateWorkspace::whatsapp('+62 812-3456-7890'));
        $this->assertNull(CandidateWorkspace::whatsapp('123'));
    }
}
