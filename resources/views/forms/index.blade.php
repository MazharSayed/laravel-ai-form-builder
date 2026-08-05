<x-layouts.app>
    <div class="max-w-4xl mx-auto py-10 px-4">
        <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Your forms</h1>
            <form method="POST" action="{{ route('forms.store') }}" class="flex gap-2">
                @csrf
                <input type="text" name="title" placeholder="New form title" required
                    class="border border-gray-300 rounded-lg px-3.5 py-2.5 text-sm w-56
                           focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400">
                <button class="bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-medium
                               rounded-lg px-5 py-2.5 text-sm transition">
                    Create
                </button>
            </form>
        </div>

        <form method="POST" action="{{ route('forms.ai-generate') }}" class="flex gap-2 mb-6">
            @csrf
            <input type="text" name="prompt" placeholder="Describe a form, e.g. internship application with resume upload"
                required
                class="border border-gray-300 rounded-lg px-3.5 py-2.5 text-sm flex-1
                       focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:border-emerald-400">
            <button class="bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-medium
                           rounded-lg px-5 py-2.5 text-sm transition whitespace-nowrap">
                ✨ Generate with AI
            </button>
        </form>

        @if (session('status'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-lg px-4 py-3 mb-6">
                {{ session('status') }}
            </div>
        @endif

        @if ($forms->isEmpty())
            <div class="border border-dashed border-gray-300 rounded-xl p-12 text-center text-gray-400">
                No forms yet — create one above or generate one with AI.
            </div>
        @else
            <div class="space-y-3">
                @foreach ($forms as $form)
                    @php
                        $statusStyles = [
                            'draft' => 'bg-gray-100 text-gray-600',
                            'published' => 'bg-green-100 text-green-700',
                            'archived' => 'bg-yellow-100 text-yellow-700',
                        ];
                    @endphp
                    <div class="flex justify-between items-center border border-gray-200 rounded-xl p-4 bg-white
                                shadow-sm hover:shadow-md hover:border-gray-300 transition">
                        <div class="flex items-center gap-3">
                            <span class="font-medium text-gray-900">{{ $form->title }}</span>
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full {{ $statusStyles[$form->status] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ ucfirst($form->status) }}
                            </span>
                        </div>
                        <div class="flex gap-4 text-sm">
                            <a href="{{ route('forms.builder', $form) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">Edit</a>
                            <a href="{{ route('submissions.index', $form) }}" class="text-gray-500 hover:text-gray-800">Submissions</a>
                            @if ($form->status === 'published')
                                <a href="{{ $form->publicUrl() }}" target="_blank" class="text-gray-500 hover:text-gray-800">Public link</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="mt-6">{{ $forms->links() }}</div>
    </div>
</x-layouts.app>
