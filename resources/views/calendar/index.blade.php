@extends('layouts.app')

@section('content')
    <div id="calendar-page-container" class="mx-auto max-w-7xl space-y-4 p-4 sm:p-6 lg:p-8" data-calendar-focus-date="{{ $focus->toDateString() }}" data-calendar-view-current="{{ $view }}" data-calendar-today-url="{{ route('logs.today') }}">
        <div id="calendar-navigation-controls" class="panel flex flex-wrap items-center gap-2">
            @php $jump = $view === 'month' ? 'month' : ($view === 'week' ? 'week' : 'day'); @endphp
            <a class="btn-secondary" href="{{ route('calendar', $focus->copy()->sub(1, $jump)->toDateString()) }}?view={{ $view }}">← Previous</a>
            <a class="btn-secondary" href="{{ route('logs.today') }}" data-calendar-today-action="open-log">Today</a>
            <a class="btn-secondary" href="{{ route('calendar', $focus->copy()->add(1, $jump)->toDateString()) }}?view={{ $view }}">Next →</a>
            <div id="calendar-view-controls" class="ml-auto flex items-center gap-1" role="group" aria-label="Calendar view">
                <a href="{{ route('calendar', $focus->toDateString()) }}?view=week" class="nav-link grid h-9 w-9 place-items-center p-0 {{ $view === 'week' ? 'nav-active' : '' }}" data-calendar-view="week" aria-label="Week view" title="Week view" @if($view === 'week') aria-current="page" @endif>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M6 14h2M10 14h2M14 14h2M18 14h1"/></svg>
                </a>
                <a href="{{ route('calendar', $focus->toDateString()) }}?view=month" class="nav-link grid h-9 w-9 place-items-center p-0 {{ $view === 'month' ? 'nav-active' : '' }}" data-calendar-view="month" aria-label="Month view" title="Month view" @if($view === 'month') aria-current="page" @endif>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M7 14h2M11 14h2M15 14h2M7 18h2M11 18h2M15 18h2"/></svg>
                </a>
            </div>
        </div>
        @if($goalSnapshots->isNotEmpty())
            <section id="calendar-goals" class="flex flex-wrap gap-2" aria-label="Goals for {{ $focus->toDateString() }}">
                @foreach($goalSnapshots as $snapshot)
                    @php $goal = $snapshot['goal']; @endphp
                    <a href="{{ route('goals.show', ['goal' => $goal, 'date' => $focus->toDateString()]) }}" class="inline-flex min-w-48 items-center gap-2 rounded-full px-3 py-2 text-sm shadow-sm transition hover:-translate-y-0.5 hover:shadow-md" style="background-color:{{ $goal->color }};color:{{ $goal->text_color }}" data-calendar-goal="{{ $goal->id }}">
                        @if($goal->icon_data)<img src="{{ $goal->icon_data }}" class="h-8 w-8 shrink-0 rounded-lg object-cover" alt="">@else<span class="text-lg" aria-hidden="true">{{ $goal->emoji }}</span>@endif
                        <span class="min-w-0"><strong class="block truncate">{{ $goal->name }}</strong><span class="block text-xs opacity-90">{{ $snapshot['points'] }}/{{ $snapshot['target'] }} points{{ $snapshot['latest'] ? ' · '.$snapshot['latest']->occurred_at->diffForHumans() : ' · No activity' }}</span></span>
                    </a>
                @endforeach
            </section>
        @endif
        <div id="calendar-day-grid" class="grid {{ $view === 'day' ? 'grid-cols-1' : 'grid-cols-2 sm:grid-cols-4 lg:grid-cols-7' }} gap-2">
            @foreach($days as $day)
                @php $item = $logs->get($day->toDateString()); @endphp
                <a href="{{ route('logs.show', $day->toDateString()) }}" class="group min-h-32 rounded-2xl border p-3 transition hover:-translate-y-0.5 hover:border-indigo-400 hover:shadow-md {{ $day->isToday() ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-950/50' : 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900' }} {{ $view === 'month' && !$day->isSameMonth($focus) ? 'opacity-50' : '' }}">
                    <div class="calendar-day-heading flex items-center justify-between"><span class="text-xs font-semibold uppercase text-slate-500">{{ $day->format('D') }}</span><span class="grid h-8 w-8 place-items-center rounded-full {{ $day->isToday() ? 'bg-indigo-600 text-white' : '' }}">{{ $day->day }}</span></div>
                    <div class="calendar-day-summary mt-5 space-y-1 text-xs text-slate-500">
                        @if($item)<p>{{ $item->blocks_count }} log {{ Str::plural('entry', $item->blocks_count) }}</p>@else<p class="opacity-0 transition group-hover:opacity-100">Open log →</p>@endif
                    </div>
                    @if($item?->blocks_count)
                        <div class="calendar-activity-markers mt-2 flex flex-wrap items-center" aria-label="{{ $item->blocks_count }} recorded activities" data-calendar-activity-markers>
                            @for($marker = 0; $marker < min($item->blocks_count, 32); $marker++)
                                <span class="m-[5px] block h-[5px] w-[5px] shrink-0 bg-indigo-500 dark:bg-indigo-400" data-calendar-activity-marker aria-hidden="true"></span>
                            @endfor
                        </div>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
@endsection
