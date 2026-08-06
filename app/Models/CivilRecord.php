<?php

namespace App\Models;

use App\Enums\ChangeRequestStatus;
use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A digitised civil registry record.
 *
 * Named CivilRecord rather than Record to keep it unambiguous in a codebase that
 * also talks about audit records and change records. The table is `records`.
 */
class CivilRecord extends Model
{
    use HasFactory;

    protected $table = 'records';

    protected $fillable = [
        'doc_type', 'document_type_id', 'document_template_id', 'registry_number', 'status',
        'scan_path', 'scan_mime', 'ocr_model_key', 'created_by',
        'submitted_by', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'doc_type' => DocumentType::class,
            'status' => RecordStatus::class,
            'submitted_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function fields(): HasMany
    {
        return $this->hasMany(RecordField::class, 'record_id')->orderBy('sort_order');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function documentTypeDefinition(): BelongsTo
    {
        return $this->belongsTo(DocumentTypeDefinition::class, 'document_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class, 'record_id')->latest();
    }

    // ------------------------------------------------------------------- state

    /**
     * Submitted records are locked. Nothing edits their values in place - not
     * Staff, and certainly not Admin. Corrections go through a change request.
     */
    public function isLocked(): bool
    {
        return $this->status->isLocked();
    }

    public function isDraft(): bool
    {
        return $this->status === RecordStatus::Draft;
    }

    public function hasPendingChangeRequest(): bool
    {
        return $this->changeRequests()
            ->where('status', ChangeRequestStatus::Pending->value)
            ->exists();
    }

    // ----------------------------------------------------------------- display

    /**
     * Best available human label: the first non-empty verified value, which for
     * every template is a name field.
     */
    public function title(): string
    {
        $first = $this->fields->first(fn (RecordField $f) => filled($f->verified_value));

        return $first?->verified_value ?? 'Untitled record';
    }

    public function typeLabel(): string
    {
        return $this->documentTypeDefinition?->label() ?? $this->doc_type->label();
    }

    public function typeShortLabel(): string
    {
        return $this->documentTypeDefinition?->shortLabel() ?? $this->doc_type->shortLabel();
    }

    public function typeIcon(): string
    {
        return $this->documentTypeDefinition?->icon() ?? $this->doc_type->icon();
    }

    /**
     * Fields the model was unsure about. Confidence is the model's certainty in
     * its own output, not accuracy, so this is a review prompt.
     *
     * @return Collection<int, RecordField>
     */
    public function lowConfidenceFields(): Collection
    {
        $threshold = OcrSetting::threshold();

        return $this->fields->filter(
            fn (RecordField $f) => $f->ocr_confidence !== null && $f->ocr_confidence < $threshold,
        );
    }

    protected static function booted(): void
    {
        static::creating(function (self $record) {
            if ($record->document_type_id !== null) {
                return;
            }

            $key = $record->doc_type instanceof DocumentType
                ? $record->doc_type->value
                : (string) $record->doc_type;
            $record->document_type_id = DocumentTypeDefinition::where('key', $key)->value('id');
        });
    }
}
