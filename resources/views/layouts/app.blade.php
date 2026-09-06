@if($mainFragment ?? false)
<main id="page-content">
    @yield('content')
</main>
@else
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="today-url" content="{{ route('logs.today') }}">

        <title>{{ config('app.name', 'Total Log') }}</title>
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        <script>(()=>{const themes=['light','paper','blue','red','dark'],saved=localStorage.getItem('totallog.theme'),theme=themes.includes(saved)?saved:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=theme;document.documentElement.classList.toggle('dark',theme==='dark'||theme==='red')})()</script>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased text-slate-900 dark:text-slate-100 {{ request()->routeIs('notes.*') ? 'overflow-hidden' : '' }}" data-time-format="{{ auth()->user()->time_format ?? '24' }}" data-demo-readonly="{{ auth()->user()?->is_guest ? 'true' : 'false' }}" data-session-keepalive-url="{{ route('session.keep-alive') }}" data-login-url="{{ route('login') }}" data-screensaver-enabled="{{ auth()->user()?->screensaver_enabled ? 'true' : 'false' }}" data-screensaver-style="{{ auth()->user()?->screensaver_style ?? 'flying-toasters' }}" data-screensaver-wait="{{ auth()->user()?->screensaver_wait_minutes ?? 10 }}" data-screensaver-speed="{{ auth()->user()?->screensaver_speed ?? 1 }}" data-screensaver-message="{{ auth()->user()?->screensaver_message ?? 'OUT TO LUNCH' }}" data-screensaver-logo="{{ auth()->user()?->screensaver_logo_path ? route('settings.screensaver.logo') : asset('screensavers/after-dark/img/logo.png') }}" data-screensaver-base="{{ asset('screensavers/after-dark/all') }}" data-screensaver-starry-night="{{ asset('screensavers/starry-night/index.html') }}">
        <div id="authenticated-app-shell" class="min-h-screen bg-slate-100 dark:bg-slate-950">
            @include('layouts.navigation')
            @if(auth()->user()->is_guest)
                <div id="demo-readonly-banner" class="flex flex-wrap items-center justify-center gap-2 bg-amber-100 px-4 py-2 text-center text-sm font-semibold text-amber-900 dark:bg-amber-950 dark:text-amber-100"><span>Read-only demo · Changes are disabled.</span><form method="POST" action="{{ route('demo.leave') }}">@csrf<button class="underline underline-offset-2">Create an account to save your own log.</button></form></div>
            @endif

            <!-- Page Heading -->
            @hasSection('header')
                <header class="relative z-30 overflow-visible border-b border-slate-200 bg-white/90 shadow-sm dark:border-slate-800 dark:bg-slate-900/90">
                    <div id="page-heading-container" class="max-w-7xl mx-auto overflow-visible py-6 px-4 sm:px-6 lg:px-8">
                        @yield('header')
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main id="page-content">
                @if(session('status'))<div id="application-status-region" class="mx-auto mt-4 max-w-7xl px-4"><div id="application-status-message" class="rounded-xl bg-emerald-100 px-4 py-3 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">{{ session('status') }}</div></div>@endif
                @yield('content')
            </main>
        </div>
        <div id="session-expired-overlay" class="fixed inset-0 hidden place-items-center bg-slate-950/75 p-4" style="z-index:200" data-session-expired-overlay role="alertdialog" aria-modal="true" aria-labelledby="session-expired-title">
            <section class="w-full max-w-md rounded-2xl bg-white p-6 text-center shadow-2xl dark:bg-slate-900">
                <h2 id="session-expired-title" class="text-2xl font-black">Your session has expired</h2>
                <p class="mt-3 text-sm text-slate-500">Sign in again before continuing. Unsynced changes remain visible on this page until you leave it.</p>
                <a class="btn mt-6 w-full justify-center" href="{{ route('login') }}" data-session-login>Sign in again</a>
            </section>
        </div>
        <div id="page-loading-overlay" class="fixed inset-0 hidden place-items-center bg-slate-950/60 p-4 backdrop-blur-sm" style="z-index:190" data-page-loading-overlay role="status" aria-live="polite" aria-label="Loading page">
            <div id="page-loading-card" class="flex flex-col items-center gap-4 rounded-2xl bg-white px-8 py-6 text-center shadow-2xl dark:bg-slate-900">
                <svg class="h-12 w-12 animate-spin text-indigo-600 dark:text-indigo-400" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="4"></circle><path class="opacity-90" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v4a5 5 0 0 1 5 5h4Z"></path></svg>
                <p class="text-sm font-bold">Loading log…</p>
            </div>
        </div>
        <div id="background-sync-status" class="fixed bottom-4 left-4 z-50 hidden rounded-xl bg-slate-900 px-3 py-2 text-xs font-semibold text-white shadow-xl dark:bg-white dark:text-slate-900" data-sync-status role="status" aria-live="polite"></div>
        <div id="screensaver-overlay" class="fixed inset-0 hidden overflow-hidden bg-black" style="z-index:1000" data-screensaver-overlay role="dialog" aria-modal="true" aria-label="Screensaver" tabindex="-1">
            <iframe class="h-full w-full border-0 bg-black" title="Active screensaver" data-screensaver-frame></iframe>
            <div id="screensaver-spotlight" class="absolute inset-0 hidden overflow-hidden" data-screensaver-spotlight aria-hidden="true"><span class="screensaver-spotlight-lens"></span></div>
            <button type="button" class="absolute bottom-5 left-1/2 -translate-x-1/2 rounded-full bg-black/60 px-4 py-2 text-xs font-semibold text-white opacity-0 transition hover:opacity-100 focus:opacity-100" data-screensaver-close>Move the pointer or press any key to return</button>
        </div>
        @include('partials.javascript-templates')
    </body>
</html>
@endif
