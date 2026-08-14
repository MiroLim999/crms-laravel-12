@extends('layouts.app')

@section('title', 'Request a Change')

@section('content')
    <x-page-header title="Request a Change"
                   :subtitle="'Record #' . $record->getKey() . ' · ' . $record->typeLabel()" />

    <div class="alert alert-info d-flex align-items-start" role="alert">
        <i class="icon-base bx bx-info-circle icon-md me-2"></i>
        <div>
            Edit only the registry number or values that need correcting. An Admin reviews the proposal, and
            approving it is what applies the change — the record stays as it is until then.
        </div>
    </div>

    <form method="POST" action="{{ route('records.change-requests.store', $record) }}">
        @csrf

        <div class="row g-4">
            <div class="col-lg-8">
                <x-card title="Proposed values">
                    <div class="mb-4">
                        <label for="registry-number" class="form-label">Registry number</label>
                        <input type="text"
                               id="registry-number"
                               name="registry_number"
                               value="{{ old('registry_number', $record->registry_number) }}"
                               maxlength="64"
                               class="form-control @error('registry_number') is-invalid @enderror">
                        @error('registry_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            On record: <strong>{{ $record->registry_number ?: '—' }}</strong>
                        </div>
                    </div>

                    @foreach ($record->fields as $field)
                        <div class="mb-3">
                            <label for="field-{{ $field->getKey() }}" class="form-label">
                                {{ $field->name }}
                                @if ($field->is_required)<span class="text-danger" aria-label="required">*</span>@endif
                            </label>
                            <input type="text"
                                   id="field-{{ $field->getKey() }}"
                                   name="values[{{ $field->getKey() }}]"
                                   value="{{ old('values.' . $field->getKey(), $field->verified_value) }}"
                                   class="form-control @error('values.' . $field->getKey()) is-invalid @enderror"
                                   @required($field->is_required)>
                            @error('values.' . $field->getKey())<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                On record: <strong>{{ $field->verified_value ?: '—' }}</strong>
                            </div>
                        </div>
                    @endforeach
                </x-card>
            </div>

            <div class="col-lg-4">
                <x-card title="Why this change?" class="mb-4">
                    <textarea name="reason" rows="5"
                              class="form-control @error('reason') is-invalid @enderror"
                              placeholder="What is wrong, and what is your source for the correction?"
                              required>{{ old('reason') }}</textarea>
                    @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">
                        The reviewer sees only this. Give them enough to decide without asking.
                    </div>
                </x-card>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary">Submit for review</button>
                    <a href="{{ route('records.show', $record) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </div>
    </form>
@endsection
