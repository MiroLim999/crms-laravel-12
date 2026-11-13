@extends('layouts.app')

@section('title', 'Correct Record')

@section('content')
    <main class="change-request-create" data-change-request-form>
        <x-page-header title="Correct Record" :subtitle="$recordHeading">
            <a href="{{ route('records.show', $record) }}" class="btn btn-outline-secondary">
                Back to record
            </a>
        </x-page-header>

        <div class="alert alert-info change-request-guidance" role="alert">
            <i class="icon-base bx bx-info-circle icon-md" aria-hidden="true"></i>
            <div>
                <strong>Saving updates the record right away.</strong>
                Change only the wrong values. The old values stay in the record's Change history and the Audit Log.
            </div>
        </div>

        <section class="record-summary-strip change-request-record-summary" aria-label="Record summary">
            @if ($personCount > 0)
                <div class="record-summary-item">
                    <span>People</span>
                    <strong>{{ $personCount }}</strong>
                    <small>Grouped registry entries</small>
                </div>
            @endif
            <div class="record-summary-item">
                <span>Verified fields</span>
                <strong>{{ $record->fields->count() }}</strong>
                <small>Current saved values</small>
            </div>
            <div class="record-summary-item change-request-live-summary">
                <span>Changes</span>
                <strong data-change-count>0</strong>
                <small data-change-count-label>No values changed</small>
            </div>
        </section>

        <form method="POST" action="{{ route('records.change-requests.store', $record) }}" data-submit-once>
            @csrf

            <div class="change-request-create-grid">
                <section class="change-request-editor" aria-labelledby="proposed-values-heading">
                    <div class="change-request-editor__header">
                        <div>
                            <h5 id="proposed-values-heading">Values</h5>
                            <p>Each current value is shown beside a box for the right value.</p>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-change-reset>
                            Reset values
                        </button>
                    </div>

                    <div class="change-request-group-list">
                        @foreach ($fieldGroups as $group)
                            <details class="record-field-group change-request-field-group"
                                     data-change-group
                                     @if ($loop->first) open @endif>
                                <summary class="record-field-group__summary">
                                    <span class="record-field-group__number">
                                        {{ $group['kind'] === 'person' ? str($group['label'])->after('Person ') : 'i' }}
                                    </span>
                                    <span class="record-field-group__copy">
                                        <strong>{{ $group['label'] }}</strong>
                                        <small>{{ $group['identity'] ?? $group['field_count'].' verified field(s)' }}</small>
                                    </span>
                                    <span class="record-field-group__meta">
                                        <span class="badge bg-label-secondary" data-change-group-count>0 changed</span>
                                    </span>
                                    <i class="icon-base bx bx-chevron-down" aria-hidden="true"></i>
                                </summary>

                                <div class="record-field-group__body">
                                    @foreach ($group['fields'] as $field)
                                        <div class="change-request-editor-row" data-change-row>
                                            <div class="change-request-editor-label">
                                                <strong>{{ $field->name }}</strong>
                                                <small>{{ $field->is_required ? 'Required' : 'Optional' }}</small>
                                            </div>
                                            <div class="change-request-current-value">
                                                <span>Current</span>
                                                <strong>{{ filled($field->verified_value) ? $field->verified_value : 'Not recorded' }}</strong>
                                            </div>
                                            <div>
                                                <label for="field-{{ $field->getKey() }}" class="form-label">Correct value</label>
                                                <input type="text"
                                                       id="field-{{ $field->getKey() }}"
                                                       name="values[{{ $field->getKey() }}]"
                                                       value="{{ old('values.' . $field->getKey(), $field->verified_value) }}"
                                                       maxlength="2000"
                                                       data-change-input data-current-value="{{ $field->verified_value }}"
                                                       class="form-control @error('values.' . $field->getKey()) is-invalid @enderror"
                                                       @required($field->is_required)>
                                                @error('values.' . $field->getKey())
                                                    <div class="invalid-feedback">{{ $message }}</div>
                                                @enderror
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endforeach
                    </div>
                </section>

                <aside class="change-request-submit-panel">
                    <x-card title="Save correction"
                            subtitle="A reason is optional, but helps later readers of the history.">
                        <label for="change-request-reason" class="form-label">Reason <span class="text-muted">(optional)</span></label>
                        <textarea id="change-request-reason" name="reason" rows="5"
                                  maxlength="2000"
                                  class="form-control @error('reason') is-invalid @enderror"
                                  placeholder="For example: the name was misread; the original page says Reyes.">{{ old('reason') }}</textarea>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror

                        <div class="change-request-submit-panel__status" aria-live="polite">
                            <span class="badge bg-label-secondary" data-change-ready-badge>No changes selected</span>
                        </div>

                        <button type="submit" class="btn btn-primary w-100" data-change-submit>
                            <i class="icon-base bx bx-save icon-sm me-1" aria-hidden="true"></i>
                            Save correction
                        </button>
                        <a href="{{ route('records.show', $record) }}" class="btn btn-outline-secondary w-100 mt-2">
                            Cancel
                        </a>
                    </x-card>
                </aside>
            </div>
        </form>
    </main>
@endsection

@push('scripts')
    @vite('resources/js/change-request.js')
@endpush
