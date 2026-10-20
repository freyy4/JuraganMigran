<?php

namespace Database\Factories;

use App\Models\JobOrder;
use App\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;

class VacancyFactory extends Factory
{
    protected $model = Vacancy::class;

    public function definition(): array
    {
        return ['job_order_id' => JobOrder::factory(), 'code' => fake()->unique()->bothify('VAC-########'), 'title' => fake()->jobTitle(), 'position_name' => fake()->jobTitle(), 'slug' => fake()->unique()->slug(), 'status' => 'draft', 'quota' => 5];
    }
}
