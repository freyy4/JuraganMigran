<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationStageHistory;
use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationStageHistoryFactory extends Factory
{
    protected $model = ApplicationStageHistory::class;

    public function definition(): array
    {
        return ['application_id' => Application::factory(), 'to_stage_id' => PipelineStage::factory(), 'entered_at' => now()];
    }
}
