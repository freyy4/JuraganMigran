<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\HealthInterview;
use Illuminate\Database\Eloquent\Factories\Factory;

class HealthInterviewFactory extends Factory
{
    protected $model = HealthInterview::class;

    public function definition(): array
    {
        return ['application_id' => Application::factory(), 'status' => 'pending', 'completeness_percentage' => 0];
    }
}
