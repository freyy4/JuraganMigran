<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationBlocker;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationBlockerFactory extends Factory
{
    protected $model = ApplicationBlocker::class;

    public function definition(): array
    {
        return ['application_id' => Application::factory(), 'category' => fake()->word(), 'description' => fake()->sentence(), 'priority' => 'normal', 'status' => 'open', 'due_at' => now()->addDay()];
    }
}
