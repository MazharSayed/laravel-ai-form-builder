<div class="grid grid-cols-3 gap-6 p-6" x-data="{}">
    {{-- Field palette --}}
    <div class="col-span-1 border rounded-lg p-4 bg-white">
        <h3 class="font-semibold mb-3">Add field</h3>
        <div class="grid grid-cols-2 gap-2">
            @foreach (['text','textarea','number','email','phone','date','dropdown','radio','checkbox','file','rating','section_heading'] as $type)
                <button
                    wire:click="addField('{{ $schema['sections'][0]['id'] ?? '' }}', '{{ $type }}')"
                    class="text-xs border rounded px-2 py-2 hover:bg-gray-50 capitalize"
                >{{ str_replace('_',' ', $type) }}</button>
            @endforeach
        </div>

        <button wire:click="addSection" class="mt-4 w-full text-sm border rounded px-2 py-2 hover:bg-gray-50">
            + Add section
        </button>

        <hr class="my-4">

        <h3 class="font-semibold mb-2">AI edit</h3>
        <textarea wire:model="aiInstruction" rows="3" class="w-full border rounded p-2 text-sm"
            placeholder="e.g. add an emergency contact section"></textarea>
        <button wire:click="requestAiEdit" wire:loading.attr="disabled"
            class="mt-2 w-full bg-indigo-600 text-white rounded px-2 py-2 text-sm">
            <span wire:loading.remove wire:target="requestAiEdit">Apply AI edit</span>
            <span wire:loading wire:target="requestAiEdit">Queuing…</span>
        </button>
        @if ($aiTrackingId)
            <div wire:poll.2s="checkAiStatus" class="text-xs text-gray-500 mt-2">
                AI edit in progress — this page will refresh automatically.
            </div>
        @endif
    </div>

    {{-- Canvas --}}
    <div class="col-span-1 border rounded-lg p-4 bg-white overflow-y-auto max-h-[80vh]">
        <h3 class="font-semibold mb-3">Canvas</h3>

        @foreach ($schema['sections'] as $section)
            <div class="mb-6 border rounded p-3">
                <input type="text" value="{{ $section['title'] }}"
                    wire:change="updateFieldProperty('{{ $section['id'] }}', '', 'title', $event.target.value)"
                    class="font-medium w-full border-b pb-1 mb-2" />

                <ul
                    id="fields-{{ $section['id'] }}"
                    class="space-y-2 sortable-fields"
                    data-section="{{ $section['id'] }}"
                >
                    @foreach ($section['fields'] as $field)
                        <li data-id="{{ $field['id'] }}"
                            wire:click="$set('activeFieldId', '{{ $field['id'] }}')"
                            class="flex items-center justify-between border rounded px-2 py-2 cursor-move
                                   {{ $activeFieldId === $field['id'] ? 'ring-2 ring-indigo-400' : '' }}">
                            <span class="text-sm">
                                <span class="text-gray-400 text-xs uppercase mr-2">{{ $field['type'] }}</span>
                                {{ $field['label'] }}
                                @if($field['required'] ?? false)<span class="text-red-500">*</span>@endif
                            </span>
                            <span class="flex gap-1">
                                <button wire:click.stop="duplicateField('{{ $section['id'] }}', '{{ $field['id'] }}')" title="Duplicate">⧉</button>
                                <button wire:click.stop="deleteField('{{ $section['id'] }}', '{{ $field['id'] }}')" title="Delete">✕</button>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach

        @php($active = $this->getActiveFieldProperty())
        @if ($active)
            <div class="border-t pt-4 mt-4 text-sm space-y-3" wire:key="props-{{ $active['id'] }}">
                <h4 class="font-semibold">Field properties — {{ $active['type'] }}</h4>

                <label class="block">
                    <span class="text-xs text-gray-500">Label</span>
                    <input type="text" value="{{ $active['label'] }}"
                        wire:change="updateFieldProperty('{{ $active['section_id'] }}', '{{ $active['id'] }}', 'label', $event.target.value)"
                        class="w-full border rounded px-2 py-1">
                </label>

                <label class="block">
                    <span class="text-xs text-gray-500">Key (submission data key)</span>
                    <input type="text" value="{{ $active['key'] }}"
                        wire:change="updateFieldProperty('{{ $active['section_id'] }}', '{{ $active['id'] }}', 'key', $event.target.value)"
                        class="w-full border rounded px-2 py-1 font-mono text-xs">
                </label>

                <label class="block">
                    <span class="text-xs text-gray-500">Placeholder</span>
                    <input type="text" value="{{ $active['placeholder'] }}"
                        wire:change="updateFieldProperty('{{ $active['section_id'] }}', '{{ $active['id'] }}', 'placeholder', $event.target.value)"
                        class="w-full border rounded px-2 py-1">
                </label>

                <label class="block">
                    <span class="text-xs text-gray-500">Help text</span>
                    <input type="text" value="{{ $active['help_text'] }}"
                        wire:change="updateFieldProperty('{{ $active['section_id'] }}', '{{ $active['id'] }}', 'help_text', $event.target.value)"
                        class="w-full border rounded px-2 py-1">
                </label>

                <label class="flex items-center gap-2">
                    <input type="checkbox" {{ ($active['required'] ?? false) ? 'checked' : '' }}
                        wire:change="updateFieldProperty('{{ $active['section_id'] }}', '{{ $active['id'] }}', 'required', $event.target.checked)">
                    <span class="text-xs text-gray-500">Required</span>
                </label>

                @if (in_array($active['type'], ['dropdown','radio','checkbox']))
                    <div>
                        <span class="text-xs text-gray-500">Options</span>
                        @foreach (($active['options'] ?? []) as $i => $opt)
                            <div class="flex gap-1 mt-1">
                                <input type="text" value="{{ $opt['label'] }}" placeholder="Label"
                                    wire:change="updateOption('{{ $active['section_id'] }}', '{{ $active['id'] }}', {{ $i }}, 'label', $event.target.value)"
                                    class="flex-1 border rounded px-2 py-1 text-xs">
                                <input type="text" value="{{ $opt['value'] }}" placeholder="Value"
                                    wire:change="updateOption('{{ $active['section_id'] }}', '{{ $active['id'] }}', {{ $i }}, 'value', $event.target.value)"
                                    class="flex-1 border rounded px-2 py-1 text-xs font-mono">
                                <button wire:click="removeOption('{{ $active['section_id'] }}', '{{ $active['id'] }}', {{ $i }})" class="text-red-500">✕</button>
                            </div>
                        @endforeach
                        <button wire:click="addOption('{{ $active['section_id'] }}', '{{ $active['id'] }}')"
                            class="mt-2 text-xs border rounded px-2 py-1">+ Add option</button>
                    </div>
                @endif

                @if (in_array($active['type'], ['text','textarea']))
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="text-xs text-gray-500">Min length</span>
                            <input type="number" value="{{ $active['validation']['min_length'] ?? '' }}"
                                wire:change="updateValidationRule('{{ $active['section_id'] }}', '{{ $active['id'] }}', 'min_length', $event.target.value)"
                                class="w-full border rounded px-2 py-1">
                        </label>
                        <label class="block">
                            <span class="text-xs text-gray-500">Max length</span>
                            <input type="number" value="{{ $active['validation']['max_length'] ?? '' }}"
                                wire:change="updateValidationRule('{{ $active['section_id'] }}', '{{ $active['id'] }}', 'max_length', $event.target.value)"
                                class="w-full border rounded px-2 py-1">
                        </label>
                    </div>
                @endif

                @if (in_array($active['type'], ['number','rating']))
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="text-xs text-gray-500">Min</span>
                            <input type="number" value="{{ $active['validation']['min'] ?? '' }}"
                                wire:change="updateValidationRule('{{ $active['section_id'] }}', '{{ $active['id'] }}', 'min', $event.target.value)"
                                class="w-full border rounded px-2 py-1">
                        </label>
                        <label class="block">
                            <span class="text-xs text-gray-500">Max</span>
                            <input type="number" value="{{ $active['validation']['max'] ?? '' }}"
                                wire:change="updateValidationRule('{{ $active['section_id'] }}', '{{ $active['id'] }}', 'max', $event.target.value)"
                                class="w-full border rounded px-2 py-1">
                        </label>
                    </div>
                @endif

                @if ($active['type'] === 'file')
                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="text-xs text-gray-500">Allowed types (comma-separated)</span>
                            <input type="text" value="{{ implode(',', $active['validation']['allowed_types'] ?? []) }}"
                                wire:change="updateValidationRule('{{ $active['section_id'] }}', '{{ $active['id'] }}', 'allowed_types', $event.target.value.split(','))"
                                class="w-full border rounded px-2 py-1">
                        </label>
                        <label class="block">
                            <span class="text-xs text-gray-500">Max size (KB)</span>
                            <input type="number" value="{{ $active['validation']['max_size_kb'] ?? '' }}"
                                wire:change="updateValidationRule('{{ $active['section_id'] }}', '{{ $active['id'] }}', 'max_size_kb', $event.target.value)"
                                class="w-full border rounded px-2 py-1">
                        </label>
                    </div>
                @endif

                <p class="text-xs text-gray-400">Changes apply instantly — check the JSON panel to confirm.</p>
            </div>
        @endif
    </div>

    {{-- Raw JSON editor (two-way synced source of truth) --}}
    <div class="col-span-1 border rounded-lg p-4 bg-gray-900">
        <div class="flex justify-between items-center mb-2">
            <h3 class="font-semibold text-white">JSON schema</h3>
            <button wire:click="save" class="bg-green-600 text-white text-xs px-3 py-1 rounded">Save</button>
        </div>
        <textarea
            wire:model.live.debounce.500ms="jsonEditorText"
            class="w-full h-[65vh] bg-gray-900 text-green-300 font-mono text-xs p-2 rounded"
            spellcheck="false"
        ></textarea>
        @if (!empty($jsonErrors))
            <div class="mt-2 text-red-400 text-xs">
                @foreach ($jsonErrors as $err)
                    <div>⚠ {{ $err }}</div>
                @endforeach
            </div>
        @endif
    </div>

    <script>
        document.querySelectorAll('.sortable-fields').forEach((el) => {
            new Sortable(el, {
                animation: 150,
                onEnd: () => {
                    const ids = Array.from(el.children).map(li => li.dataset.id);
                    @this.call('reorderFields', el.dataset.section, ids);
                },
            });
        });
    </script>
</div>
