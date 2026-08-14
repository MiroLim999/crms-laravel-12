<?php

namespace App\Services;

use App\Enums\ChangeRequestStatus;
use App\Models\ChangeRequest;
use App\Models\CivilRecord;
use App\Models\RecordField;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The correction workflow for locked records.
 *
 * This service is the only path by which a submitted record's values change. It
 * exists so that no controller is ever tempted to write to a locked record
 * directly, and so the proposal and the decision are both on record.
 *
 * Note the split: Staff propose, Admin decides, and the values are applied by the
 * approval - not by the Admin editing anything.
 */
class ChangeRequestService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Raise a request against a locked record.
     *
     * @param  array<int, string|null>  $proposals  Keyed by record_field id.
     * @param  array{registry_number?: string|null}  $recordProposals
     */
    public function open(
        CivilRecord $record,
        array $proposals,
        string $reason,
        User $requester,
        array $recordProposals = [],
    ): ChangeRequest {
        if (! $record->isLocked()) {
            throw new RuntimeException('This record is still a draft and does not need a change request.');
        }

        if ($record->hasPendingChangeRequest()) {
            throw new RuntimeException('This record already has a pending change request.');
        }

        $fields = $record->fields->keyBy('id');

        // Only keep fields whose value actually differs, so a reviewer is not
        // asked to approve no-ops. Requiredness is a capture-time invariant and
        // cannot be bypassed by manually posting an empty proposal.
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

        $changesRegistryNumber = array_key_exists('registry_number', $recordProposals)
            && $this->normaliseValue($recordProposals['registry_number'])
                !== $this->normaliseValue($record->registry_number);
        $proposedRegistryNumber = $changesRegistryNumber
            ? $this->normaliseValue($recordProposals['registry_number'])
            : null;

        if ($changes === [] && ! $changesRegistryNumber) {
            throw new RuntimeException('None of those values differ from what is on record.');
        }

        return DB::transaction(function () use (
            $record,
            $changes,
            $fields,
            $reason,
            $requester,
            $changesRegistryNumber,
            $proposedRegistryNumber,
        ) {
            $request = $record->changeRequests()->create([
                'status' => ChangeRequestStatus::Pending,
                'reason' => $reason,
                'requested_by' => $requester->getKey(),
                'changes_registry_number' => $changesRegistryNumber,
                'current_registry_number' => $changesRegistryNumber ? $record->registry_number : null,
                'proposed_registry_number' => $proposedRegistryNumber,
            ]);

            foreach ($changes as $fieldId => $proposed) {
                $request->items()->create([
                    'record_field_id' => $fieldId,
                    'current_value' => $fields[$fieldId]->verified_value,
                    'proposed_value' => $proposed,
                ]);
            }

            $changeCount = count($changes) + ($changesRegistryNumber ? 1 : 0);

            $this->audit->log(
                'change_request.opened',
                $request,
                new: [
                    'record_id' => $record->getKey(),
                    'field_count' => count($changes),
                    'registry_number_changed' => $changesRegistryNumber,
                ],
                description: "Requested {$changeCount} change(s) on record #{$record->getKey()}.",
                actor: $requester,
            );

            return $request->load('items.field');
        });
    }

    /**
     * Approve and apply. This is the only place a locked record's values move.
     */
    public function approve(ChangeRequest $request, User $reviewer, ?string $note = null): ChangeRequest
    {
        $this->guardOpen($request);

        return DB::transaction(function () use ($request, $reviewer, $note) {
            $applied = [];
            $previous = [];

            foreach ($request->items as $item) {
                $field = $item->field;

                if ($field === null) {
                    continue;
                }

                $proposed = $this->normaliseFieldValue($field, $item->proposed_value);

                $previous[$field->name] = $field->verified_value;
                $applied[$field->name] = $proposed;

                $field->forceFill(['verified_value' => $proposed])->save();
            }

            if ($request->changes_registry_number) {
                $previous['Registry Number'] = $request->record->registry_number;
                $applied['Registry Number'] = $request->proposed_registry_number;

                $request->record->forceFill([
                    'registry_number' => $request->proposed_registry_number,
                ])->save();
            }

            $request->forceFill([
                'status' => ChangeRequestStatus::Approved,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->log(
                'change_request.approved',
                $request->record,
                old: $previous,
                new: $applied,
                description: "Approved change request #{$request->getKey()} and applied "
                    .count($applied).' value(s).',
                actor: $reviewer,
            );

            return $request;
        });
    }

    public function reject(ChangeRequest $request, User $reviewer, ?string $note = null): ChangeRequest
    {
        $this->guardOpen($request);

        $request->forceFill([
            'status' => ChangeRequestStatus::Rejected,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'decision_note' => $note,
        ])->save();

        $this->audit->log(
            'change_request.rejected',
            $request,
            description: "Rejected change request #{$request->getKey()}."
                .($note ? " Reason: {$note}" : ''),
            actor: $reviewer,
        );

        return $request;
    }

    /**
     * The requester changing their mind. Not a decision, so it needs no reviewer.
     */
    public function withdraw(ChangeRequest $request, User $actor): ChangeRequest
    {
        $this->guardOpen($request);

        $request->forceFill(['status' => ChangeRequestStatus::Withdrawn])->save();

        $this->audit->log(
            'change_request.withdrawn',
            $request,
            description: "Withdrew change request #{$request->getKey()}.",
            actor: $actor,
        );

        return $request;
    }

    private function guardOpen(ChangeRequest $request): void
    {
        if (! $request->isOpen()) {
            throw new RuntimeException(
                "This request is already {$request->status->value} and cannot be changed.",
            );
        }
    }

    private function normaliseFieldValue(RecordField $field, mixed $value): ?string
    {
        $normalised = $this->normaliseValue($value);

        if ($field->is_required && $normalised === null) {
            throw new RuntimeException("{$field->name} is required and cannot be blank.");
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
