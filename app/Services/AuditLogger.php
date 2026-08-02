<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Single entry point for writing audit entries.
 *
 * Every state change on a record, change request, account, or OCR model goes
 * through here. Do not write AuditLog rows directly - routing through this class
 * is what keeps actor, role, and request metadata consistent.
 */
class AuditLogger
{
    /**
     * Record an action.
     *
     * @param  string  $action  Dotted verb, e.g. 'user.created', 'record.submitted'.
     * @param  Model|null  $subject  The thing acted upon.
     * @param  array<string, mixed>|null  $old  Values before the change.
     * @param  array<string, mixed>|null  $new  Values after the change.
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?string $description = null,
        ?User $actor = null,
    ): AuditLog {
        if ($actor === null) {
            $authenticated = Auth::user();
            $actor = $authenticated instanceof User ? $authenticated : null;
        }

        return AuditLog::create([
            'user_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'actor_role' => $actor?->roleSlug()?->value,
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'description' => $description,
            'old_values' => $this->redact($old),
            'new_values' => $this->redact($new),
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 1000),
        ]);
    }

    /**
     * Persist a pending change and log the diff.
     *
     * Call this with the model still dirty - Eloquent re-syncs originals during
     * save(), so the before values are unrecoverable afterwards. Saving and
     * logging happen in one transaction so the trail cannot drift from the data.
     *
     * @param  Model  $subject  A model with unsaved changes.
     */
    public function saveAndLog(
        string $action,
        Model $subject,
        ?string $description = null,
        ?User $actor = null,
    ): AuditLog {
        $new = $subject->getDirty();
        unset($new['updated_at']);

        $old = array_intersect_key($subject->getOriginal(), $new);

        return DB::transaction(function () use ($action, $subject, $old, $new, $description, $actor) {
            $subject->save();

            return $this->log($action, $subject, $old, $new, $description, $actor);
        });
    }

    /**
     * Strip anything secret before it reaches storage. Audit logs are read by
     * admins, so they must never carry credentials or tokens.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $secret = ['password', 'password_confirmation', 'remember_token', 'temporary_password'];

        foreach ($secret as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
