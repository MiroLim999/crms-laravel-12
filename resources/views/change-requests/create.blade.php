@extends('layouts.app')

@section('title', 'Request a Change')

@section('content')
    <main class="change-request-create" data-change-request-form>
        <x-page-header title="Request a Change" :subtitle="$recordHeading">
            <a href="{{ route('records.show', $record) }}" class="btn btn-outline-secondary">
                Back to record
            </a>
        </x-page-header>

        <div class="alert alert-info change-request-guidance" role="alert">
            <i class="icon-base bx bx-info-circle icon-md" aria-hidden="true"></i>
            <div>
                <strong>The verified record remains locked.</strong>
                Change only incorrect values and explain the evidence. Nothing is updated until an Admin approves the request.
            </div>
        </div>

        <section class="record-summary-strip change-request-record-summary" aria-label="Record summary">
            <div class="record-summary-item {{ $record->registry_number ? '' : 'is-warning' }}">
                <span>Registry number</span>
                <strong>{{ $record->registry_number ?? 'Not recorded' }}</strong>
            </div>
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
                <small>Current locked values</small>
            </div>
            <div class="record-summary-item change-request-live-summary">
                <span>Proposed changes</span>
                <strong data-change-count>0</strong>
                <small data-change-count-label>No values changed</small>
            </div>
        </section>

        <form method="POST" action="{{ route('records.change-requests.store', $record) }}">
            @csrf

            <div class="change-request-create-grid">
                <section class="change-request-editor" aria-labelledby="proposed-values-heading">
                    <div class="change-request-editor__header">
                        <div>
                            <h5 id="proposed-values-heading">Proposed values</h5>
                            <p>Current values appear beside editable proposals for a direct comparison.</p>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-change-reset>
                            Reset values
                        </button>
                    </div>

                    <div class="change-request-registry-row" data-change-row>
                        <div class="change-request-editor-label">
                            <strong>Registry number</strong>
                            <small>Document detail</small>
                        </div>
                        <div class="change-request-current-value">
                            <span>Current</span>
                            <strong>{{ $record->registry_number ?: 'Not recorded' }}</strong>
                        </div>
                        <div>
                            <label for="registry-number" class="form-label">Proposed value</label>
                            <input type="text" id="registry-number" name="registry_number"
                                   value="{{ old('registry_number', $record->registry_number) }}"
                                   maxlength="64"
                                   data-change-input data-current-value="{{ $record->registry_number }}"
                                   class="form-control @error('registry_number') is-invalid @enderror">
                            @error('registry_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
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
                                                <label for="field-{{ $field->getKey() }}" class="form-label">Proposed value</label>
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
                    <x-card title="Reason for correction"
                            subtitle="Give the reviewer enough context to verify your proposal.">
                        <label for="change-request-reason" class="form-label">Explanation</label>
                        <textarea id="change-request-reason" name="reason" rows="7"
                                  maxlength="2000"
                                  class="form-control @error('reason') is-invalid @enderror"
                                  placeholder="What is incorrect, and what in the original document confirms the proposed value?"
                                  required>{{ old('reason') }}</textarea>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Minimum 10 characters.</div>

                        <div class="change-request-submit-panel__status" aria-live="polite">
                            <span class="badge bg-label-secondary" data-change-ready-badge>No changes selected</span>
                        </div>

                        <button type="submit" class="btn btn-primary w-100" data-change-submit>
                            <i class="icon-base bx bx-git-pull-request icon-sm me-1" aria-hidden="true"></i>
                            Submit for review
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
