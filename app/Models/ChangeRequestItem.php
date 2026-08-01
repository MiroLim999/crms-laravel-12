<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeRequestItem extends Model
{
    protected $fillable = [
        'change_request_id', 'record_field_id', 'current_value', 'proposed_value',
    ];

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(RecordField::class, 'record_field_id');
    }
}
