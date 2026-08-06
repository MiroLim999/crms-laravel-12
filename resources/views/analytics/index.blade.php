@extends('layouts.app')

@section('title', 'Analytics')

@section('content')
    <x-page-header title="Analytics"
                   subtitle="Digitisation volume, workload, and OCR signals across the registry." />

    <div class="row g-4 mb-4">
        @php
            $cards = [
                ['Total records', number_format($stats['total_records']), 'bx-archive', 'primary',
                    'Every record, draft and submitted.'],
                ['Submitted this month', number_format($stats['submitted_this_month']), 'bx-check-shield', 'success',
                    'Locked into the archive since '.now()->startOfMonth()->format('j M').'.'],
                ['Pending change requests', number_format($stats['pending_change_requests']), 'bx-git-pull-request', 'warning',
                    'Awaiting an approve or reject decision.'],
                ['Average OCR confidence', $stats['average_confidence'] === null ? '—' : $stats['average_confidence'].'%',
                    'bx-brain', 'info', 'The model\'s certainty, not its accuracy.'],
            ];
        @endphp

        @foreach ($cards as [$label, $value, $icon, $tone, $note])
            <div class="col-sm-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted">{{ $label }}</span>
                            <div class="avatar avatar-sm">
                                <span class="avatar-initial rounded bg-label-{{ $tone }}">
                                    <i class="icon-base bx {{ $icon }} icon-sm"></i>
                                </span>
                            </div>
                        </div>
                        <h4 class="mb-1">{{ $value }}</h4>
                        <small class="text-muted">{{ $note }}</small>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <x-card title="Records per month"
                    subtitle="Created in the last 12 months.">
                <div id="records-by-month-chart" class="mb-3"></div>

                {{-- The table is the source of truth for the chart above and keeps
                     the page useful if the chart script fails to load. --}}
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th class="text-end">Records</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byMonth as $month)
                                <tr>
                                    <td>{{ $month['label'] }}</td>
                                    <td class="text-end">{{ number_format($month['total']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>

        <div class="col-lg-5">
            <x-card title="Records by document type" subtitle="Share of the whole archive.">
                <div id="records-by-type-chart" class="mb-3"></div>

                @forelse ($byDocumentType as $row)
                    <div class="mb-3">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="d-inline-flex align-items-center gap-2">
                                <i class="icon-base bx {{ $row['type']->icon() }} icon-sm text-muted"></i>
                                {{ $row['type']->label() }}
                            </span>
                            <span class="fw-medium">
                                {{ number_format($row['total']) }}
                                <small class="text-muted">({{ $row['share'] }}%)</small>
                            </span>
                        </div>
                        <div class="progress" style="height: 6px;"
                             role="progressbar"
                             aria-label="{{ $row['type']->label() }} share"
                             aria-valuenow="{{ $row['share'] }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" style="width: {{ $row['share'] }}%"></div>
                        </div>
                    </div>
                @empty
                    <x-empty-state icon="bx-archive" title="No records yet"
                                   message="Figures appear once Staff digitise their first certificate." />
                @endforelse
            </x-card>
        </div>

        <div class="col-lg-5">
            <x-card title="OCR quality signals"
                    subtitle="Indicators of model performance on real documents.">
                <div class="alert alert-info d-flex align-items-start mb-4" role="alert">
                    <i class="icon-base bx bx-info-circle icon-md me-2"></i>
                    <div class="small">
                        Neither figure below is a validated accuracy metric. Confidence is the
                        model's certainty in its own output, and the correction rate only counts
                        how often a person changed what the model read — a corrected field may
                        have been right, and an uncorrected one may have been wrong and missed.
                        Read them as prompts to look closer.
                    </div>
                </div>

                <dl class="row mb-0">
                    <dt class="col-7 fw-normal text-muted">Average confidence</dt>
                    <dd class="col-5 text-end fw-medium">
                        {{ $ocrQuality['average_confidence'] === null ? '—' : $ocrQuality['average_confidence'].'%' }}
                    </dd>

                    <dt class="col-7 fw-normal text-muted">Correction rate</dt>
                    <dd class="col-5 text-end fw-medium">
                        {{ $ocrQuality['correction_rate'] === null ? '—' : $ocrQuality['correction_rate'].'%' }}
                    </dd>

                    <dt class="col-7 fw-normal text-muted">Fields corrected by a person</dt>
                    <dd class="col-5 text-end fw-medium">
                        {{ number_format($ocrQuality['corrected_fields']) }}
                        <small class="text-muted">of {{ number_format($ocrQuality['comparable_fields']) }}</small>
                    </dd>

                    <dt class="col-7 fw-normal text-muted">Flagged below {{ $threshold }}% confidence</dt>
                    <dd class="col-5 text-end fw-medium">{{ number_format($ocrQuality['below_threshold']) }}</dd>
                </dl>

                @if ($ocrQuality['correction_rate'] !== null)
                    <div class="progress mt-4" style="height: 8px;"
                         role="progressbar" aria-label="Correction rate"
                         aria-valuenow="{{ $ocrQuality['correction_rate'] }}"
                         aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar bg-warning"
                             style="width: {{ $ocrQuality['correction_rate'] }}%"></div>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="col-lg-7">
            <x-card title="Throughput per person"
                    subtitle="Records submitted, by the account that submitted them.">
                @forelse ($throughput as $person)
                    <div class="mb-3">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span>
                                {{ $person['name'] }}
                                <span class="badge bg-label-secondary ms-1">{{ $person['role'] }}</span>
                            </span>
                            <span class="fw-medium">{{ number_format($person['total']) }}</span>
                        </div>
                        <div class="progress" style="height: 6px;"
                             role="progressbar" aria-label="{{ $person['name'] }} submissions"
                             aria-valuenow="{{ $person['share'] }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar bg-success" style="width: {{ $person['share'] }}%"></div>
                        </div>
                    </div>
                @empty
                    <x-empty-state icon="bx-user-voice" title="Nothing submitted yet"
                                   message="Throughput appears once records are verified and submitted." />
                @endforelse
            </x-card>
        </div>
    </div>
@endsection

@push('scripts')
    {{-- Its own bundle entry: only this page charts anything. --}}
    @vite('resources/js/sneat/apexcharts.js')

    <script>
        // The module above is deferred, so it has published window.ApexCharts by
        // the time DOMContentLoaded fires. Tables and progress bars carry the same
        // figures, so a missing chart degrades rather than breaks the page.
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof window.ApexCharts === 'undefined') {
                return;
            }

            const byMonth = @json($byMonth->values());
            const byType = @json($byDocumentType->map(fn ($row) => [
                'label' => $row['type']->shortLabel(),
                'total' => $row['total'],
            ])->values());

            const purple = '#696cff';

            new ApexCharts(document.getElementById('records-by-month-chart'), {
                chart: { type: 'area', height: 260, toolbar: { show: false } },
                colors: [purple],
                dataLabels: { enabled: false },
                stroke: { curve: 'smooth', width: 2 },
                series: [{ name: 'Records', data: byMonth.map((m) => m.total) }],
                xaxis: { categories: byMonth.map((m) => m.label) },
                yaxis: { labels: { formatter: (value) => Math.round(value) } },
                grid: { borderColor: 'rgba(0,0,0,.08)' },
            }).render();

            if (byType.some((t) => t.total > 0)) {
                new ApexCharts(document.getElementById('records-by-type-chart'), {
                    chart: { type: 'donut', height: 240 },
                    colors: [purple, '#03c3ec', '#71dd37', '#ffab00', '#ff3e1d', '#8592a3', '#20c997', '#8e44ad'],
                    labels: byType.map((t) => t.label),
                    series: byType.map((t) => t.total),
                    legend: { position: 'bottom' },
                }).render();
            }
        });
    </script>
@endpush
