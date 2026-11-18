<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One page of a batch: the file and page it is, its state in the workspace
 * (pending, saved, scanning, ready, imported, rejected, failed), its alignment
 * and flags, and the record it was saved as.
 */
class ScanBatchPage extends Model
{
    /** The states a page is kept in (the workspace's passing ones become one of these). */
    public const STATUSES = ['pending', 'saved', 'scanning', 'ready', 'imported', 'rejected', 'failed'];

    protected $fillable = [
        'scan_batch_id', 'client_key', 'position', 'source_name', 'source_hash', 'page_no', 'page_total',
        'record_key', 'status', 'document_page_id', 'workspace_scan_path', 'alignment', 'flags', 'error', 'record_id',
    ];

    protected function casts(): array
    {
        return [
            'alignment' => 'array',
            'flags' => 'array',
            'position' => 'integer',
            'page_no' => 'integer',
            'page_total' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ScanBatch::class, 'scan_batch_id');
    }

    public function documentPage(): BelongsTo
    {
        return $this->belongsTo(DocumentPage::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(CivilRecord::class, 'record_id');
    }
}
