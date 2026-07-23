<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>{{ $windowId }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @livewireStyles
    <style>
        :root { --nb-safe-top: env(safe-area-inset-top, 0px); --nb-safe-bottom: env(safe-area-inset-bottom, 0px); }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; }
    </style>
</head>
<body>
    {{-- The one component this satellite window hosts. livewire.js boots on it;
         its /livewire/update requests are relayed to the main window's runtime.
         The window id is passed as a mount param so the component knows which
         window/conversation it is. --}}
    @livewire($component, ['windowId' => $windowId])

    @livewireScripts
</body>
</html>
