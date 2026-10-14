@extends('layouts.app')

@section('content')
    <div id="settings-shell" class="mx-auto max-w-6xl space-y-5 p-4 sm:p-6 lg:p-8">
        <header>
            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">Settings</p>
            <h1 class="mt-1 text-2xl font-bold">@yield('settings-title')</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">@yield('settings-description')</p>
        </header>
        @include('partials.account-tabs')
        @yield('settings-content')
    </div>
@endsection
