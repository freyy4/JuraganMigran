<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(Carbon::parse('2026-10-03 10:00:00', 'Asia/Jakarta')->utc());
    }

    public function test_add_edit_assign_complete_and_reopen_with_jakarta_deadline(): void
    {
        $application = Application::factory()->create();
        $user = User::factory()->create();
        $data = ['title' => 'Tindak lanjut dokumen', 'application_id' => $application->id, 'assigned_to' => $user->id, 'task_type' => 'Pemeriksaan', 'priority' => 'high', 'status' => 'open', 'due_at' => '2026-10-03T12:30'];
        $this->get(route('tasks.create'))->assertOk()->assertSee($application->application_code);
        $this->post(route('tasks.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('tasks.index', ['tab' => 'today']));
        $task = Task::firstOrFail();
        $this->assertSame('2026-10-03 05:30', $task->due_at->format('Y-m-d H:i'));
        $this->assertSame(auth()->id(), $task->created_by);
        $this->assertSame($task->id, $application->tasks()->first()->id);
        $this->get(route('tasks.index'))->assertOk()->assertSee($application->candidate->person->full_name)->assertSee($application->vacancy->title)->assertSee($user->name);
        $this->get(route('tasks.edit', $task))->assertOk()->assertSee('2026-10-03T12:30');
        $data['assigned_to'] = null;
        $data['title'] = 'Judul baru';
        $this->put(route('tasks.update', $task), $data)->assertSessionHasNoErrors();
        $this->assertNull($task->fresh()->assigned_to);
        $this->patch(route('tasks.complete', $task))->assertRedirect(route('tasks.index', ['tab' => 'completed']));
        $completedAt = $task->fresh()->completed_at;
        $this->assertNotNull($completedAt);
        $this->assertSame('completed', $task->fresh()->status);
        $this->travel(1)->hours();
        $this->patch(route('tasks.complete', $task))->assertSessionHasNoErrors();
        $this->assertTrue($completedAt->equalTo($task->fresh()->completed_at));
        $this->put(route('tasks.update', $task), $data)->assertSessionHasNoErrors();
        $this->assertNull($task->fresh()->completed_at);
        $this->assertSame('active', $application->fresh()->status);
    }

    public function test_tabs_filters_pagination_and_null_deadline(): void
    {
        $today = Task::factory()->create(['title' => 'Tugas Hari Ini', 'due_at' => now()->addHour(), 'priority' => 'high', 'assigned_to' => auth()->id()]);
        Task::factory()->create(['title' => 'Tugas Terlambat', 'due_at' => now()->subMinute()]);
        Task::factory()->create(['title' => 'Tugas Besok', 'due_at' => '2026-10-03 17:00:00']);
        Task::factory()->create(['title' => 'Tugas Tanpa Deadline', 'due_at' => null]);
        Task::factory()->create(['title' => 'Tugas Selesai', 'status' => 'completed', 'due_at' => now()->subDay()]);
        foreach (['today' => 'Tugas Hari Ini', 'overdue' => 'Tugas Terlambat', 'completed' => 'Tugas Selesai'] as $tab => $title) {
            $this->get(route('tasks.index', ['tab' => $tab]))->assertOk()->assertSee($title)->assertViewHas('tasks', fn ($rows) => $rows->total() === 1);
        }
        $this->get(route('tasks.index', ['tab' => 'upcoming']))->assertOk()->assertSee('Tugas Besok')->assertSee('Tanpa deadline')->assertViewHas('tasks', fn ($rows) => $rows->total() === 2);
        Task::factory()->count(15)->create(['due_at' => now()->addHour()]);
        $this->get(route('tasks.index', ['priority' => 'high', 'assigned_to' => auth()->id(), 'status' => 'open']))->assertOk()->assertViewHas('tasks', fn ($rows) => $rows->total() === 1);
        $this->get(route('tasks.index', ['tab' => 'today', 'status' => 'open', 'per_page' => 10]))->assertOk()->assertViewHas('tasks', fn ($rows) => $rows->count() === 10 && str_contains($rows->nextPageUrl(), 'status=open'));
        $this->get(route('tasks.index', ['search' => 'Tugas Hari Ini']))->assertOk()->assertViewHas('tasks', fn ($rows) => $rows->total() === 1);
    }

    public function test_validation_optional_application_and_authentication(): void
    {
        $this->post(route('tasks.store'), ['title' => 'Tugas', 'priority' => 'invalid', 'status' => 'open', 'assigned_to' => 999, 'application_id' => 999, 'due_at' => 'invalid'])->assertSessionHasErrors(['priority', 'assigned_to', 'application_id', 'due_at']);
        $this->assertDatabaseCount('tasks', 0);
        $this->post(route('tasks.store'), ['title' => 'Tugas mandiri', 'priority' => 'normal', 'status' => 'open'])->assertSessionHasNoErrors()->assertRedirect(route('tasks.index', ['tab' => 'upcoming']));
        $task = Task::firstOrFail();
        $this->assertNull($task->application_id);
        auth()->logout();
        $this->get(route('tasks.index'))->assertRedirect(route('login'));
        $this->patch(route('tasks.complete', $task))->assertRedirect(route('login'));
        $this->assertSame('open', $task->fresh()->status);
    }
}
