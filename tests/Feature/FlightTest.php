<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\FlightDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function payload(): array
    {
        return ['application_id' => Application::factory()->create()->id, 'airline' => 'Maskapai Pengujian', 'flight_number' => 'JM-123', 'departure_airport' => 'Bandara Asal', 'arrival_airport' => 'Bandara Tujuan', 'departure_at' => '2026-10-03T00:30', 'arrival_at' => '2026-10-03T06:00'];
    }

    public function test_crud_and_timezone(): void
    {
        $data = $this->payload();
        $this->get(route('flights.create'))->assertOk();
        $this->post(route('flights.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $flight = FlightDetail::firstOrFail();
        $this->assertSame('2026-10-02 17:30', $flight->departure_at->format('Y-m-d H:i'));
        $this->assertSame($flight->id, $flight->application->flights()->first()->id);
        $this->get(route('flights.show', $flight))->assertOk()->assertSee('Maskapai Pengujian')->assertSee($flight->application->candidate->person->full_name);
        $this->get(route('flights.edit', $flight))->assertOk()->assertSee('2026-10-03T00:30');
        $data['flight_number'] = 'JM-456';
        $this->put(route('flights.update', $flight), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('flight_details', ['id' => $flight->id, 'flight_number' => 'JM-456']);
        $this->delete(route('flights.destroy', $flight))->assertRedirect(route('flights.index'));
        $this->assertDatabaseMissing('flight_details', ['id' => $flight->id]);
        $this->assertDatabaseHas('applications', ['id' => $data['application_id']]);
    }

    public function test_date_country_company_filters_and_pagination(): void
    {
        $data = $this->payload();
        $this->post(route('flights.store'), $data)->assertSessionHasNoErrors();
        $flight = FlightDetail::firstOrFail();
        FlightDetail::factory()->create(['departure_at' => '2026-10-03 17:00:00']);
        $filters = ['date_from' => '2026-10-03', 'date_to' => '2026-10-03', 'country_id' => $flight->application->vacancy->jobOrder->country_id, 'company_id' => $flight->application->vacancy->jobOrder->company_id];
        $this->get(route('flights.index', $filters))->assertOk()->assertViewHas('flights', fn ($rows) => $rows->total() === 1);
        $this->get(route('flights.index', ['date_to' => '2026-10-02']))->assertOk()->assertSee('Tidak ada jadwal penerbangan');
        $this->get(route('flights.index', ['search' => 'JM-123']))->assertOk()->assertViewHas('flights', fn ($rows) => $rows->total() === 1);
        FlightDetail::factory()->count(15)->create(['application_id' => $flight->application_id]);
        $this->get(route('flights.index', ['company_id' => $filters['company_id']]))->assertOk()->assertViewHas('flights', fn ($rows) => $rows->count() === 15 && str_contains($rows->nextPageUrl(), 'company_id='));
    }

    public function test_validation_historical_data_and_auth(): void
    {
        $data = $this->payload();
        $this->post(route('flights.store'), [...$data, 'application_id' => 9999, 'arrival_at' => '2026-10-02T00:00'])->assertSessionHasErrors(['application_id', 'arrival_at']);
        $this->assertDatabaseCount('flight_details', 0);
        $this->post(route('flights.store'), $data)->assertSessionHasNoErrors();
        $flight = FlightDetail::firstOrFail();
        $name = $flight->application->candidate->person->full_name;
        $flight->application->delete();
        $flight->application->candidate->delete();
        $this->get(route('flights.show', $flight))->assertOk()->assertSee($name);
        $this->put(route('flights.update', $flight), $data)->assertSessionHasNoErrors();
        $this->post(route('flights.store'), $data)->assertSessionHasErrors('application_id');
        $this->get(route('flights.index', ['date_from' => '2026-10-05', 'date_to' => '2026-10-01']))->assertSessionHasErrors('date_to');
        auth()->logout();
        $this->get(route('flights.index'))->assertRedirect(route('login'));
        $this->delete(route('flights.destroy', $flight))->assertRedirect(route('login'));
        $this->assertDatabaseHas('flight_details', ['id' => $flight->id]);
    }
}
