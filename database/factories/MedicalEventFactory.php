<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Company;
use App\Models\MedicalEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

class MedicalEventFactory extends Factory
{
    protected $model = MedicalEvent::class;

    public function definition(): array
    {
        return ['application_id' => Application::factory(), 'clinic_id' => Company::factory()->state(['type' => 'clinic', 'status' => 'active']), 'status' => 'scheduled', 'scheduled_at' => now()->addDay()];
    }
}
