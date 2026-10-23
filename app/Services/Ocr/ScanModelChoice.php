<?php

namespace App\Services\Ocr;

use App\Models\OcrModel;
use App\Models\OcrSetting;

/**
 * Which TrOCR model a scan reads with.
 *
 * Shared by the scan workspace and the page-processing flow so both honour the
 * same rule: Staff may pick only when a Super Admin allowed it, and anything
 * else falls back to the promoted model.
 */
class ScanModelChoice
{
    public function __construct(private readonly OcrClient $ocr) {}

    /**
     * Models Staff may pick from, or an empty list when they may not pick at all.
     *
     * Empty is the default: a registry where every reading came from the one model
     * a Super Admin approved is easier to stand behind than one where each Staff
     * member chose for themselves.
     *
     * @return list<array{key: string, label: string, is_active: bool}>
     */
    public function selectable(): array
    {
        if (! OcrSetting::staffMayChooseModel()) {
            return [];
        }

        $health = $this->ocr->health();

        if (! $health['reachable']) {
            return [];
        }

        $activeKey = OcrModel::active()?->key;

        return collect($health['models'])
            ->map(fn (array $model) => [
                'key' => $model['key'],
                'label' => $model['label'] ?? $model['key'],
                'is_active' => $model['key'] === $activeKey,
            ])
            // The promoted model first, so the default is the obvious choice.
            ->sortByDesc(fn (array $model) => (int) $model['is_active'])
            ->values()
            ->all();
    }

    /**
     * Which model this reading should run against.
     *
     * A submitted key is honoured only when Staff choice is switched on and the
     * service can actually serve it. Anything else falls back to the promoted model,
     * so a stale tab or a hand-edited request cannot silently swap the model behind
     * a record.
     */
    public function resolve(?string $requested): ?string
    {
        $active = OcrModel::active()?->key;

        if ($requested === null || $requested === '') {
            return $active;
        }

        $allowed = array_column($this->selectable(), 'key');

        return in_array($requested, $allowed, true) ? $requested : $active;
    }
}
