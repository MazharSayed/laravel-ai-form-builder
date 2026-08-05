<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'AI Form Builder' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    @livewireStyles
</head>
<body class="bg-gray-100 min-h-screen">
    <nav class="bg-white border-b px-6 py-3 flex items-center gap-4 text-sm">
        <a href="{{ route('forms.index') }}" class="font-semibold text-indigo-600">← Your forms</a>
        @if (request()->routeIs('forms.builder') || request()->routeIs('submissions.index'))
            <span class="text-gray-300">|</span>
            <a href="javascript:history.back()" class="text-gray-500 hover:text-gray-800">← Back</a>
        @endif
    </nav>

    {{ $slot }}
    @livewireScripts
</body>
</html>
