<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        return ['application_code' => fake()->unique()->bothify('APP-########'), 'candidate_id' => Candidate::factory(), 'vacancy_id' => Vacancy::factory(), 'status' => 'active', 'applied_at' => now()];
    }
}
