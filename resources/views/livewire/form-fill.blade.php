<div class="max-w-2xl mx-auto py-10 px-4">
    @if ($submitted)
        <div class="bg-green-50 border border-green-200 rounded-lg p-6 text-center">
            <h2 class="text-lg font-semibold text-green-800">Thanks — your response was recorded.</h2>
        </div>
    @else
        <h1 class="text-2xl font-bold mb-1">{{ $form->title }}</h1>
        @if ($form->description)
            <p class="text-gray-500 mb-6">{{ $form->description }}</p>
        @endif

        <form wire:submit="submit" class="space-y-6">
            @foreach ($form->schema['sections'] as $section)
                <div>
                    <h2 class="font-semibold text-lg border-b pb-1 mb-3">{{ $section['title'] }}</h2>
                    @if (!empty($section['description']))
                        <p class="text-sm text-gray-500 mb-3">{{ $section['description'] }}</p>
                    @endif

                    @foreach ($section['fields'] as $field)
                        @if ($field['type'] === 'section_heading')
                            <h3 class="font-medium mt-4 mb-2">{{ $field['label'] }}</h3>
                        @elseif ($this->isFieldVisible($field))
                            <div class="mb-4" wire:key="field-{{ $field['id'] }}">
                                <label class="block text-sm font-medium mb-1">
                                    {{ $field['label'] }}
                                    @if($field['required'] ?? false)<span class="text-red-500">*</span>@endif
                                </label>

                                @if (!empty($field['help_text']))
                                    <p class="text-xs text-gray-400 mb-1">{{ $field['help_text'] }}</p>
                                @endif

                                @switch($field['type'])
                                    @case('textarea')
                                        <textarea wire:model.live="values.{{ $field['key'] }}"
                                            placeholder="{{ $field['placeholder'] }}"
                                            class="w-full border rounded px-3 py-2"></textarea>
                                        @break

                                    @case('dropdown')
                                        <select wire:model.live="values.{{ $field['key'] }}" class="w-full border rounded px-3 py-2">
                                            <option value="">— Select —</option>
                                            @foreach ($field['options'] as $opt)
                                                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                            @endforeach
                                        </select>
                                        @break

                                    @case('radio')
                                        @foreach ($field['options'] as $opt)
                                            <label class="flex items-center gap-2 mb-1">
                                                <input type="radio" wire:model.live="values.{{ $field['key'] }}" value="{{ $opt['value'] }}">
                                                {{ $opt['label'] }}
                                            </label>
                                        @endforeach
                                        @break

                                    @case('checkbox')
                                        @foreach ($field['options'] as $opt)
                                            <label class="flex items-center gap-2 mb-1">
                                                <input type="checkbox" wire:model.live="values.{{ $field['key'] }}" value="{{ $opt['value'] }}">
                                                {{ $opt['label'] }}
                                            </label>
                                        @endforeach
                                        @break

                                    @case('file')
                                        <input type="file" wire:model="values.{{ $field['key'] }}" class="w-full border rounded px-3 py-2">
                                        @break

                                    @case('rating')
                                        <input type="number" wire:model.live="values.{{ $field['key'] }}"
                                            min="{{ $field['validation']['min'] ?? 1 }}" max="{{ $field['validation']['max'] ?? 5 }}"
                                            class="w-24 border rounded px-3 py-2">
                                        @break

                                    @default
                                        <input type="{{ $field['type'] === 'number' ? 'number' : ($field['type'] === 'date' ? 'date' : 'text') }}"
                                            wire:model.live="values.{{ $field['key'] }}"
                                            placeholder="{{ $field['placeholder'] }}"
                                            class="w-full border rounded px-3 py-2">
                                @endswitch

                                @if (!empty($errors2[$field['key']]))
                                    <p class="text-xs text-red-500 mt-1">{{ $errors2[$field['key']][0] }}</p>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>
            @endforeach

            <button type="submit" class="bg-indigo-600 text-white rounded px-4 py-2">Submit</button>
        </form>
    @endif
</div>
