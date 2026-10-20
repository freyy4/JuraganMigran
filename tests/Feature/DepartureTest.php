<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ReadinessChecklist;
use App\Models\ReadinessItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DepartureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(Carbon::parse('2026-10-03 18:00', 'Asia/Jakarta')->utc());
    }

    private function data(Application $application, string $time = '2026-10-03T09:00'): array
    {
        return ['application_id' => $application->id, 'scheduled_at' => $time, 'completed_at' => $time, 'status' => 'completed', 'notes' => 'Catatan aktual'];
    }

    public function test_opp_crud_filters_duplicate_guard_and_scope(): void
    {
        $application = Application::factory()->create();
        $this->get(route('opp.create'))->assertOk()->assertSee('Bagian Keberangkatan');
        $data = $this->data($application);
        $this->post(route('opp.store'), $data)->assertSessionHasNoErrors();
        $event = DB::table('travel_events')->first();
        $this->assertSame('OPP', $event->event_type);
        $this->assertSame('2026-10-03 02:00:00', $event->completed_at);
        $this->get(route('opp.index', ['search' => $application->candidate->candidate_code, 'status' => 'completed']))->assertOk()->assertViewHas('events', fn ($rows) => $rows->total() === 1);
        $this->get(route('opp.edit', $event->id))->assertOk()->assertSee('2026-10-03T09:00');
        $this->post(route('opp.store'), $data)->assertSessionHasErrors('application_id');
        $this->get(route('arrivals.edit', $event->id))->assertNotFound();
        $other = Application::factory()->create();
        $this->put(route('opp.update', $event->id), array_replace($data, ['application_id' => $other->id]))->assertSessionHasErrors('application_id');
        $this->put(route('opp.update', $event->id), array_replace($data, ['status' => 'cancelled']))->assertSessionHasNoErrors();
        $this->assertNull(DB::table('travel_events')->find($event->id)->completed_at);
        $this->assertSame('active', $application->fresh()->status);
    }

    public function test_departure_readiness_and_arrival_ordering_and_history_guard(): void
    {
        $application = Application::factory()->create();
        $departure = $this->data($application);
        $this->post(route('departures.store'), $departure)->assertSessionHasErrors('status');
        $checklist = ReadinessChecklist::factory()->create(['application_id' => $application->id]);
        ReadinessItem::factory()->create(['readiness_checklist_id' => $checklist->id, 'is_required' => true, 'status' => 'completed']);
        $this->post(route('departures.store'), $departure)->assertSessionHasNoErrors();
        $event = DB::table('travel_events')->where('event_type', 'departure')->first();
        $this->post(route('arrivals.store'), $this->data($application, '2026-10-03T08:00'))->assertSessionHasErrors('completed_at');
        $this->post(route('arrivals.store'), $this->data($application, '2026-10-03T12:00'))->assertSessionHasNoErrors();
        $this->put(route('departures.update', $event->id), array_replace($departure, ['status' => 'cancelled']))->assertSessionHasErrors('status');
        $this->put(route('departures.update', $event->id), $this->data($application, '2026-10-03T13:00'))->assertSessionHasErrors('status');
        $this->assertDatabaseCount('travel_events', 2);
        $this->assertDatabaseHas('travel_events', ['id' => $event->id, 'status' => 'completed']);
    }

    public function test_missing_departure_future_actual_and_invalid_status_are_rejected(): void
    {
        $application = Application::factory()->create();
        $this->post(route('arrivals.store'), $this->data($application))->assertSessionHasErrors('completed_at');
        $this->post(route('opp.store'), $this->data($application, '2026-10-04T09:00'))->assertSessionHasErrors('completed_at');
        $this->post(route('opp.store'), array_replace($this->data($application), ['completed_at' => null]))->assertSessionHasErrors('completed_at');
        $this->get(route('opp.index', ['status' => 'bad']))->assertSessionHasErrors('status');
        $this->assertDatabaseCount('travel_events', 0);
    }

    public function test_authentication_and_old_menu_links(): void
    {
        foreach (['menu-6-1' => 'opp.index', 'menu-6-3' => 'departures.index', 'menu-6-4' => 'arrivals.index'] as $key => $route) {
            $this->get('/modul/'.$key)->assertRedirect(route($route, ['menu' => $key]));
        }
        auth()->logout();
        foreach (['opp', 'departures', 'arrivals'] as $prefix) {
            $this->get(route($prefix.'.index'))->assertRedirect(route('login'));
            $this->post(route($prefix.'.store'), [])->assertRedirect(route('login'));
            $this->put(route($prefix.'.update', 1), [])->assertRedirect(route('login'));
        }
    }
}
