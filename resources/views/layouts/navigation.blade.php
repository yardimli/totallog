@php
    $iconClass = 'h-5 w-5';
    $activeLogDate = request()->routeIs('logs.show') ? request()->route('date') : today()->toDateString();
    $activeLogUrl = request()->routeIs('logs.show') ? route('logs.show', $activeLogDate) : route('logs.today');
@endphp
<nav class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95" data-primary-navigation>
    <div id="primary-navigation-content" class="mx-auto flex min-h-16 max-w-7xl flex-wrap items-center gap-2 px-4 py-2 sm:flex-nowrap sm:px-6 lg:px-8">
        <a href="{{ route('logs.today') }}" class="flex min-w-0 items-center gap-2 font-bold" data-navbar-home>
            @include('partials.logo', ['class' => 'h-9 w-9 shrink-0'])
            <span class="min-w-0 leading-tight">
                <span class="block">Total Log</span>
                @if(request()->routeIs('logs.show') && isset($day))
                    <time class="block whitespace-nowrap text-[11px] font-medium text-slate-500 dark:text-slate-400 sm:text-xs" datetime="{{ $day->toDateString() }}" data-navigation-date>{{ $day->format('l, F j, Y') }}</time>
                @elseif(request()->routeIs('calendar') && isset($start, $end))
                    <span class="block whitespace-nowrap text-[11px] font-medium text-slate-500 dark:text-slate-400 sm:text-xs" data-navigation-date>{{ $start->format('M j') }} &ndash; {{ $end->format('M j, Y') }}</span>
                @endif
            </span>
        </a>

        @if(request()->routeIs('logs.show') && isset($day, $tasks, $log, $counts))
            <div id="daily-log-navigation-actions" class="order-3 flex w-full items-center justify-end gap-1 sm:order-none sm:w-auto" data-day-navigation>
                <a class="nav-link grid h-9 w-9 place-items-center p-0" href="{{ route('logs.show', $day->copy()->subDay()->toDateString()) }}" aria-label="Previous day" title="Previous day">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                </a>
                <a class="nav-link grid h-9 w-9 place-items-center p-0" href="{{ route('logs.today') }}" aria-label="Today" title="Today">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/><circle cx="12" cy="15" r="2" fill="currentColor" stroke="none"/></svg>
                </a>
                <a class="nav-link grid h-9 w-9 place-items-center p-0" href="{{ route('logs.show', $day->copy()->addDay()->toDateString()) }}" aria-label="Next day" title="Next day">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                </a>
                <a class="nav-link grid h-9 w-9 place-items-center p-0" href="{{ route('calendar') }}" aria-label="Open calendar" title="Calendar">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h2M12 14h2M16 14h2M8 18h2M12 18h2"/></svg>
                </a>
                    <details class="relative z-50 {{ $tasks->isEmpty() ? 'hidden' : '' }}" data-events-menu>
                        <summary class="nav-link grid h-9 w-9 cursor-pointer list-none place-items-center p-0 [&::-webkit-details-marker]:hidden" aria-label="Events" title="Events">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 6 2 2 3-3M11 7h9M4 12l2 2 3-3M11 13h9M4 18l2 2 3-3M11 19h9"/></svg>
                        </summary>
                        <div id="more-events-menu" class="absolute right-0 mt-2 grid max-h-[65vh] w-72 gap-1 overflow-y-auto overscroll-contain rounded-xl border bg-white p-2 shadow-2xl dark:border-slate-700 dark:bg-slate-900" style="z-index:70">
                            @foreach($tasks as $task)
                                <button class="flex items-center rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" data-task-event="{{ route('events.store', [$log, $task]) }}" data-task-emoji="{{ $task->emoji }}" data-task-icon="{{ $task->icon_data }}" data-capture-location data-name="{{ $task->name }}" data-options='@json($task->options ?? [])'><span class="mr-2 h-4 w-4 shrink-0 rounded-sm border border-slate-300 dark:border-slate-600" style="background-color:{{ $task->color_hex }}"></span>@if($task->icon_data)<img src="{{ $task->icon_data }}" class="mr-2 h-7 w-7 shrink-0 rounded-lg object-cover" alt="">@else<span class="mr-2 text-lg" aria-hidden="true">{{ $task->emoji }}</span>@endif<span class="min-w-0"><strong>{{ $task->name }}</strong> <span class="rounded-full bg-slate-100 px-1.5 dark:bg-slate-800" data-count>{{ $counts[$task->id] ?? 0 }}</span><small class="block text-slate-500">{{ collect($task->scheduled_times ?? [])->map(fn ($time) => auth()->user()->formatClock($time))->implode(', ') ?: 'Any time' }}</small></span></button>
                            @endforeach
                        </div>
                    </details>
            </div>
        @endif

        @unless(auth()->user()->is_guest)
        @if(request()->routeIs('notes.*'))
            <a class="nav-link ml-auto grid h-9 w-9 place-items-center p-0" href="{{ route('logs.today') }}" aria-label="Open today's log" title="Today's log">
                <svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/><path d="M8 7h8M8 11h6"/></svg>
            </a>
        @else
            <a class="nav-link ml-auto hidden h-9 w-9 place-items-center p-0 sm:grid" href="{{ route('notes.index') }}" aria-label="Open notes" title="Notes">
                <svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 3h9l3 3v15H6z"/><path d="M14 3v4h4M9 11h6M9 15h6"/></svg>
            </a>
        @endif
        @endunless
        @unless(request()->routeIs('calendar', 'logs.*', 'notes.*'))
            <a class="nav-link grid h-9 w-9 place-items-center p-0" href="{{ route('logs.today') }}" aria-label="Open today's log" title="Today's log">
                <svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h2M12 14h2M16 14h2M8 18h2M12 18h2"/></svg>
            </a>
        @endunless
        <a class="nav-link {{ auth()->user()->is_guest ? 'ml-auto' : '' }} hidden h-9 w-9 place-items-center p-0 sm:grid {{ request()->routeIs('search.*') ? 'nav-active' : '' }}" href="{{ route('search.index') }}" aria-label="Search logs" title="Search logs">
            <svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
        </a>
        @include('partials.theme-selector', ['class' => 'hidden sm:block'])
        <button type="button" class="nav-link ml-auto p-2 sm:ml-0" data-mobile-nav-toggle aria-expanded="false" aria-controls="account-navigation" aria-label="Open navigation" title="Menu"><svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
    </div>

    <div id="account-navigation" class="absolute right-4 top-14 hidden w-[min(20rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl dark:border-slate-800 dark:bg-slate-900 sm:right-6 lg:right-8" data-mobile-nav-menu>
        <div id="account-navigation-links" class="grid gap-1 text-sm">
            @unless(auth()->user()->is_guest)
            <a class="nav-link flex items-center gap-2 sm:hidden {{ request()->routeIs('notes.*') ? 'nav-active' : '' }}" href="{{ route('notes.index') }}"><svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 3h9l3 3v15H6z"/><path d="M14 3v4h4M9 11h6M9 15h6"/></svg><span>Notes</span></a>
            <a class="nav-link flex items-center gap-2 sm:hidden {{ request()->routeIs('search.*') ? 'nav-active' : '' }}" href="{{ route('search.index') }}"><svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><span>Search</span></a>
            @include('partials.theme-selector', ['class' => 'sm:hidden', 'navigationMenu' => true])
            <div class="navigation-divider my-1 border-t border-slate-200 dark:border-slate-800 sm:hidden"></div>
            <a class="nav-link flex items-center gap-2 {{ request()->routeIs('tasks.*') ? 'nav-active' : '' }}" href="{{ route('tasks.index') }}"><svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m4 6 2 2 3-3M11 7h9M4 12l2 2 3-3M11 13h9M4 18l2 2 3-3M11 19h9"/></svg><span>Event setup</span></a>
            <a class="nav-link flex items-center gap-2 {{ request()->routeIs('goals.*') ? 'nav-active' : '' }}" href="{{ route('goals.index') }}"><svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg><span>Goals</span></a>
            <a class="nav-link flex items-center gap-2 {{ request()->routeIs('profile.*', 'settings.*', 'sensors.*', 'api-usage.*', 'admin.*') ? 'nav-active' : '' }}" href="{{ route('profile.edit') }}"><svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg><span>Account setup</span></a>
            @endunless
            @if(request()->routeIs('logs.show') && request()->boolean('show_hidden'))
                <a class="nav-link flex items-center gap-2 font-semibold text-amber-700 dark:text-amber-300" href="{{ $activeLogUrl }}" data-hidden-entries-toggle><svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2"/></svg><span data-hidden-entries-label>Hide hidden entries</span></a>
            @else
                <a class="nav-link flex items-center gap-2 font-semibold text-amber-700 dark:text-amber-300" href="{{ $activeLogUrl }}?show_hidden=1" data-hidden-entries-toggle><svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2"/></svg><span data-hidden-entries-label>Show hidden entries</span></a>
            @endif
            @unless(auth()->user()->is_guest)
            <div class="navigation-divider my-1 border-t border-slate-200 dark:border-slate-800"></div>
            <button type="button" class="nav-link flex w-full items-center gap-2 text-left" data-screensaver-toggle data-toggle-url="{{ route('settings.screensaver.toggle') }}" aria-pressed="{{ auth()->user()->screensaver_enabled ? 'true' : 'false' }}"><svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M8 22h8M12 18v4"/></svg><span data-screensaver-toggle-label>{{ auth()->user()->screensaver_enabled ? 'Disable screensaver' : 'Enable screensaver' }}</span></button>
            @if(request()->routeIs('logs.show'))
                <button type="button" class="nav-link flex items-center gap-2 text-left font-semibold text-indigo-600 dark:text-indigo-400" data-panel-open="chat"><svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/></svg><span>Chat with log</span></button>
            @else
                <a class="nav-link flex items-center gap-2 font-semibold text-indigo-600 dark:text-indigo-400" href="{{ route('logs.today', ['panel' => 'chat']) }}"><svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z"/></svg><span>Chat with log</span></a>
            @endif
            @endunless
            <div class="navigation-divider my-1 border-t border-slate-200 dark:border-slate-800"></div>
            <form method="POST" action="{{ route('logout') }}" data-navigation-sign-out>
                @csrf
                <button class="nav-link flex w-full items-center gap-2 text-left" aria-label="Sign out" title="Sign out"><svg class="{{ $iconClass }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/></svg><span>Sign out</span></button>
            </form>
        </div>
    </div>
</nav>
