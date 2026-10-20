<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Company;
use App\Models\TrainingBatch;
use App\Models\TrainingEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function batchPayload(array $extra = []): array
    {
        return array_replace(['company_id' => Company::factory()->create(['type' => 'lpk'])->id, 'code' => 'LPK-2026-A', 'name' => 'Angkatan Bahasa', 'start_date' => '2026-10-03', 'end_date' => '2026-11-03', 'capacity' => 20, 'status' => 'planned'], $extra);
    }

    private function enrollmentPayload(TrainingBatch $batch, array $extra = []): array
    {
        return array_replace(['application_id' => Application::factory()->create()->id, 'batch_id' => $batch->id, 'status' => 'ongoing', 'joined_at' => '2026-10-03', 'completed_at' => null], $extra);
    }

    public function test_manage_batches_and_enrollments_with_dates_and_relationships(): void
    {
        $payload = $this->batchPayload();
        $this->get(route('training-batches.create'))->assertOk();
        $this->post(route('training-batches.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $batch = TrainingBatch::firstOrFail();
        $this->assertEquals($batch->id, $batch->company->trainingBatches()->first()->id);
        $this->get(route('training-batches.edit', $batch))->assertOk()->assertSee('Angkatan Bahasa');
        $this->put(route('training-batches.update', $batch), array_replace($payload, ['name' => 'Angkatan Terbaru']))->assertSessionHasNoErrors();
        $this->get(route('training.create', ['batch_id' => $batch->id]))->assertOk()->assertSee('Angkatan Terbaru');
        $data = $this->enrollmentPayload($batch);
        $this->post(route('training.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('training.index'));
        $enrollment = TrainingEnrollment::firstOrFail();
        $application = $enrollment->application;
        $this->assertDatabaseHas('training_enrollments', ['id' => $enrollment->id, 'joined_at' => '2026-10-02 17:00:00', 'status' => 'ongoing']);
        $this->assertEquals($enrollment->id, $application->trainingEnrollments()->first()->id);
        $this->get(route('training-batches.show', $batch))->assertOk()->assertSee($application->candidate->person->full_name)->assertSee('03/10/2026')->assertSee('Berlangsung');
        $this->get(route('training.edit', $enrollment))->assertOk()->assertSee('2026-10-03');
        $this->put(route('training.update', $enrollment), array_replace($data, ['status' => 'completed', 'completed_at' => '2026-11-03']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('training_enrollments', ['id' => $enrollment->id, 'completed_at' => '2026-11-02 17:00:00', 'status' => 'completed']);
        $this->assertEquals('active', $application->fresh()->status);
        $this->get(route('training.index'))->assertOk()->assertSee($application->candidate->person->full_name)->assertSee('03/11/2026');
    }

    public function test_lpk_validation_capacity_and_duplicate_candidates(): void
    {
        $payload = $this->batchPayload(['company_id' => Company::factory()->create(['type' => 'sponsor'])->id]);
        $this->post(route('training-batches.store'), $payload)->assertSessionHasErrors('company_id');
        $this->post(route('training-batches.store'), $this->batchPayload(['end_date' => '2026-01-01']))->assertSessionHasErrors('end_date');
        $batch = TrainingBatch::factory()->create(['capacity' => 1]);
        $data = $this->enrollmentPayload($batch);
        $this->post(route('training.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('training.store'), $data)->assertSessionHasErrors('application_id');
        $application = Application::findOrFail($data['application_id']);
        $second = Application::factory()->create(['candidate_id' => $application->candidate_id]);
        $this->post(route('training.store'), array_replace($data, ['application_id' => $second->id]))->assertSessionHasErrors('application_id');
        $this->post(route('training.store'), $this->enrollmentPayload($batch))->assertSessionHasErrors('batch_id');
        $enrollment = TrainingEnrollment::firstOrFail();
        $this->put(route('training.update', $enrollment), array_replace($data, ['status' => 'withdrawn']))->assertSessionHasNoErrors();
        $this->post(route('training.store'), $this->enrollmentPayload($batch))->assertSessionHasNoErrors();
        $this->put(route('training.update', $enrollment), $data)->assertSessionHasErrors('batch_id');
        $this->assertDatabaseCount('training_enrollments', 2);
        $batch->update(['status' => 'completed']);
        $this->post(route('training.store'), $this->enrollmentPayload($batch))->assertSessionHasErrors('batch_id');
        $this->post(route('training-batches.store'), $this->batchPayload(['capacity' => 0]))->assertSessionHasErrors('capacity');
        $batch->update(['capacity' => null]);
        $this->post(route('training-batches.store'), $this->batchPayload(['code' => $batch->code]))->assertSessionHasErrors('code');
    }

    public function test_completion_validation_and_preserve_deleted_profiles(): void
    {
        $batch = TrainingBatch::factory()->create();
        $data = $this->enrollmentPayload($batch);
        $this->post(route('training.store'), array_replace($data, ['status' => 'completed', 'completed_at' => null]))->assertSessionHasErrors('completed_at');
        $this->post(route('training.store'), array_replace($data, ['status' => 'completed', 'completed_at' => '2026-10-01']))->assertSessionHasErrors('completed_at');
        $this->post(route('training.store'), array_replace($data, ['joined_at' => null]))->assertSessionHasErrors('joined_at');
        $this->post(route('training.store'), array_replace($data, ['completed_at' => '2026-10-04']))->assertSessionHasErrors('completed_at');
        $this->post(route('training.store'), $data)->assertSessionHasNoErrors();
        $enrollment = TrainingEnrollment::firstOrFail();
        $application = $enrollment->application;
        $name = $application->candidate->person->full_name;
        $application->candidate->person->delete();
        $application->candidate->delete();
        $application->delete();
        $batch->company->delete();
        $this->get(route('training.index'))->assertOk()->assertSee($name);
        $this->get(route('training-batches.show', $batch))->assertOk()->assertSee($name);
        $this->get(route('training.edit', $enrollment))->assertOk()->assertSee($name);
        $this->put(route('training.update', $enrollment), array_replace($data, ['status' => 'completed', 'completed_at' => '2026-11-03']))->assertSessionHasNoErrors();
        $this->post(route('training.store'), $data)->assertSessionHasErrors('application_id');
        auth()->logout();
        $this->get(route('training.index'))->assertRedirect(route('login'));
        $this->get(route('training-batches.index'))->assertRedirect(route('login'));
    }

    public function test_search_filters_pagination_and_capacity_reduction(): void
    {
        $batch = TrainingBatch::factory()->create(['capacity' => null]);
        $enrollment = TrainingEnrollment::factory()->create(['batch_id' => $batch->id]);
        $application = $enrollment->application;
        $this->get(route('training.index', ['search' => $application->candidate->person->full_name, 'company_id' => $batch->company_id, 'batch_id' => $batch->id, 'status' => 'scheduled']))->assertViewHas('enrollments', fn ($rows) => $rows->total() === 1);
        $this->get(route('training.index', ['search' => 'NoMatchingRecord']))->assertViewHas('enrollments', fn ($rows) => $rows->total() === 0);
        $this->get(route('training-batches.index', ['search' => $batch->code, 'company_id' => $batch->company_id, 'status' => 'planned']))->assertViewHas('batches', fn ($rows) => $rows->total() === 1);
        $this->get(route('training.index', ['status' => 'invalid']))->assertSessionHasErrors('status');
        $this->get(route('training-batches.index', ['company_id' => Company::factory()->create(['type' => 'agency'])->id]))->assertSessionHasErrors('company_id');
        TrainingEnrollment::factory()->count(15)->create(['batch_id' => $batch->id]);
        $this->get(route('training.index', ['batch_id' => $batch->id]))->assertViewHas('enrollments', fn ($rows) => $rows->count() === 15 && $rows->total() === 16)->assertSee('batch_id='.$batch->id);
        $this->put(route('training-batches.update', $batch), ['company_id' => $batch->company_id, 'code' => $batch->code, 'name' => $batch->name, 'status' => $batch->status, 'capacity' => 15])->assertSessionHasErrors('capacity');
        $this->assertNull($batch->fresh()->capacity);
    }
}
