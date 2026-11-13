<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time a record was downloaded from the archive, as CSV or Excel.
 */
class RecordDownload extends Model
{
    protected $fillable = ['record_id', 'user_id', 'format', 'downloaded_at'];

    protected function casts(): array
    {
        return ['downloaded_at' => 'datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(CivilRecord::class, 'record_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
