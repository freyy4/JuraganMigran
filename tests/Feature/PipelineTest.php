<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationStageHistory;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_board_sequence_cards_filters_and_pagination(): void
    {
        $last = PipelineStage::factory()->create(['name' => 'Tahap Akhir', 'sequence' => 20]);
        $first = PipelineStage::factory()->create(['name' => 'Tahap Awal', 'sequence' => 1]);
        $user = User::factory()->create();
        $application = Application::factory()->create(['application_code' => 'APP-KANBAN', 'current_stage_id' => $first->id, 'assigned_cs_id' => $user->id]);
        ApplicationStageHistory::factory()->create(['application_id' => $application->id, 'to_stage_id' => $first->id, 'entered_at' => now()->subDays(3)]);
        Application::factory()->count(15)->create(['current_stage_id' => $first->id]);
        $response = $this->get(route('pipeline.index', ['mode' => 'board']));
        $response->assertOk()->assertSeeInOrder(['Tahap Awal', 'Tahap Akhir'])->assertSee('Belum ada tahap');
        $response->assertViewHas('columns', fn ($columns) => $columns[0]['applications']->total() === 16 && $columns[0]['applications']->count() === 10);
        $filters = ['company_id' => $application->vacancy->jobOrder->company_id, 'country_id' => $application->vacancy->jobOrder->country_id, 'vacancy_id' => $application->vacancy_id, 'assigned_cs_id' => $user->id];
        foreach ($filters as $key => $value) {
            $this->get(route('pipeline.index', [$key => $value, 'mode' => 'board']))->assertOk()->assertSee('APP-KANBAN')->assertSee('3 hari')->assertViewHas('columns', fn ($columns) => $columns[0]['applications']->total() === 1);
        }
        $this->get(route('pipeline.index', $filters))->assertOk()->assertSee($user->name)->assertSee($application->candidate->person->full_name);
        $this->get(route('pipeline.index', ['stage_'.$first->id.'_page' => 2, 'mode' => 'board']))->assertOk()->assertViewHas('columns', fn ($columns) => $columns[0]['applications']->count() === 6);
        $this->get(route('pipeline.index', ['company_id' => 999999]))->assertSessionHasErrors('company_id');
    }

    public function test_confirmed_move_records_actor_time_and_closes_previous_visit(): void
    {
        $first = PipelineStage::factory()->create();
        $next = PipelineStage::factory()->create();
        $application = Application::factory()->create(['current_stage_id' => $first->id]);
        $other = Application::factory()->create(['candidate_id' => $application->candidate_id, 'current_stage_id' => $first->id]);
        $visit = ApplicationStageHistory::factory()->create(['application_id' => $application->id, 'to_stage_id' => $first->id, 'entered_at' => now()->subHours(5)]);
        $this->get(route('pipeline.confirm', [$application, 'to_stage_id' => $next->id]))->assertOk()->assertSee('Konfirmasi Pindah Tahap');
        $this->assertSame($first->id, $application->fresh()->current_stage_id);
        $this->travelTo(now()->startOfSecond());
        $data = ['to_stage_id' => $next->id, 'from_stage_id' => $first->id, 'confirmed' => 1, 'reason' => 'Dokumen lengkap'];
        $this->patch(route('pipeline.move', $application), $data)->assertSessionHasNoErrors()->assertRedirect(route('pipeline.index'));
        $this->assertSame($next->id, $application->fresh()->current_stage_id);
        $history = $application->stageHistories()->latest('id')->firstOrFail();
        $this->assertSame(auth()->id(), $history->changed_by);
        $this->assertSame($first->id, $history->from_stage_id);
        $this->assertSame($next->id, $history->to_stage_id);
        $this->assertTrue($history->entered_at->equalTo(now()));
        $this->assertTrue($visit->fresh()->exited_at->equalTo(now()));
        $this->assertSame('Dokumen lengkap', $history->reason);
        $this->assertSame($first->id, $other->fresh()->current_stage_id);
        $this->assertSame('lead', $application->candidate->status);
        $this->assertSame('0 menit', $application->fresh()->stageDuration());
        $this->patch(route('pipeline.move', $application), $data)->assertSessionHasErrors('from_stage_id');
        $this->assertSame(2, $application->stageHistories()->count());
        $this->patch(route('pipeline.move', $application), ['to_stage_id' => $first->id, 'from_stage_id' => $next->id, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('0 menit', $application->fresh()->stageDuration());
    }

    public function test_move_validation_and_unassigned_initial_move(): void
    {
        $application = Application::factory()->create();
        $active = PipelineStage::factory()->create();
        $inactive = PipelineStage::factory()->create(['is_active' => false]);
        $this->get(route('pipeline.index'))->assertOk()->assertSee($application->application_code)->assertSee('Belum ada tahap');
        $this->patch(route('pipeline.move', $application), ['from_stage_id' => null, 'to_stage_id' => $active->id])->assertSessionHasErrors('confirmed');
        $this->patch(route('pipeline.move', $application), ['from_stage_id' => null, 'to_stage_id' => $inactive->id, 'confirmed' => 1])->assertSessionHasErrors('to_stage_id');
        $this->get(route('pipeline.confirm', [$application, 'to_stage_id' => $inactive->id]))->assertSessionHasErrors('to_stage_id');
        $this->assertNull($application->fresh()->current_stage_id);
        $this->assertDatabaseCount('application_stage_histories', 0);
        $this->patch(route('pipeline.move', $application), ['from_stage_id' => null, 'to_stage_id' => $active->id, 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('application_stage_histories', ['application_id' => $application->id, 'from_stage_id' => null, 'to_stage_id' => $active->id]);
        $this->patch(route('pipeline.move', $application), ['from_stage_id' => $active->id, 'to_stage_id' => $active->id, 'confirmed' => 1])->assertSessionHasErrors('to_stage_id');
        $application->delete();
        $this->patch(route('pipeline.move', $application), ['from_stage_id' => $active->id, 'to_stage_id' => $active->id, 'confirmed' => 1])->assertNotFound();
    }

    public function test_auth_is_required_and_unknown_duration_is_not_invented(): void
    {
        $stage = PipelineStage::factory()->create();
        $application = Application::factory()->create(['current_stage_id' => $stage->id]);
        $this->assertSame('Belum tercatat', $application->stageDuration());
        auth()->logout();
        $this->get(route('pipeline.index'))->assertRedirect(route('login'));
        $this->patch(route('pipeline.move', $application), ['from_stage_id' => $stage->id, 'to_stage_id' => $stage->id, 'confirmed' => 1])->assertRedirect(route('login'));
        $this->assertDatabaseCount('application_stage_histories', 0);
    }
}
