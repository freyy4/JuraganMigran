<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class TrainingBatchFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory()->state(['type' => 'lpk']), 'code' => fake()->unique()->bothify('TRN-########'), 'name' => fake()->words(3, true), 'start_date' => today(), 'end_date' => today()->addMonth(), 'capacity' => null, 'status' => 'planned'];
    }
}
