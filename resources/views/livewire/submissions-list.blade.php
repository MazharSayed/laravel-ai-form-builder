<div class="p-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold">{{ $form->title }} — Submissions</h2>
        <a href="{{ route('submissions.export', $form) }}" class="bg-gray-800 text-white text-sm px-4 py-2 rounded hover:bg-gray-700">
            Export CSV
        </a>
    </div>

    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search submissions…"
        class="border rounded px-3 py-2 mb-4 w-full max-w-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">

    <div class="submissions-scroll bg-white rounded-lg shadow" wire:ignore.self wire:key="submissions-table-wrap">
        <table id="submissionsTable" class="min-w-full text-sm display" style="width:100%">
            <thead>
                <tr>
                    <th>Submitted</th>
                    @foreach ($fieldKeys as $key)
                        <th>{{ ucwords(str_replace('_', ' ', $key)) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($submissions as $submission)
                    <tr>
                        <td class="whitespace-nowrap">{{ $submission->created_at->format('Y-m-d H:i') }}</td>
                        @foreach ($fieldKeys as $key)
                            <td>
                                @php($v = $submission->data[$key] ?? '')
                                @if (in_array($key, $fileFieldKeys) && $v)
                                    <a href="{{ route('submissions.download-file', [$form, $submission, $key]) }}"
                                    target="_blank"
                                    class="text-indigo-600 hover:text-indigo-800 underline">
                                        View / Download
                                    </a>
                                @else
                                    {{ is_array($v) ? implode(', ', $v) : $v }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($fieldKeys) + 1 }}" class="text-gray-400 text-center py-6">No submissions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $submissions->links() }}</div>

    @once
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-dt@2.1.8/css/dataTables.dataTables.min.css">
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/datatables.net@2.1.8/js/dataTables.min.js"></script>

        <style>
            .submissions-scroll {
                overflow-x: auto;
                border: 1px solid #e5e7eb;
            }
            .submissions-scroll::-webkit-scrollbar {
                height: 10px;
            }
            .submissions-scroll::-webkit-scrollbar-track {
                background: #f3f4f6;
            }
            .submissions-scroll::-webkit-scrollbar-thumb {
                background: #a5b4fc;
                border-radius: 9999px;
            }
            .submissions-scroll::-webkit-scrollbar-thumb:hover {
                background: #818cf8;
            }
            #submissionsTable {
                border-collapse: collapse;
            }
            #submissionsTable thead th {
                background: #f9fafb;
                text-align: left;
                padding: 0.75rem 1rem;
                font-weight: 600;
                color: #374151;
                border-bottom: 2px solid #e5e7eb;
                white-space: nowrap;
            }
            #submissionsTable {
                table-layout: auto;
            }
            #submissionsTable thead th {
                background: #f9fafb;
                text-align: left;
                vertical-align: middle;
                padding: 0.75rem 1rem;
                font-weight: 600;
                color: #374151;
                border-bottom: 2px solid #e5e7eb;
                white-space: nowrap;
                line-height: 1.4;
            }
            #submissionsTable tbody td {
                padding: 0.75rem 1rem;
                vertical-align: middle;
                border-bottom: 1px solid #f3f4f6;
                color: #1f2937;
                line-height: 1.4;
                white-space: nowrap;
            }
        </style>
    @endonce

    <script>
        function initSubmissionsTable() {
            const el = document.getElementById('submissionsTable');
            if (!el || !window.jQuery) return;

            if (jQuery.fn.DataTable.isDataTable(el)) {
                jQuery(el).DataTable().destroy();
            }

            jQuery(el).DataTable({
                paging: false,
                searching: false,
                info: false,
                order: [],
            });
        }

        document.addEventListener('livewire:navigated', initSubmissionsTable);
        document.addEventListener('DOMContentLoaded', initSubmissionsTable);
        Livewire.hook('morph.updated', ({ el }) => {
            if (el.id === 'submissionsTable' || el.querySelector?.('#submissionsTable')) {
                initSubmissionsTable();
            }
        });
    </script>
</div>
