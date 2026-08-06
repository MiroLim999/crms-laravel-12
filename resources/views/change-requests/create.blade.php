@extends('layouts.app')

@section('title', 'Request a Change')

@section('content')
    <x-page-header title="Request a Change"
                   :subtitle="'Record #' . $record->getKey() . ' · ' . $record->typeLabel()" />

    <div class="alert alert-info d-flex align-items-start" role="alert">
        <i class="icon-base bx bx-info-circle icon-md me-2"></i>
        <div>
            Edit only the values that need correcting. An Admin reviews the proposal, and
            approving it is what applies the change — the record stays as it is until then.
        </div>
    </div>

    <form method="POST" action="{{ route('records.change-requests.store', $record) }}">
        @csrf

        <div class="row g-4">
            <div class="col-lg-8">
                <x-card title="Proposed values">
                    @foreach ($record->fields as $field)
                        <div class="mb-3">
                            <label for="field-{{ $field->getKey() }}" class="form-label">
                                {{ $field->name }}
                            </label>
                            <input type="text"
                                   id="field-{{ $field->getKey() }}"
                                   name="values[{{ $field->getKey() }}]"
                                   value="{{ old('values.' . $field->getKey(), $field->verified_value) }}"
                                   class="form-control">
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
