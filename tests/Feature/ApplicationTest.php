<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_one_candidate_can_have_multiple_independent_applications(): void
    {
        $candidate = Candidate::factory()->create();
        $first = Application::factory()->create(['candidate_id' => $candidate->id, 'application_code' => 'APP-PERTAMA']);
        $second = Application::factory()->create(['candidate_id' => $candidate->id, 'application_code' => 'APP-KEDUA']);
        $this->assertSame(2, $candidate->applications()->count());
        $this->get(route('applications.index'))->assertOk()->assertSee('APP-PERTAMA')->assertSee('APP-KEDUA')->assertSee($candidate->person->full_name);
        $user = User::factory()->create();
        $this->patch(route('applications.assign-cs', $first), ['reason' => 'Penugasan awal terverifikasi', 'assigned_cs_id' => $user->id, 'candidate_id' => 999])->assertSessionHasNoErrors()->assertRedirect(route('applications.show', $first));
        $this->patch(route('applications.update-status', $first), ['status' => 'closed', 'candidate_id' => 999])->assertSessionHasNoErrors()->assertRedirect(route('applications.show', $first));
        $this->assertSame($user->id, $first->fresh()->assigned_cs_id);
        $this->assertSame('closed', $first->fresh()->status);
        $this->assertNull($second->fresh()->assigned_cs_id);
        $this->assertSame('active', $second->fresh()->status);
        $this->assertSame('lead', $candidate->fresh()->status);
        $this->assertSame($candidate->id, $first->fresh()->candidate_id);
        $this->assertSame($first->id, $user->assignedApplications()->first()->id);
        $this->get(route('applications.show', $first))->assertOk()->assertSee($user->name)->assertSee('Ditutup');
        $this->patch(route('applications.assign-cs', $first), ['reason' => 'Alihkan ke antrean penugasan', 'assigned_cs_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($first->fresh()->assigned_cs_id);
    }

    public function test_search_and_all_filters_work_together(): void
    {
        $stage = PipelineStage::factory()->create(['name' => 'Tahap Khusus']);
        $user = User::factory()->create();
        $application = Application::factory()->create(['application_code' => 'APP-KHUSUS', 'current_stage_id' => $stage->id, 'assigned_cs_id' => $user->id]);
        $candidate = $application->candidate;
        $candidate->update(['candidate_code' => 'CPMI-KHUSUS']);
        $candidate->person->update(['full_name' => 'Nama Kandidat Khusus', 'nik' => '1234567890123456']);
        Application::factory()->count(16)->create();
        foreach (['APP-KHUSUS', 'CPMI-KHUSUS', 'Nama Kandidat Khusus', '1234567890123456'] as $search) {
            $this->get(route('applications.index', ['search' => $search]))->assertOk()->assertViewHas('applications', fn ($rows) => $rows->total() === 1);
        }
        $filters = ['current_stage_id' => $stage->id, 'assigned_cs_id' => $user->id, 'vacancy_id' => $application->vacancy_id, 'company_id' => $application->vacancy->jobOrder->company_id, 'country_id' => $application->vacancy->jobOrder->country_id, 'status' => 'active'];
        foreach ($filters as $key => $value) {
            $this->get(route('applications.index', [$key => $value]))->assertOk()->assertViewHas('applications', fn ($rows) => $rows->total() === ($key === 'status' ? 17 : 1));
        }
        $this->get(route('applications.index', $filters))->assertOk()->assertSee('Tahap Khusus')->assertViewHas('applications', fn ($rows) => $rows->total() === 1);
        $this->get(route('applications.index', [...$filters, 'status' => 'closed']))->assertOk()->assertSee('Tidak ada lamaran');
        $this->get(route('applications.index', ['status' => 'active', 'search' => 'APP-', 'per_page' => 10]))->assertOk()->assertViewHas('applications', fn ($rows) => $rows->count() === 10 && str_contains($rows->nextPageUrl(), 'status=active') && str_contains($rows->nextPageUrl(), 'search=APP-'));
    }

    public function test_historical_profiles_remain_visible_and_deleted_applications_are_excluded(): void
    {
        $application = Application::factory()->create(['application_code' => 'APP-RIWAYAT']);
        $name = $application->candidate->person->full_name;
        $title = $application->vacancy->title;
        $company = $application->vacancy->jobOrder->company;
        $application->candidate->person->delete();
        $application->candidate->delete();
        $application->vacancy->delete();
        $company->delete();
        $this->get(route('applications.show', $application))->assertOk()->assertSee($name)->assertSee($title)->assertSee($company->name);
        $this->get(route('applications.index', ['search' => $name]))->assertOk()->assertSee('APP-RIWAYAT');
        $this->get(route('applications.index', ['company_id' => $company->id]))->assertOk()->assertSee('APP-RIWAYAT');
        $application->delete();
        $this->get(route('applications.index'))->assertOk()->assertDontSee('APP-RIWAYAT');
        $this->get(route('applications.show', $application))->assertNotFound();
        $this->patch(route('applications.update-status', $application), ['status' => 'closed'])->assertNotFound();
    }

    public function test_validation_and_optional_relations(): void
    {
        $application = Application::factory()->create();
        $this->get(route('applications.show', $application))->assertOk()->assertSee('Belum ada tahap')->assertSee('Belum ditugaskan');
        $this->patch(route('applications.assign-cs', $application), ['assigned_cs_id' => 99999])->assertSessionHasErrors('assigned_cs_id');
        $this->patch(route('applications.assign-cs', $application), [])->assertSessionHasErrors('assigned_cs_id');
        $this->patch(route('applications.update-status', $application), ['status' => 'invalid'])->assertSessionHasErrors('status');
        $this->assertSame('active', $application->fresh()->status);
        $this->assertNull($application->fresh()->assigned_cs_id);
        $this->get(route('applications.index', ['current_stage_id' => 99999]))->assertSessionHasErrors('current_stage_id');
        $this->get(route('applications.index', ['search' => ['invalid']]))->assertSessionHasErrors('search');
        $this->get(route('applications.index', ['search' => 'tidak ditemukan']))->assertOk()->assertSee('Tidak ada lamaran');
    }
}
