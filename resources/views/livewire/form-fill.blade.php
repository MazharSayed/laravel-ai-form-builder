<div class="max-w-2xl mx-auto py-10 px-4">
    @if ($submitted)
        <div class="bg-green-50 border border-green-200 rounded-lg p-6 text-center">
            <h2 class="text-lg font-semibold text-green-800">Thanks — your response was recorded.</h2>
        </div>
    @else
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
            <h1 class="text-2xl font-bold text-gray-900 mb-1">{{ $form->title }}</h1>
            @if ($form->description)
                <p class="text-gray-500 mb-8">{{ $form->description }}</p>
            @endif

            <form wire:submit="submit" class="space-y-8">
                @foreach ($form->schema['sections'] as $section)
                    <div>
                        <h2 class="font-semibold text-lg text-gray-800 border-b border-gray-200 pb-2 mb-4">{{ $section['title'] }}</h2>
                        @if (!empty($section['description']))
                            <p class="text-sm text-gray-500 mb-4">{{ $section['description'] }}</p>
                        @endif

                        @foreach ($section['fields'] as $field)
                            @if ($field['type'] === 'section_heading')
                                <h3 class="font-medium text-gray-700 mt-6 mb-3">{{ $field['label'] }}</h3>
                            @elseif ($this->isFieldVisible($field))
                                <div class="mb-5" wire:key="field-{{ $field['id'] }}">
                                    <label class="block text-sm font-medium text-gray-700 mb-1.5">
                                        {{ $field['label'] }}
                                        @if($field['required'] ?? false)<span class="text-red-500">*</span>@endif
                                    </label>

                                    @if (!empty($field['help_text']))
                                        <p class="text-xs text-gray-400 mb-1.5">{{ $field['help_text'] }}</p>
                                    @endif

                                    @php
                                        $inputClass = 'w-full border border-gray-300 rounded-lg px-3.5 py-2.5 text-gray-900 transition
                                                       focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400
                                                       placeholder:text-gray-400';
                                    @endphp

                                    @switch($field['type'])
                                        @case('textarea')
                                            <textarea wire:model.live="values.{{ $field['key'] }}"
                                                placeholder="{{ $field['placeholder'] }}"
                                                rows="4"
                                                class="{{ $inputClass }}"></textarea>
                                            @break

                                        @case('dropdown')
                                            <select wire:model.live="values.{{ $field['key'] }}" class="{{ $inputClass }} bg-white">
                                                <option value="">— Select —</option>
                                                @foreach ($field['options'] as $opt)
                                                    <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                @endforeach
                                            </select>
                                            @break

                                        @case('radio')
                                            <div class="space-y-2">
                                                @foreach ($field['options'] as $opt)
                                                    <label class="flex items-center gap-2.5 text-gray-700 cursor-pointer">
                                                        <input type="radio" wire:model.live="values.{{ $field['key'] }}" value="{{ $opt['value'] }}"
                                                            class="w-4 h-4 text-indigo-600 focus:ring-indigo-400">
                                                        {{ $opt['label'] }}
                                                    </label>
                                                @endforeach
                                            </div>
                                            @break

                                        @case('checkbox')
                                            <div class="space-y-2">
                                                @foreach ($field['options'] as $opt)
                                                    <label class="flex items-center gap-2.5 text-gray-700 cursor-pointer">
                                                        <input type="checkbox" wire:model.live="values.{{ $field['key'] }}" value="{{ $opt['value'] }}"
                                                            class="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-400">
                                                        {{ $opt['label'] }}
                                                    </label>
                                                @endforeach
                                            </div>
                                            @break

                                        @case('file')
                                            <input type="file" wire:model="values.{{ $field['key'] }}"
                                                class="w-full text-sm text-gray-600
                                                       file:mr-4 file:py-2.5 file:px-4 file:rounded-lg file:border-0
                                                       file:bg-indigo-50 file:text-indigo-700 file:font-medium
                                                       hover:file:bg-indigo-100 border border-gray-300 rounded-lg
                                                       focus:outline-none focus:ring-2 focus:ring-indigo-400">
                                            @break

                                        @case('rating')
                                            <input type="number" wire:model.live="values.{{ $field['key'] }}"
                                                min="{{ $field['validation']['min'] ?? 1 }}" max="{{ $field['validation']['max'] ?? 5 }}"
                                                class="{{ $inputClass }} w-24">
                                            @break

                                        @default
                                            <input type="{{ $field['type'] === 'number' ? 'number' : ($field['type'] === 'date' ? 'date' : 'text') }}"
                                                wire:model.live="values.{{ $field['key'] }}"
                                                placeholder="{{ $field['placeholder'] }}"
                                                class="{{ $inputClass }}">
                                    @endswitch

                                    @if (!empty($errors2[$field['key']]))
                                        <p class="text-xs text-red-500 mt-1.5">{{ $errors2[$field['key']][0] }}</p>
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endforeach

                <button type="submit"
                    class="w-full bg-indigo-600 text-white font-medium rounded-lg px-4 py-3 transition
                           hover:bg-indigo-700 active:bg-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-2">
                    Submit
                </button>
            </form>
        </div>
    @endif
</div>
