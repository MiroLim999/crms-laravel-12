<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A training dataset known to CRMS.
 *
 * The images live on disk under ml/datasets/<name>/ and belong to the FastAPI
 * service. This row carries what Laravel needs: who uploaded it and whether the
 * last validation said it is safe to train on.
 */
class MlDataset extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'notes',
        'train_count', 'val_count', 'test_count', 'total_images', 'size_bytes',
        'validation', 'is_valid', 'validated_at', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'validation' => 'array',
            'is_valid' => 'boolean',
            'validated_at' => 'datetime',
            'train_count' => 'integer',
            'val_count' => 'integer',
            'test_count' => 'integer',
            'total_images' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Has it ever been validated? Distinct from having been validated and failed.
     */
    public function isValidated(): bool
    {
        return $this->validated_at !== null;
    }

    /**
     * Only a dataset that passed validation may be trained on. A manifest pointing
     * at missing files wastes hours of GPU time and fails deep into an epoch.
     */
    public function isTrainable(): bool
    {
        return $this->is_valid === true && $this->usableTrainRows() > 0;
    }

    public function usableTrainRows(): int
    {
        return (int) data_get($this->validation, 'usable.train', 0);
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return (array) data_get($this->validation, 'errors', []);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return (array) data_get($this->validation, 'warnings', []);
    }

    public function humanSize(): string
    {
        $bytes = $this->size_bytes;

        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $index => $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return round($bytes, $index === 0 ? 0 : 1).' '.$unit;
            }
            $bytes /= 1024;
        }

        return $bytes.' B';
    }
}
