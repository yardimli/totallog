@extends('layouts.app')

@section('header')
<div id="event-edit-page-heading"><p class="text-xs font-semibold uppercase text-emerald-600">Event already tracked at {{ auth()->user()->formatTime($event->occurred_at) }}</p><h1 class="text-xl font-bold">{{ $event->task_name }} @if($event->selected_value)· {{ $event->selected_value }}@endif</h1></div>
@endsection

@section('content')
    <div id="event-edit-page-container" class="mx-auto max-w-2xl p-4 sm:p-6 lg:p-8">
        <section class="panel"><h2 class="mb-3 font-bold">Optional notes</h2>@if($event->latitude !== null)@php($eventPlace = collect([$event->suburb, $event->city])->filter()->unique()->join(', '))<div id="event-edit-location" class="mb-4 rounded-xl bg-slate-100 px-3 py-2 text-sm dark:bg-slate-950"><p>{{ $eventPlace ?: number_format($event->latitude, 5).', '.number_format($event->longitude, 5) }}</p></div>@endif<form method="POST" action="{{ route('events.update', $event) }}" class="space-y-3" data-event-autosave-form>@csrf @method('PATCH')<div id="event-edit-time-field"><label class="label">Event time</label><div id="event-edit-time-picker" data-time-picker><button type="button" class="btn-secondary w-full justify-center text-lg font-bold" data-time-picker-open></button><input type="hidden" name="occurred_at" value="{{ \App\Support\DayTime::clock($event->occurred_at, $event->dailyLog->log_date) }}" data-time-picker-input data-shared-time-source="event-{{ $event->id }}"></div></div>@include('partials.emoji-picker', ['pickerId' => 'tracked-event-emoji-'.$event->id, 'name' => 'emoji', 'value' => $event->block->emoji, 'label' => 'Entry emoji'])<textarea class="input" name="notes" rows="12" placeholder="Anything else worth remembering?">{{ $event->block->content }}</textarea><p class="text-sm font-semibold text-emerald-600 dark:text-emerald-400" data-autosave-status role="status" aria-live="polite">Changes save automatically.</p><a class="btn-secondary w-full" href="{{ route('logs.show', $event->dailyLog->log_date->toDateString()) }}">Return to log</a></form></section>
    </div>
@endsection
