<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Person extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'persons';

    protected $fillable = ['full_name', 'nik', 'birth_place', 'birth_date', 'gender', 'phone', 'whatsapp', 'email', 'address', 'education_level', 'marital_status', 'nationality', 'current_job', 'languages', 'height_cm', 'weight_kg'];

    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(function (Person $person): void {
            $person->uuid ??= (string) Str::uuid();
        });
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }
}
