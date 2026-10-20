<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Interview;
use Illuminate\Database\Eloquent\Factories\Factory;

class InterviewFactory extends Factory
{
    protected $model = Interview::class;

    public function definition(): array
    {
        return ['application_id' => Application::factory(), 'type' => 'general', 'status' => 'scheduled', 'scheduled_at' => now()->addHour()];
    }
}
