<div class="p-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold">{{ $form->title }} — Submissions</h2>
        <a href="{{ route('submissions.export', $form) }}" class="bg-gray-800 text-white text-sm px-3 py-2 rounded">
            Export CSV
        </a>
    </div>

    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search submissions…"
        class="border rounded px-3 py-2 mb-4 w-full max-w-sm">

    <div class="overflow-x-auto border rounded">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left px-3 py-2">Submitted</th>
                    @foreach ($fieldKeys as $key)
                        <th class="text-left px-3 py-2">{{ $key }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($submissions as $submission)
                    <tr class="border-t">
                        <td class="px-3 py-2 whitespace-nowrap">{{ $submission->created_at->format('Y-m-d H:i') }}</td>
                        @foreach ($fieldKeys as $key)
                            <td class="px-3 py-2">
                                @php($v = $submission->data[$key] ?? '')
                                {{ is_array($v) ? implode(', ', $v) : $v }}
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td class="px-3 py-4 text-gray-400" colspan="{{ count($fieldKeys) + 1 }}">No submissions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $submissions->links() }}</div>
</div>
