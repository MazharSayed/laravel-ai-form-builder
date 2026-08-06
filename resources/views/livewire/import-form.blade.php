<div class="max-w-3xl mx-auto py-10 px-4">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Import a form from Word or Excel</h1>

    @if ($status === 'idle')
        <div class="bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
            <label class="block text-sm font-medium text-gray-700 mb-2">Upload a .docx or .xlsx file</label>
            <input type="file" wire:model.live="file"
                class="w-full text-sm text-gray-600 file:mr-4 file:py-2.5 file:px-4 file:rounded-lg file:border-0
                    file:bg-indigo-50 file:text-indigo-700 file:font-medium hover:file:bg-indigo-100
                    border border-gray-300 rounded-lg">
            @error('file') <p class="text-xs text-red-500 mt-2">{{ $message }}</p> @enderror
            <p wire:loading wire:target="file" class="mt-3 text-sm text-indigo-600">Uploading & parsing…</p>
            @if (!empty($unparseable))
                <div class="mt-4 text-sm text-red-500 space-y-1">
                    @foreach ($unparseable as $u) <div>⚠ {{ $u }}</div> @endforeach
                </div>
            @endif
        </div>
    @elseif ($status === 'processing')
        <div wire:poll.1500ms="checkStatus" class="bg-white border border-gray-200 rounded-xl p-8 shadow-sm text-center">
            <div class="inline-block w-3 h-3 rounded-full bg-indigo-500 animate-pulse mb-3"></div>
            <p class="text-gray-600">Parsing your file…</p>
        </div>
    @elseif ($status === 'ready')
        <div class="bg-white border border-gray-200 rounded-xl p-6 shadow-sm">
            <div class="flex justify-between items-center mb-4">
                <h2 class="font-semibold text-gray-800">Preview & fix detected fields</h2>
                {{-- <button wire:click="commit"
                    class="bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg px-5 py-2.5 text-sm transition">
                    Commit as form
                </button> --}}
                <form method="POST" action="{{ route('imports.commit', $importJobId) }}">
                    @csrf
                    <input type="hidden" name="schema" value="{{ json_encode($schema) }}">
                    <button type="submit"
                        class="bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg px-5 py-2.5 text-sm transition">
                        Commit as form
                    </button>
                </form>
            </div>

            @if (!empty($unparseable))
                <div class="mb-4 bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-xs text-yellow-800 space-y-1">
                    <p class="font-medium">Parser notes:</p>
                    @foreach ($unparseable as $u) <div>• {{ $u }}</div> @endforeach
                </div>
            @endif

            @foreach ($schema['sections'] as $si => $section)
                <div class="mb-5 border border-gray-200 rounded-lg p-4">
                    <h3 class="font-medium text-gray-800 mb-3">{{ $section['title'] }}</h3>
                    <div class="space-y-2">
                        @foreach ($section['fields'] as $fi => $field)
                            <div class="flex items-center gap-3 text-sm">
                                <span class="flex-1 text-gray-700">{{ $field['label'] }}</span>
                                <select wire:change="setFieldType({{ $si }}, {{ $fi }}, $event.target.value)"
                                    class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-400">
                                    @foreach ($this->fieldTypes() as $t)
                                        <option value="{{ $t }}" {{ $field['type'] === $t ? 'selected' : '' }}>{{ $t }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                        @if (empty($section['fields']))
                            <p class="text-xs text-gray-400">No fields detected in this section.</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @elseif ($status === 'committed')
        <div class="bg-green-50 border border-green-200 rounded-xl p-8 text-center">
            <h2 class="text-lg font-semibold text-green-800 mb-3">✅ Form imported successfully.</h2>
            <a href="{{ route('forms.builder', $committedFormId) }}"
               class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg px-5 py-2.5 text-sm transition">
                Open in builder →
            </a>
        </div>
    @endif
</div>
