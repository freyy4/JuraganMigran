<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\JobOrder;
use App\Models\RequirementDefinition;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VacancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function payload(): array
    {
        return ['code' => 'VAC-001', 'job_order_id' => JobOrder::factory()->create()->id, 'title' => 'Lowongan Khusus', 'position_name' => 'Posisi Khusus', 'quota' => 10, 'salary' => '1200.50', 'currency' => 'USD', 'status' => 'draft'];
    }

    private function requirement(RequirementDefinition $definition, array $values = []): array
    {
        return ['definition_id' => $definition->id, 'selected' => 1, 'operator' => 'eq', 'value' => 'Syarat teks', 'is_required' => 1, ...$values];
    }

    public function test_crud_requirements_versions_and_soft_delete(): void
    {
        $definition = RequirementDefinition::factory()->create(['name' => 'Ketentuan Khusus']);
        $data = $this->payload();
        $data['requirements'] = [$definition->id => $this->requirement($definition)];
        $this->get(route('vacancies.create'))->assertOk()->assertSee('Ketentuan Khusus');
        $this->post(route('vacancies.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $vacancy = Vacancy::firstOrFail();
        $slug = $vacancy->slug;
        $this->assertNotEmpty($slug);
        $this->get(route('vacancies.show', $vacancy))->assertOk()->assertSee('Syarat teks');
        $this->get(route('vacancies.edit', $vacancy))->assertOk()->assertSee('Ketentuan Khusus');
        $this->post(route('vacancies.store'), $data)->assertSessionHasErrors('code');
        $data['requirements'][$definition->id]['value'] = 'Syarat terbaru';
        $data['title'] = 'Judul Baru';
        $data['status'] = 'published';
        $this->put(route('vacancies.update', $vacancy), $data)->assertSessionHasNoErrors();
        $this->assertSame($slug, $vacancy->fresh()->slug);
        $this->assertNotNull($vacancy->fresh()->published_at);
        $this->assertDatabaseHas('vacancy_requirements', ['vacancy_id' => $vacancy->id, 'version' => 1, 'value' => 'Syarat teks']);
        $this->assertDatabaseHas('vacancy_requirements', ['vacancy_id' => $vacancy->id, 'version' => 2, 'value' => 'Syarat terbaru']);
        $this->get(route('vacancies.show', $vacancy))->assertOk()->assertSee('Syarat terbaru')->assertDontSee('Syarat teks');
        $this->put(route('vacancies.update', $vacancy), $data)->assertSessionHasNoErrors();
        $this->assertSame(2, $vacancy->requirements()->count());
        $this->delete(route('vacancies.destroy', $vacancy))->assertRedirect(route('vacancies.index'));
        $this->assertSoftDeleted('vacancies', ['id' => $vacancy->id]);
        $this->assertDatabaseCount('vacancy_requirements', 2);
        $this->get(route('vacancies.show', $vacancy))->assertNotFound();
    }

    public function test_dynamic_form_and_typed_validation(): void
    {
        $numeric = RequirementDefinition::factory()->create(['name' => 'Ukuran Dinamis', 'data_type' => 'integer', 'unit' => 'cm']);
        $boolean = RequirementDefinition::factory()->create(['name' => 'Pilihan Dinamis', 'data_type' => 'boolean']);
        $date = RequirementDefinition::factory()->create(['name' => 'Tanggal Dinamis', 'data_type' => 'date']);
        $inactive = RequirementDefinition::factory()->create(['name' => 'Definisi Tidak Aktif', 'is_active' => false]);
        $this->get(route('vacancies.create'))->assertOk()->assertSee('Ukuran Dinamis')->assertSee('Pilihan Dinamis')->assertSee('Tanggal Dinamis')->assertDontSee('Definisi Tidak Aktif');
        $data = $this->payload();
        $data['requirements'] = [
            $numeric->id => $this->requirement($numeric, ['operator' => 'between', 'value_min' => 20, 'value_max' => 10]),
            $boolean->id => $this->requirement($boolean, ['value' => 'invalid']),
            $date->id => $this->requirement($date, ['value' => 'invalid']),
        ];
        $this->post(route('vacancies.store'), $data)->assertSessionHasErrors(['requirements.'.$numeric->id.'.value_max', 'requirements.'.$boolean->id.'.value', 'requirements.'.$date->id.'.value']);
        $this->assertDatabaseCount('vacancies', 0);
        $data['requirements'][$numeric->id]['value_max'] = 30;
        $data['requirements'][$boolean->id]['value'] = false;
        $data['requirements'][$date->id]['value'] = '2026-10-03';
        $this->post(route('vacancies.store'), $data)->assertSessionHasNoErrors();
        $vacancy = Vacancy::firstOrFail();
        $this->assertDatabaseHas('vacancy_requirements', ['requirement_definition_id' => $boolean->id, 'value' => '0']);
        $this->assertDatabaseHas('vacancy_requirements', ['requirement_definition_id' => $numeric->id, 'value_min' => 20, 'value_max' => 30, 'value' => null]);
        $this->get(route('vacancies.edit', $vacancy))->assertOk()->assertSee('Ukuran Dinamis');
        $data['requirements'][$numeric->id]['value_min'] = 20.5;
        $this->put(route('vacancies.update', $vacancy), $data)->assertSessionHasErrors('requirements.'.$numeric->id.'.value_min');
        $data['requirements'] = [$inactive->id => $this->requirement($inactive)];
        $this->put(route('vacancies.update', $vacancy), $data)->assertSessionHasErrors('requirements.'.$inactive->id.'.definition_id');
    }

    public function test_removing_unused_requirements_and_preserving_qualification_results(): void
    {
        $definition = RequirementDefinition::factory()->create();
        $data = $this->payload();
        $data['requirements'] = [$definition->id => $this->requirement($definition)];
        $this->post(route('vacancies.store'), $data)->assertSessionHasNoErrors();
        $vacancy = Vacancy::firstOrFail();
        $application = Application::factory()->create(['vacancy_id' => $vacancy->id]);
        $requirement = $vacancy->requirements()->firstOrFail();
        DB::table('application_qualification_results')->insert(['application_id' => $application->id, 'vacancy_requirement_id' => $requirement->id, 'result' => 'passed']);
        $data['requirements'][$definition->id]['value'] = 'Nilai baru';
        $this->put(route('vacancies.update', $vacancy), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vacancy_requirements', ['id' => $requirement->id, 'value' => 'Syarat teks']);
        $this->assertDatabaseHas('application_qualification_results', ['vacancy_requirement_id' => $requirement->id]);
        unset($data['requirements']);
        $data['title'] = 'Harus dibatalkan';
        $this->put(route('vacancies.update', $vacancy), $data)->assertSessionHasErrors('requirements');
        $this->assertDatabaseHas('vacancies', ['id' => $vacancy->id, 'title' => 'Lowongan Khusus']);
        $unused = Vacancy::factory()->create();
        $unused->requirements()->create(['requirement_definition_id' => $definition->id, 'value' => 'Tanpa hasil', 'operator' => 'eq']);
        $this->put(route('vacancies.update', $unused), [...$data, 'code' => $unused->code])->assertSessionHasNoErrors();
        $this->assertSame(0, $unused->requirements()->count());
    }

    public function test_search_pagination_and_unique_applicant_count(): void
    {
        $vacancy = Vacancy::factory()->create(['code' => 'VAC-KHUSUS', 'title' => 'Lowongan Khusus', 'position_name' => 'Posisi Khusus', 'status' => 'published']);
        $vacancy->jobOrder->company->update(['name' => 'Mitra Khusus']);
        Vacancy::factory()->count(16)->create();
        foreach (['VAC-KHUSUS', 'Lowongan Khusus', 'Posisi Khusus', 'Mitra Khusus'] as $search) {
            $this->get(route('vacancies.index', ['search' => $search]))->assertOk()->assertViewHas('vacancies', fn ($rows) => $rows->total() === 1);
        }
        $this->get(route('vacancies.index', ['status' => 'draft']))->assertOk()->assertViewHas('vacancies', fn ($rows) => $rows->count() === 15 && str_contains($rows->nextPageUrl(), 'status=draft'));
        $this->get(route('vacancies.index', ['search' => 'Tidak tersedia']))->assertOk()->assertSee('Tidak ada lowongan');
        $application = Application::factory()->create(['vacancy_id' => $vacancy->id]);
        Application::factory()->create(['vacancy_id' => $vacancy->id, 'candidate_id' => $application->candidate_id]);
        Application::factory()->create(['vacancy_id' => $vacancy->id])->delete();
        Application::factory()->create();
        $this->get(route('vacancies.show', $vacancy))->assertOk()->assertViewHas('vacancy', fn ($row) => $row->applications_count === 1);
    }

    public function test_base_validation_and_empty_definitions(): void
    {
        $this->get(route('vacancies.create'))->assertOk()->assertSee('Belum ada definisi persyaratan aktif.');
        $data = $this->payload();
        $data['job_order_id'] = 99999;
        $data['quota'] = -1;
        $data['salary'] = '100.123';
        $data['currency'] = null;
        $this->post(route('vacancies.store'), $data)->assertSessionHasErrors(['job_order_id', 'quota', 'salary', 'currency']);
        $this->assertDatabaseCount('vacancies', 0);
        $data = $this->payload();
        $data['salary'] = '10000000000000000';
        $this->post(route('vacancies.store'), $data)->assertSessionHasErrors('salary');
        $data['salary'] = '0';
        $this->post(route('vacancies.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('vacancies', 1);
    }
}
