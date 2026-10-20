<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineStage extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'sequence', 'sla_hours', 'is_terminal', 'is_active'];

    protected function casts(): array
    {
        return ['is_terminal' => 'boolean', 'is_active' => 'boolean'];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'current_stage_id');
    }
}
