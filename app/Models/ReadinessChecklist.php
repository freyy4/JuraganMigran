<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReadinessChecklist extends Model
{
    use HasFactory;

    protected $fillable = ['application_id', 'type', 'completion_percentage', 'status'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReadinessItem::class);
    }

    public function progress(): int
    {
        $items = $this->items;

        return $items->isEmpty() ? 0 : (int) floor($items->where('status', 'completed')->count() * 100 / $items->count());
    }

    public function isReady(): bool
    {
        $required = $this->items->where('is_required', true);

        return $required->isNotEmpty() && $required->every(fn ($item) => $item->status === 'completed');
    }

    public function synchronize(): void
    {
        $this->unsetRelation('items');
        $this->update(['completion_percentage' => $this->progress(), 'status' => $this->isReady() ? 'ready' : 'pending']);
    }
}
