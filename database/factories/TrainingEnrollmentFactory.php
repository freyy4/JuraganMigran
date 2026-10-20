<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\TrainingBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

class TrainingEnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return ['application_id' => Application::factory(), 'batch_id' => TrainingBatch::factory(), 'status' => 'scheduled', 'joined_at' => null, 'completed_at' => null];
    }
}
