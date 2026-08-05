<?php

namespace App\Services;

use App\Enums\ChangeRequestStatus;
use App\Models\ChangeRequest;
use App\Models\CivilRecord;
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
     */
    public function open(CivilRecord $record, array $proposals, string $reason, User $requester): ChangeRequest
    {
        if (! $record->isLocked()) {
            throw new RuntimeException('This record is still a draft and does not need a change request.');
        }

        if ($record->hasPendingChangeRequest()) {
            throw new RuntimeException('This record already has a pending change request.');
        }

        $fields = $record->fields->keyBy('id');

        // Only keep fields whose value actually differs, so a reviewer is not
        // asked to approve no-ops.
        $changes = collect($proposals)
            ->filter(fn ($value, $id) => $fields->has($id)
                && trim((string) $value) !== trim((string) $fields[$id]->verified_value))
            ->all();

        if ($changes === []) {
            throw new RuntimeException('None of those values differ from what is on record.');
        }

        return DB::transaction(function () use ($record, $changes, $fields, $reason, $requester) {
            $request = $record->changeRequests()->create([
                'status' => ChangeRequestStatus::Pending,
                'reason' => $reason,
                'requested_by' => $requester->getKey(),
            ]);

            foreach ($changes as $fieldId => $proposed) {
                $request->items()->create([
                    'record_field_id' => $fieldId,
                    'current_value' => $fields[$fieldId]->verified_value,
                    'proposed_value' => $proposed,
                ]);
            }

            $this->audit->log(
                'change_request.opened',
                $request,
                new: ['record_id' => $record->getKey(), 'field_count' => count($changes)],
                description: 'Requested changes to '.count($changes)
                    ." field(s) on record #{$record->getKey()}.",
                actor: $requester,
            );

            return $request->load('items.field');
        });
    }

    /**
     * Approve and apply. This is the only place a locked record's values move.
     *
     * @param  array<int>|null  $selectedItemIds  When provided, only items whose primary
     *                                            key is in this list have their field values
     *                                            updated. Null means apply all items.
     */
    public function approve(ChangeRequest $request, User $reviewer, ?string $note = null, ?array $selectedItemIds = null): ChangeRequest
    {
        $this->guardOpen($request);

        return DB::transaction(function () use ($request, $reviewer, $note, $selectedItemIds) {
            $applied = [];
            $previous = [];
            $skipped = [];

            foreach ($request->items as $item) {
                $field = $item->field;

                if ($field === null) {
                    continue;
                }

                // When the moderator selected specific items, skip anything not chosen.
                if ($selectedItemIds !== null && ! in_array($item->getKey(), $selectedItemIds, true)) {
                    $skipped[$field->name] = $item->proposed_value;
                    continue;
                }

                $previous[$field->name] = $field->verified_value;
                $applied[$field->name]  = $item->proposed_value;

                $field->forceFill(['verified_value' => $item->proposed_value])->save();
            }

            $request->forceFill([
                'status'        => ChangeRequestStatus::Approved,
                'reviewed_by'   => $reviewer->getKey(),
                'reviewed_at'   => now(),
                'decision_note' => $note,
            ])->save();

            $this->audit->log(
                'change_request.approved',
                $request->record,
                old: $previous,
                new: $applied,
                description: "Approved change request #{$request->getKey()} and applied "
                    . count($applied) . ' value(s)'
                    . (count($skipped) ? '; skipped: ' . implode(', ', array_keys($skipped)) : '') . '.',
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
}
