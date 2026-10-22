<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Candidate extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['candidate_code', 'status', 'registered_at', 'created_by', 'preferences'];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime', 'preferences' => 'array'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CandidateContact::class);
    }

    public static function statusOptions(): array
    {
        return static::query()->distinct()->orderBy('status')->pluck('status')->prepend('lead')->unique()->values()->all();
    }

    public static function statusLabel(string $status): string
    {
        return $status === 'lead' ? 'Calon baru' : ucfirst(str_replace('_', ' ', $status));
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
