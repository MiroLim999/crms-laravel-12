<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A further page of a record saved from a multi-page spread (page 2 and on).
 * Page 1 stays on the record itself.
 */
class RecordPage extends Model
{
    protected $fillable = [
        'record_id', 'page_no', 'scan_path', 'scan_mime', 'scan_rotation',
        'page_image_path', 'source_page',
    ];

    protected function casts(): array
    {
        return [
            'page_no' => 'integer',
            'source_page' => 'integer',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(CivilRecord::class, 'record_id');
    }
}
