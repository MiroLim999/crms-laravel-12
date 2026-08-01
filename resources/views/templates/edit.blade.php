@extends('layouts.app')

@section('title', $template ? 'Edit Template' : 'New Template')

@section('content')
    <x-page-header :title="$template ? 'Edit: ' . $template->name : 'New ' . $docType->shortLabel() . ' Template'"
                   subtitle="Drag the boxes onto a sample scan. Positions are stored as fractions of the page, so they hold at any resolution.">
        @if ($template && ! $template->is_active)
            <form method="POST" action="{{ route('templates.activate', $template) }}">
                @csrf
                <button class="btn btn-outline-primary" type="submit">Activate</button>
            </form>
        @endif
        <a href="{{ route('templates.index') }}" class="btn btn-outline-secondary">Back</a>
    </x-page-header>

    <form method="POST"
          action="{{ $template ? route('templates.update', $template) : route('templates.store') }}"
          id="templateForm">
        @csrf
        @if ($template) @method('PUT') @endif

        <div class="row g-4">
            <div class="col-lg-4">
                <x-card title="Details" class="mb-4">
                    <div class="mb-3">
                        <label for="name" class="form-label">Template name</label>
                        <input type="text" id="name" name="name"
                               value="{{ old('name', $template?->name ?? $docType->label()) }}"
                               class="form-control @error('name') is-invalid @enderror" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="doc_type" class="form-label">Certificate type</label>
                        <select id="doc_type" name="doc_type" class="form-select" required>
                            @foreach ($documentTypes as $type)
                                <option value="{{ $type->value }}"
                                        @selected(old('doc_type', $docType->value) === $type->value)>
                                    {{ $type->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-0">
                        <label for="description" class="form-label">Notes</label>
                        <textarea id="description" name="description" rows="2" class="form-control"
                                  placeholder="Which form revision this matches, for example.">{{ old('description', $template?->description) }}</textarea>
                    </div>
                </x-card>

                <x-card title="Fields" subtitle="Order here is the order Staff verify in.">
                    <div class="mb-3">
                        <div class="input-group">
                            <input type="text" id="newFieldName" class="form-control"
                                   placeholder="New field name">
                            <button class="btn btn-outline-primary" type="button" id="addFieldBtn">Add</button>
                        </div>
                    </div>

                    <ul class="list-unstyled mb-0" id="fieldList"></ul>

                    @error('fields')
                        <div class="text-danger small mt-2">{{ $message }}</div>
                    @enderror
                </x-card>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-primary">
                        {{ $template ? 'Save Template' : 'Create Template' }}
                    </button>
                </div>
            </div>

            <div class="col-lg-8">
                <x-card title="Layout" subtitle="Load a sample scan to position the boxes against.">
                    <div class="mb-3">
                        <input type="file" id="sampleScan" class="form-control"
                               accept="application/pdf,image/*">
                        <div class="form-text">
                            The sample is only used for positioning in your browser. It is not uploaded or stored.
                        </div>
                    </div>

                    <div class="doc-stage" id="docStage">
                        <canvas id="pageCanvas" width="900" height="1200"></canvas>
                        <div class="field-overlay" id="fieldOverlay"></div>
                    </div>
                </x-card>
            </div>
        </div>

        {{-- Fractional coordinates are serialised here on submit. --}}
        <div id="fieldInputs"></div>
    </form>
@endsection

@push('scripts')
<script type="module">
    import { FieldMarker } from '{{ Vite::asset('resources/js/field-marker.js') }}';

    const initial = @json($fields);

    const canvas = document.getElementById('pageCanvas');
    const overlay = document.getElementById('fieldOverlay');

    // Blank page so boxes are positionable before a sample scan is chosen.
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = '#d9dee3';
    ctx.strokeRect(0.5, 0.5, canvas.width - 1, canvas.height - 1);

    const marker = new FieldMarker({ canvas, overlay, onChange: renderList });

    marker.setBoxes(initial.map((f) => ({
        name: f.name,
        x: Number(f.x),
        y: Number(f.y),
        w: Number(f.width ?? f.w),
        h: Number(f.height ?? f.h),
    })));

    function renderList(boxes) {
        const list = document.getElementById('fieldList');
        list.innerHTML = '';

        boxes.forEach((box, index) => {
            const li = document.createElement('li');
            li.className = 'd-flex align-items-center gap-2 py-1';
            li.innerHTML = `
                <span class="badge bg-label-primary">${index + 1}</span>
                <input type="text" class="form-control form-control-sm" value="">
                <button type="button" class="btn btn-sm btn-icon btn-text-danger" aria-label="Remove field">
                    <i class="icon-base bx bx-trash icon-sm"></i>
                </button>`;

            const input = li.querySelector('input');
            input.value = box.name;
            input.addEventListener('input', () => marker.renameBox(index, input.value));

            li.querySelector('button').addEventListener('click', () => marker.removeBox(index));

            list.appendChild(li);
        });
    }

    document.getElementById('addFieldBtn').addEventListener('click', () => {
        const input = document.getElementById('newFieldName');
        const name = input.value.trim();
        if (!name) return;
        marker.addBox(name);
        input.value = '';
    });

    document.getElementById('sampleScan').addEventListener('change', async (event) => {
        const file = event.target.files?.[0];
        if (file) await marker.load(file);
    });

    // Serialise the boxes into hidden inputs at submit time.
    document.getElementById('templateForm').addEventListener('submit', () => {
        const container = document.getElementById('fieldInputs');
        container.innerHTML = '';

        marker.toJSON().forEach((box, index) => {
            const pairs = {
                name: box.name,
                x: box.x.toFixed(5),
                y: box.y.toFixed(5),
                width: box.w.toFixed(5),
                height: box.h.toFixed(5),
            };

            Object.entries(pairs).forEach(([key, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `fields[${index}][${key}]`;
                input.value = value;
                container.appendChild(input);
            });
        });
    });
</script>
@endpush
