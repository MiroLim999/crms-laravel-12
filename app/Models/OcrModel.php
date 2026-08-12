<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A TrOCR model known to CRMS.
 *
 * The weights live on disk and are owned by the FastAPI service; this row carries
 * what Laravel needs, chiefly which model Staff scanning should use.
 */
class OcrModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'key', 'label', 'notes', 'is_active',
        'cer', 'wer', 'exact_match', 'evaluated_at', 'registered_by',
        'evaluation_dataset', 'evaluation_split', 'evaluation_sample_count',
        'evaluation_manifest_sha256', 'evaluation_weights_sha256',
        'disk_deleted_at', 'disk_deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'cer' => 'float',
            'wer' => 'float',
            'exact_match' => 'float',
            'evaluation_sample_count' => 'integer',
            'evaluated_at' => 'datetime',
            'disk_deleted_at' => 'datetime',
        ];
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function diskDeleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disk_deleted_by');
    }

    /**
     * The model Staff scanning uses. Null means no model has been promoted yet,
     * which the scanning flow treats as "not ready".
     */
    public static function active(): ?self
    {
        return static::where('is_active', true)
            ->whereNull('disk_deleted_at')
            ->first();
    }

    /**
     * Promote this model, demoting any other. Exactly one row may be active.
     */
    public function activate(): void
    {
        DB::transaction(function () {
            static::where('is_active', true)
                ->whereKeyNot($this->getKey())
                ->update(['is_active' => false]);

            $this->forceFill(['is_active' => true])->save();
        });
    }

    public function hasEvaluation(): bool
    {
        return $this->cer !== null
            && $this->wer !== null
            && $this->exact_match !== null
            && $this->evaluation_dataset !== null
            && $this->evaluation_split === 'test'
            && $this->evaluation_sample_count > 0
            && $this->evaluation_manifest_sha256 !== null
            && $this->evaluation_weights_sha256 !== null;
    }
}
