<?php

namespace Database\Factories;

use App\Models\ReadinessChecklist;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReadinessItemFactory extends Factory
{
    public function definition(): array
    {
        return ['readiness_checklist_id' => ReadinessChecklist::factory(), 'code' => fake()->unique()->word(), 'name' => fake()->words(2, true), 'is_required' => true, 'status' => 'pending'];
    }
}
