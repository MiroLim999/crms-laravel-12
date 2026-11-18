<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record of a batch, as far as it got: what was typed and ticked in Verify
 * and Review & Export, kept until it is saved; once saved, the table it was
 * saved with (for the batch's Download all).
 */
class ScanBatchDraft extends Model
{
    protected $fillable = ['scan_batch_id', 'record_key', 'draft'];

    protected function casts(): array
    {
        return ['draft' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ScanBatch::class, 'scan_batch_id');
    }
}
