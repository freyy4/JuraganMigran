<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\RequirementDefinition;
use App\Models\User;
use App\Models\VacancyRequirement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->startOfSecond());
    }

    private function requirement(Application $application, string $type = 'string'): VacancyRequirement
    {
        $definition = RequirementDefinition::factory()->create(['name' => 'Ketentuan Dinamis', 'data_type' => $type]);

        return $application->vacancy->requirements()->create(['requirement_definition_id' => $definition->id, 'operator' => 'eq', 'value' => $type === 'integer' ? '15' : 'Nilai dari database']);
    }

    public function test_save_progress_metadata_and_results_are_independent_for_each_application(): void
    {
        $application = Application::factory()->create();
        $first = $this->requirement($application);
        $second = $this->requirement($application, 'integer');
        $other = Application::factory()->create(['vacancy_id' => $application->vacancy_id, 'candidate_id' => $application->candidate_id]);
        $this->get(route('qualifications.show', $application))->assertOk()->assertSee('Nilai dari database')->assertViewHas('progress', 0);
        $data = ['results' => [['vacancy_requirement_id' => $first->id, 'value' => 'Valeur candidat', 'result' => 'review', 'reason' => 'Besoin de vérifier'], ['vacancy_requirement_id' => $second->id, 'value' => '16', 'result' => 'not_checked']]];
        $this->put(route('qualifications.update', $application), $data)->assertSessionHasNoErrors()->assertRedirect(route('qualifications.show', $application));
        $this->get(route('qualifications.show', $application))->assertOk()->assertViewHas('progress', 50);
        $result = $application->qualificationResults()->where('vacancy_requirement_id', $first->id)->firstOrFail();
        $this->assertSame(auth()->id(), $result->checked_by);
        $this->assertTrue($result->checked_at->equalTo(now()));
        $this->assertNull($application->qualificationResults()->where('vacancy_requirement_id', $second->id)->first()->checked_at);
        $this->get(route('qualifications.show', $other))->assertOk()->assertViewHas('progress', 0);
        $this->travel(1)->hours();
        $this->actingAs(auth()->user());
        $this->put(route('qualifications.update', $application), $data)->assertSessionHasNoErrors();
        $this->assertTrue($result->fresh()->checked_at->equalTo($result->checked_at));
        $this->assertDatabaseCount('application_qualification_results', 2);
        $data['results'][1]['result'] = 'rejected';
        $this->put(route('qualifications.update', $application), $data)->assertSessionHasNoErrors();
        $this->get(route('qualifications.show', $application))->assertOk()->assertViewHas('progress', 100);
        $data['results'][0]['result'] = 'not_checked';
        $this->put(route('qualifications.update', $application), $data)->assertSessionHasNoErrors();
        $this->assertNull($result->fresh()->checked_at);
        $this->assertNull($result->fresh()->checked_by);
        $this->assertSame('active', $application->fresh()->status);
    }

    public function test_requirements_versions_foreign_ids_and_typed_validation(): void
    {
        $application = Application::factory()->create();
        $old = $this->requirement($application, 'integer');
        $application->qualificationResults()->create(['vacancy_requirement_id' => $old->id, 'value' => '20', 'result' => 'passed']);
        $latest = $application->vacancy->requirements()->create(['requirement_definition_id' => $old->requirement_definition_id, 'version' => 2, 'operator' => 'gte', 'value' => '25']);
        $foreignApplication = Application::factory()->create();
        $foreign = $this->requirement($foreignApplication);
        $this->get(route('qualifications.show', $application))->assertOk()->assertSee('25')->assertViewHas('progress', 0)->assertViewHas('requirements', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $latest->id);
        foreach ([$old->id, $foreign->id] as $id) {
            $this->put(route('qualifications.update', $application), ['results' => [['vacancy_requirement_id' => $id, 'result' => 'passed', 'value' => '30']]])->assertSessionHasErrors('results.0.vacancy_requirement_id');
        }
        $this->put(route('qualifications.update', $application), ['results' => [['vacancy_requirement_id' => $latest->id, 'result' => 'passed', 'value' => 'texte']]])->assertSessionHasErrors('results.0.value');
        $this->put(route('qualifications.update', $application), ['results' => [['vacancy_requirement_id' => $latest->id, 'result' => 'invalid', 'value' => '30']]])->assertSessionHasErrors('results.0.result');
        $this->put(route('qualifications.update', $application), ['results' => [['vacancy_requirement_id' => $latest->id, 'result' => 'passed', 'value' => '30']]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('application_qualification_results', 2);
        $this->assertDatabaseHas('application_qualification_results', ['vacancy_requirement_id' => $old->id, 'value' => '20']);
        $this->get(route('qualifications.show', $application))->assertOk()->assertViewHas('progress', 100);
    }

    public function test_search_pagination_empty_requirements_and_login_requirement(): void
    {
        $application = Application::factory()->create(['application_code' => 'APP-KUALIFIKASI']);
        Application::factory()->count(15)->create();
        $this->get(route('qualifications.index', ['search' => 'APP-KUALIFIKASI']))->assertOk()->assertViewHas('applications', fn ($rows) => $rows->total() === 1);
        $this->get(route('qualifications.index'))->assertOk()->assertViewHas('applications', fn ($rows) => $rows->count() === 15);
        $this->get(route('qualifications.show', $application))->assertOk()->assertSee('Belum ada persyaratan')->assertViewHas('progress', 0);
        auth()->logout();
        $this->get(route('qualifications.index'))->assertRedirect(route('login'));
        $this->put(route('qualifications.update', $application), ['results' => []])->assertRedirect(route('login'));
        $this->assertDatabaseCount('application_qualification_results', 0);
    }
}
