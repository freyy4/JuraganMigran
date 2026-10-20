<?php

namespace Database\Factories;

use App\Models\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReadinessChecklistFactory extends Factory
{
    public function definition(): array
    {
        return ['application_id' => Application::factory(), 'type' => 'departure', 'status' => 'pending', 'completion_percentage' => 0];
    }
}
