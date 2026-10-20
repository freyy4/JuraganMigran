<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\FlightDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

class FlightDetailFactory extends Factory
{
    protected $model = FlightDetail::class;

    public function definition(): array
    {
        return ['application_id' => Application::factory(), 'airline' => fake()->company(), 'flight_number' => fake()->bothify('FL-####'), 'departure_airport' => fake()->city(), 'arrival_airport' => fake()->city(), 'departure_at' => now()->addDay(), 'arrival_at' => now()->addDay()->addHours(5)];
    }
}
