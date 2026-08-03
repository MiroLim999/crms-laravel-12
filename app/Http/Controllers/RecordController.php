<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\RecordStatus;
use App\Models\CivilRecord;
use App\Models\OcrSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The searchable archive. Every signed-in role may read it.
 *
 * Read-only by design. There is no edit or destroy action here for anyone,
 * including Super Admin: submitted records change only through an approved change
 * request, which is what makes the trail worth anything.
 */
class RecordController extends Controller
{
    public function index(Request $request): View
    {
        $records = CivilRecord::query()
            ->with(['fields', 'submitter'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(function ($q) use ($term) {
                    $q->where('registry_number', 'like', $term)
                        ->orWhereHas('fields', fn ($f) => $f->where('verified_value', 'like', $term));
                });
            })
            ->when($request->filled('type'), fn ($q) => $q->where('doc_type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('submitted_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('submitted_at', '<=', $request->date('to')))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('records.index', [
            'records' => $records,
            'documentTypes' => DocumentType::cases(),
            'statuses' => RecordStatus::cases(),
        ]);
    }

    public function show(CivilRecord $record): View
    {
        $record->load([
            'fields',
            'submitter',
            'template',
            'changeRequests.requester',
            'changeRequests.reviewer',
            'changeRequests.items.field',
        ]);

        return view('records.show', [
            'record' => $record,
            'threshold' => OcrSetting::threshold(),
        ]);
    }

    /**
     * Stream the stored scan. Scans hold personal data, so they live on the local
     * disk outside the web root and are served only to signed-in users who may
     * view the archive.
     */
    public function scan(CivilRecord $record)
    {
        abort_if($record->scan_path === null, 404);
        abort_unless(Storage::disk('local')->exists($record->scan_path), 404);

        return Response::file(
            Storage::disk('local')->path($record->scan_path),
            ['Content-Type' => $record->scan_mime ?? 'application/octet-stream'],
        );
    }
}
