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
    {{ $slot }}
    @livewireScripts
</body>
</html>
