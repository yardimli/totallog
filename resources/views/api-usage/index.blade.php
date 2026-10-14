@extends('layouts.settings')

@section('settings-title', 'API usage')
@section('settings-description', 'Review calls, tokens, and estimated costs.')

@section('settings-content')
        <section class="grid gap-3 sm:grid-cols-3">
            <div class="api-usage-summary-card panel"><p class="text-xs font-bold uppercase text-slate-500">Calls</p><p class="mt-1 text-2xl font-black">{{ number_format($totals->calls) }}</p></div>
            <div class="api-usage-summary-card panel"><p class="text-xs font-bold uppercase text-slate-500">Tokens</p><p class="mt-1 text-2xl font-black">{{ number_format($totals->tokens) }}</p></div>
            <div class="api-usage-summary-card panel"><p class="text-xs font-bold uppercase text-slate-500">Estimated cost</p><p class="mt-1 text-2xl font-black">${{ number_format((float) $totals->cost, 8) }}</p></div>
        </section>

        <section class="panel overflow-hidden p-0">
            <div id="api-call-history-heading" class="border-b border-slate-200 p-4 dark:border-slate-800"><h2 class="font-bold">Call history</h2><p class="text-sm text-slate-500">Each call links back to the day where it was made.</p></div>
            <div id="api-call-history-list" class="divide-y divide-slate-200 dark:divide-slate-800">
                @forelse($calls as $call)
                    <article class="grid gap-2 p-4 text-sm sm:grid-cols-[9rem_minmax(0,1fr)_7rem_8rem_7rem] sm:items-center">
                        <div class="api-call-identity"><strong class="block">{{ ucfirst($call->operation) }}</strong><time class="text-xs text-slate-500">{{ $call->created_at->format('M j') }}, {{ auth()->user()->formatTime($call->created_at) }}</time></div>
                        <div class="api-call-details min-w-0"><p class="truncate font-medium">{{ $call->model ?: 'Models endpoint' }}</p>@if($call->error)<p class="truncate text-xs text-rose-600">{{ $call->error }}</p>@endif</div>
                        <span>{{ number_format($call->total_tokens) }} tokens</span>
                        <span>${{ number_format((float) $call->cost, 8) }}</span>
                        @if($call->dailyLog)<a class="font-semibold text-indigo-600 dark:text-indigo-400" href="{{ route('logs.show', $call->dailyLog->log_date->toDateString()) }}">View day</a>@else<span class="text-slate-400">No day</span>@endif
                    </article>
                @empty
                    <p class="p-6 text-sm text-slate-500">No API calls have been recorded yet.</p>
                @endforelse
            </div>
        </section>
        {{ $calls->links() }}
@endsection
