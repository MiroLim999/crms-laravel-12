<?php

namespace App\Services;

use App\Enums\ChangeRequestStatus;
use App\Exceptions\ChangeRequestException;
use App\Models\ChangeRequest;
use App\Models\CivilRecord;
use App\Models\RecordField;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Corrections to saved records.
 *
 * This service is the only path by which a saved record's values change. A
 * correction is stored as a change request that is approved the moment it is
 * saved, so the record's Change history and the dashboard keep working, and the
 * old and new values stay on record.
 */
class ChangeRequestService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Save a correction: change the values at once and keep the old ones.
     *
     * @param  array<int, string|null>  $proposals  Keyed by record_field id.
     */
    public function correct(
        CivilRecord $record,
        array $proposals,
        ?string $reason,
        User $editor,
    ): ChangeRequest {
        $fields = $record->fields->keyBy('id');

        // Only keep fields whose value actually differs. Requiredness is a
        // capture-time rule and cannot be bypassed by posting an empty value.
        $changes = [];
        foreach ($proposals as $fieldId => $value) {
            $field = $fields->get((int) $fieldId);

            if (! $field instanceof RecordField) {
                continue;
            }

            $proposed = $this->normaliseFieldValue($field, $value);
            if ($proposed !== $this->normaliseValue($field->verified_value)) {
                $changes[$field->getKey()] = $proposed;
            }
        }

        if ($changes === []) {
            throw new ChangeRequestException('None of those values differ from what is on record.');
        }

        $reason = $this->normaliseValue($reason);

        return DB::transaction(function () use ($record, $changes, $fields, $reason, $editor) {
            // Lock the record row, so two saves of one record take turns.
            CivilRecord::whereKey($record->getKey())->lockForUpdate()->first();

            $correction = $record->changeRequests()->create([
                'status' => ChangeRequestStatus::Approved,
                'reason' => $reason,
                'requested_by' => $editor->getKey(),
                'reviewed_by' => $editor->getKey(),
                'reviewed_at' => now(),
            ]);

            $previous = [];
            $applied = [];

            foreach ($changes as $fieldId => $proposed) {
                $field = $fields[$fieldId];

                $correction->items()->create([
                    'record_field_id' => $fieldId,
                    'current_value' => $field->verified_value,
                    'proposed_value' => $proposed,
                ]);

                $previous[$field->name] = $field->verified_value;
                $applied[$field->name] = $proposed;

                $field->forceFill(['verified_value' => $proposed])->save();
            }

            $count = count($changes);

            $this->audit->log(
                'record.corrected',
                $record,
                old: $previous,
                new: $reason === null ? $applied : [...$applied, 'reason' => $reason],
                description: "Corrected {$count} value(s) on record #{$record->getKey()}.",
                actor: $editor,
            );

            return $correction->load('items.field');
        });
    }

    private function normaliseFieldValue(RecordField $field, mixed $value): ?string
    {
        $normalised = $this->normaliseValue($value);

        if ($field->is_required && $normalised === null) {
            throw new ChangeRequestException("{$field->name} is required and cannot be blank.");
        }

        return $normalised;
    }

    private function normaliseValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalised = trim((string) $value);

        return $normalised === '' ? null : $normalised;
    }
}
