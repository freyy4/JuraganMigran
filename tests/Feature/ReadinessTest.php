<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ReadinessChecklist;
use App\Models\ReadinessItem;
use App\Models\User;
use Database\Seeders\ReadinessChecklistSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_database_items_progress_and_ready_guard(): void
    {
        $application = Application::factory()->create();
        $checklist = ReadinessChecklist::factory()->create(['application_id' => $application->id, 'completion_percentage' => 100, 'status' => 'ready']);
        $required = ReadinessItem::factory()->create(['readiness_checklist_id' => $checklist->id, 'name' => 'Syarat dari Database']);
        $optional = ReadinessItem::factory()->create(['readiness_checklist_id' => $checklist->id, 'is_required' => false]);
        $this->get(route('readiness.show', $application))->assertOk()->assertSee('Syarat dari Database')->assertSee('0%')->assertSee('Belum Siap');
        $this->patch(route('applications.update-status', $application), ['status' => 'ready_for_departure'])->assertSessionHasErrors('status');
        $this->put(route('readiness.update', [$application, $checklist]), ['items' => [$required->id => 'completed', $optional->id => 'pending']])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('readiness_items', ['id' => $required->id, 'verified_by' => auth()->id(), 'status' => 'completed']);
        $this->assertNotNull($required->fresh()->verified_at);
        $this->assertEquals(50, $checklist->fresh()->completion_percentage);
        $this->assertTrue($application->isReadyForDeparture());
        $this->assertEquals('active', $application->fresh()->status);
        $this->get(route('readiness.index', ['readiness' => 'ready']))->assertViewHas('applications', fn ($rows) => $rows->total() === 1);
        $this->patch(route('applications.update-status', $application), ['status' => 'ready_for_departure'])->assertSessionHasNoErrors();
        $this->put(route('readiness.update', [$application, $checklist]), ['items' => [$required->id => 'pending', $optional->id => 'completed']])->assertSessionHasNoErrors();
        $this->assertEquals('active', $application->fresh()->status);
        $this->assertNull($required->fresh()->verified_by);
        $this->assertNull($required->fresh()->verified_at);
        $this->get(route('readiness.index', ['readiness' => 'pending', 'search' => $application->application_code]))->assertViewHas('applications', fn ($rows) => $rows->total() === 1);
    }

    public function test_create_validation_and_item_ownership(): void
    {
        $application = Application::factory()->create();
        $this->get(route('readiness.show', $application))->assertOk()->assertSee('Buat Checklist');
        $this->patch(route('applications.update-status', $application), ['status' => 'ready_for_departure'])->assertSessionHasErrors('status');
        $this->post(route('readiness.store', $application), ['items' => [['code' => 'custom', 'name' => 'Persyaratan Khusus', 'is_required' => 1]]])->assertSessionHasNoErrors();
        $checklist = $application->readinessChecklists()->firstOrFail();
        $item = $checklist->items()->firstOrFail();
        $this->post(route('readiness.store', $application), ['items' => [['code' => 'custom', 'name' => 'Duplikat', 'is_required' => 1]]])->assertSessionHasErrors('items');
        $this->put(route('readiness.update', [$application, $checklist]), ['items' => [$item->id => 'invalid']])->assertSessionHasErrors();
        $this->put(route('readiness.update', [$application, $checklist]), ['items' => [999 => 'completed']])->assertSessionHasErrors();
        $this->put(route('readiness.update', [Application::factory()->create(), $checklist]), ['items' => [$item->id => 'completed']])->assertNotFound();
        $this->get(route('readiness.index', ['readiness' => 'unknown']))->assertSessionHasErrors();
        $other = Application::factory()->create();
        $this->post(route('readiness.store', $other), ['items' => [['code' => 'same', 'name' => 'A', 'is_required' => true], ['code' => 'same', 'name' => 'B', 'is_required' => true]]])->assertSessionHasErrors();
        $this->assertEquals(1, $application->readinessChecklists()->count());
        auth()->logout();
        $this->get(route('readiness.index'))->assertRedirect(route('login'));
    }

    public function test_seed_is_idempotent_and_empty_or_optional_only_checklists_are_not_ready(): void
    {
        $application = Application::factory()->create();
        $this->seed(ReadinessChecklistSeeder::class);
        $this->seed(ReadinessChecklistSeeder::class);
        $this->assertDatabaseCount('readiness_checklists', 1);
        $this->assertDatabaseCount('readiness_items', 6);
        $this->get(route('readiness.show', $application))->assertOk()->assertSee('Paspor')->assertSee('Visa')->assertSee('Perjanjian')->assertSee('Tiket')->assertSee('OPP')->assertSee('Dokumen Asli');
        $other = Application::factory()->create();
        $checklist = ReadinessChecklist::factory()->create(['application_id' => $other->id]);
        $this->assertFalse($other->isReadyForDeparture());
        ReadinessItem::factory()->create(['readiness_checklist_id' => $checklist->id, 'is_required' => false, 'status' => 'completed']);
        $this->assertFalse($other->isReadyForDeparture());
        $this->get(route('readiness.index', ['readiness' => 'ready']))->assertViewHas('applications', fn ($rows) => $rows->total() === 0);
        Application::factory()->count(16)->create();
        $this->get(route('readiness.index', ['readiness' => 'empty']))->assertViewHas('applications', fn ($rows) => $rows->count() === 15 && $rows->total() === 16)->assertSee('readiness=empty');
    }
}
