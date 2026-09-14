<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-stone-50 font-sans text-stone-900 antialiased">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-10 focus:rounded focus:bg-white focus:p-3 focus:ring-2 focus:ring-emerald-700">Skip to content</a>
        <div class="mx-auto flex min-h-screen max-w-5xl flex-col px-6 sm:px-10">
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-stone-200 py-7">
                <a href="{{ route('home') }}" class="flex items-center gap-3 rounded text-lg font-semibold tracking-tight focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-700">
                    <span aria-hidden="true" class="flex size-9 items-center justify-center rounded-xl bg-emerald-800 text-white">✓</span>
                    Little list
                </a>
                @auth
                    <div class="flex items-center gap-4 text-sm">
                        <span class="max-w-40 truncate text-stone-600">{{ auth()->user()->name }}</span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="rounded font-medium text-emerald-800 underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4">Log out</button>
                        </form>
                    </div>
                @else
                    <span class="text-sm text-stone-500">One thing at a time.</span>
                @endauth
            </header>
            <main id="main-content" class="flex flex-1 items-center justify-center py-14 sm:py-20">
                {{ $slot }}
            </main>
            <footer class="border-t border-stone-200 py-6 text-center text-xs text-stone-500">Small steps count.</footer>
        </div>
        @livewireScripts
    </body>
</html>
