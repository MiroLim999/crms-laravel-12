<?php

namespace App\Models;

use App\Enums\ChangeRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChangeRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'record_id', 'status', 'reason', 'requested_by',
        'changes_registry_number', 'current_registry_number', 'proposed_registry_number',
        'reviewed_by', 'reviewed_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => ChangeRequestStatus::class,
            'changes_registry_number' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(CivilRecord::class, 'record_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChangeRequestItem::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function changeCount(): int
    {
        return $this->items->count() + ($this->changes_registry_number ? 1 : 0);
    }
}
