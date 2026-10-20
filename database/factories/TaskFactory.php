<?php

namespace Database\Factories;

use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return ['title' => fake()->sentence(), 'priority' => 'normal', 'status' => 'open', 'due_at' => now()->addHour()];
    }
}
