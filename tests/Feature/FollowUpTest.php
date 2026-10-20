<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_follow_up_creation_filters_edit_and_completion(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $application = Application::factory()->create();
        $this->get(route('follow-ups.create'))->assertOk()->assertSee('Tambah Tindak Lanjut');
        $payload = ['title' => 'Hubungi CPMI', 'application_id' => $application->id, 'assigned_to' => $user->id, 'priority' => 'normal', 'status' => 'open', 'due_at' => null, 'task_type' => 'other'];
        $this->post(route('follow-ups.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('follow-ups.index', ['tab' => 'upcoming']));
        $followUp = Task::firstOrFail();
        $this->assertEquals('follow_up', $followUp->task_type);
        $other = Task::factory()->create(['title' => 'Tugas Lain', 'task_type' => 'other', 'due_at' => null]);
        $this->get(route('follow-ups.index', ['tab' => 'upcoming', 'assigned_to' => $user->id]))->assertOk()->assertSee('Hubungi CPMI')->assertDontSee('Tugas Lain')->assertViewHas('tasks', fn ($rows) => $rows->total() === 1);
        $this->get(route('follow-ups.edit', $followUp))->assertOk()->assertSee('Edit Tindak Lanjut');
        $this->put(route('follow-ups.update', $followUp), array_replace($payload, ['title' => 'Konfirmasi Dokumen']))->assertSessionHasNoErrors();
        $this->assertEquals('follow_up', $followUp->fresh()->task_type);
        $this->get(route('follow-ups.edit', $other))->assertNotFound();
        $this->patch(route('follow-ups.complete', $other))->assertNotFound();
        $this->patch(route('follow-ups.complete', $followUp))->assertRedirect(route('follow-ups.index', ['tab' => 'completed']));
        $this->assertTrue($followUp->fresh()->isCompleted());
        $this->get(route('follow-ups.index', ['tab' => 'completed']))->assertOk()->assertSee('Konfirmasi Dokumen');
        $this->get(route('dashboard'))->assertOk()->assertSee('CRM')->assertSee(route('follow-ups.index'), false);
        auth()->logout();
        $this->get(route('follow-ups.index'))->assertRedirect(route('login'));
    }
}
