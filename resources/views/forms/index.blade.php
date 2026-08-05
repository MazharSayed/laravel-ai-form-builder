<x-layouts.app>
    <div class="max-w-4xl mx-auto py-10 px-4">
        <div class="flex justify-between items-center mb-4">
            <h1 class="text-2xl font-bold">Your forms</h1>
            <form method="POST" action="{{ route('forms.store') }}" class="flex gap-2">
                @csrf
                <input type="text" name="title" placeholder="New form title" required class="border rounded px-3 py-2">
                <button class="bg-indigo-600 text-white rounded px-4 py-2">Create</button>
            </form>
        </div>

        <form method="POST" action="{{ route('forms.ai-generate') }}" class="flex gap-2 mb-6">
            @csrf
            <input type="text" name="prompt" placeholder="Describe a form, e.g. internship application with resume upload" required class="border rounded px-3 py-2 flex-1">
            <button class="bg-emerald-600 text-white rounded px-4 py-2">Generate with AI</button>
        </form>

        @if (session('status'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded px-3 py-2 mb-4">
                {{ session('status') }}
            </div>
        @endif

        <div class="space-y-2">
            @foreach ($forms as $form)
                <div class="flex justify-between items-center border rounded p-3 bg-white">
                    <div>
                        <span class="font-medium">{{ $form->title }}</span>
                        <span class="text-xs text-gray-400 ml-2">{{ $form->status }}</span>
                    </div>
                    <div class="flex gap-3 text-sm">
                        <a href="{{ route('forms.builder', $form) }}" class="text-indigo-600">Edit</a>
                        <a href="{{ route('submissions.index', $form) }}" class="text-gray-600">Submissions</a>
                        @if ($form->status === 'published')
                            <a href="{{ $form->publicUrl() }}" target="_blank" class="text-gray-600">Public link</a>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $forms->links() }}</div>
    </div>
</x-layouts.app>
