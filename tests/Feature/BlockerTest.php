<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationBlocker;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlockerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(now()->startOfSecond());
    }

    private function payload(): array
    {
        return ['application_id' => Application::factory()->create()->id, 'stage_id' => PipelineStage::factory()->create()->id, 'category' => 'Dokumen', 'description' => 'Dokumen belum lengkap', 'priority' => 'high', 'status' => 'open', 'owner_id' => auth()->id(), 'due_at' => '2026-10-01T12:00', 'next_action' => 'Hubungi kandidat'];
    }

    public function test_add_edit_resolve_reopen_and_linked_details(): void
    {
        $data = $this->payload();
        $this->get(route('blockers.create'))->assertOk();
        $this->post(route('blockers.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $blocker = ApplicationBlocker::firstOrFail();
        $code = $blocker->code;
        $this->assertStringStartsWith('KDL-', $code);
        $this->assertSame('2026-10-01 05:00', $blocker->due_at->format('Y-m-d H:i'));
        $this->assertSame($blocker->id, $blocker->application->blockers()->first()->id);
        $this->get(route('blockers.show', $blocker))->assertOk()->assertSee($blocker->application->candidate->person->full_name)->assertSee('Detail Lamaran')->assertSee('Profil CPMI')->assertSee('Hubungi kandidat');
        $this->get(route('blockers.edit', $blocker))->assertOk()->assertSee('2026-10-01T12:00');
        $data['description'] = 'Kendala diperbarui';
        $this->put(route('blockers.update', $blocker), $data)->assertSessionHasNoErrors();
        $this->assertSame($code, $blocker->fresh()->code);
        $this->patch(route('blockers.resolve', $blocker))->assertSessionHasNoErrors();
        $this->assertSame(auth()->id(), $blocker->fresh()->resolved_by);
        $this->assertTrue($blocker->fresh()->resolved_at->equalTo(now()));
        $resolvedAt = $blocker->fresh()->resolved_at;
        $this->travel(1)->hours();
        $this->patch(route('blockers.resolve', $blocker))->assertSessionHasNoErrors();
        $this->assertTrue($resolvedAt->equalTo($blocker->fresh()->resolved_at));
        $this->assertFalse($blocker->fresh()->isOverdue());
        $this->put(route('blockers.update', $blocker), $data)->assertSessionHasNoErrors();
        $this->assertNull($blocker->fresh()->resolved_at);
        $this->assertNull($blocker->fresh()->resolved_by);
        $this->assertSame('active', $blocker->application->fresh()->status);
    }

    public function test_filters_search_pagination_and_overdue_highlight(): void
    {
        $blocker = ApplicationBlocker::factory()->create(['category' => 'Categorie unique', 'priority' => 'high', 'owner_id' => auth()->id(), 'due_at' => now()->subMinute()]);
        ApplicationBlocker::factory()->count(15)->create();
        $filters = ['category' => $blocker->category, 'priority' => 'high', 'owner_id' => auth()->id(), 'status' => 'open'];
        foreach ($filters as $field => $value) {
            $this->get(route('blockers.index', [$field => $value]))->assertOk()->assertViewHas('blockers', fn ($rows) => $rows->total() === ($field === 'status' ? 16 : 1));
        }
        $this->get(route('blockers.index', $filters))->assertOk()->assertSee('blocker-overdue', false)->assertSee('Terlambat')->assertViewHas('blockers', fn ($rows) => $rows->total() === 1);
        $this->get(route('blockers.index', ['search' => $blocker->application->candidate->person->full_name]))->assertOk()->assertSee($blocker->code);
        $this->get(route('blockers.index', ['status' => 'open', 'per_page' => 10]))->assertOk()->assertViewHas('blockers', fn ($rows) => $rows->count() === 10 && str_contains($rows->nextPageUrl(), 'status=open'));
        $this->patch(route('blockers.resolve', $blocker));
        $this->get(route('blockers.index', ['search' => $blocker->code]))->assertOk()->assertDontSee('blocker-overdue', false)->assertDontSee('Terlambat');
        $this->get(route('blockers.index', ['search' => 'inexistant']))->assertOk()->assertSee('Tidak ada kendala');
    }

    public function test_validation_and_historical_relations(): void
    {
        $data = $this->payload();
        $this->post(route('blockers.store'), [...$data, 'application_id' => 999, 'stage_id' => 999, 'owner_id' => 999, 'description' => '', 'due_at' => 'invalid'])->assertSessionHasErrors(['application_id', 'stage_id', 'owner_id', 'description', 'due_at']);
        $this->assertDatabaseCount('application_blockers', 0);
        $this->post(route('blockers.store'), $data)->assertSessionHasNoErrors();
        $blocker = ApplicationBlocker::firstOrFail();
        $name = $blocker->application->candidate->person->full_name;
        $blocker->application->candidate->person->delete();
        $blocker->application->candidate->delete();
        $blocker->application->delete();
        $this->get(route('blockers.show', $blocker))->assertOk()->assertSee($name)->assertDontSee('>Detail Lamaran</a>', false);
        $this->put(route('blockers.update', $blocker), $data)->assertSessionHasNoErrors();
        $this->post(route('blockers.store'), $data)->assertSessionHasErrors('application_id');
        auth()->logout();
        $this->get(route('blockers.index'))->assertRedirect(route('login'));
        $this->patch(route('blockers.resolve', $blocker))->assertRedirect(route('login'));
        $this->assertSame('open', $blocker->fresh()->status);
    }
}
