@extends('layouts.app')

@section('header')
    <div id="event-setup-page-heading" class="flex flex-wrap items-center gap-3">
        <div id="event-setup-heading-copy"><p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Event schedule</p><h1 class="text-xl font-bold">Repeating events & buttons</h1></div>
        <button type="button" class="btn ml-auto" data-event-definition-create>+ Add event</button>
    </div>
@endsection

@section('content')
    <div id="event-setup-page-container" class="mx-auto max-w-5xl space-y-5 p-4 sm:p-6 lg:p-8">
        <div id="event-setup-introduction" class="panel flex flex-wrap items-center gap-4">
            <div id="event-setup-introduction-copy" class="min-w-0 flex-1"><h2 class="text-lg font-bold">Your event buttons</h2><p class="mt-1 text-sm text-slate-500">Drag events into your preferred order. Use Edit to change an event in the side panel. This order is also used for sticky events and the daily dropdown.</p></div>
            <span class="rounded-full bg-indigo-100 px-3 py-1 text-sm font-bold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-200">{{ $tasks->count() }} {{ Str::plural('event', $tasks->count()) }}</span>
        </div>

        <section id="event-definition-list" class="space-y-3" data-event-sort-list data-reorder-url="{{ route('tasks.reorder') }}">
            @forelse($tasks as $task)
                @php
                    $taskEditorData = ['name' => $task->name, 'emoji' => $task->emoji, 'icon_data' => $task->icon_data, 'color' => $task->color_hex, 'recurrence_type' => $task->recurrence_type, 'recurrence_days' => $task->recurrence_days ?? [], 'scheduled_times' => $task->scheduled_times ?? [], 'visible_after' => $task->visible_after, 'options_text' => implode(', ', $task->options ?? []), 'is_sticky' => $task->is_sticky, 'daily_default_count' => $task->daily_default_count, 'update_url' => route('tasks.update', $task), 'delete_url' => route('tasks.destroy', $task)];
                @endphp
                <article class="panel flex cursor-grab items-center gap-3 transition hover:border-indigo-300 hover:shadow-md active:cursor-grabbing dark:hover:border-indigo-700" id="event-definition-{{ $task->id }}" draggable="true" data-event-sort-item data-event-id="{{ $task->id }}" title="Drag to reorder">
                    <span class="touch-none select-none text-xl text-slate-400" data-event-drag-handle aria-hidden="true">⠿</span>
                    <span class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-2xl text-2xl shadow-sm" style="background-color:{{ $task->color_hex }};color:{{ $task->button_text_color }}" aria-hidden="true">@if($task->icon_data)<img src="{{ $task->icon_data }}" class="h-full w-full object-cover" alt="">@else{{ $task->emoji }}@endif</span>
                    <span class="min-w-0 flex-1"><strong class="block truncate text-base">{{ $task->name }}</strong><span class="mt-0.5 block text-sm text-slate-500">{{ $task->schedule_summary }} · Daily target {{ $task->daily_target_count }}@if(count($task->scheduled_times ?? []) > 1) ({{ $task->daily_default_count }} per time slot)@endif</span>@if($task->options)<span class="mt-1 block truncate text-xs text-slate-400">Values: {{ implode(', ', $task->options) }}</span>@endif</span>
                    <span class="hidden rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300 sm:inline-flex">{{ $task->is_sticky ? 'Sticky' : 'Dropdown' }}</span>
                    <button type="button" class="btn-secondary shrink-0" data-event-definition-open="event-definition-data-{{ $task->id }}">Edit</button>
                    <script type="application/json" id="event-definition-data-{{ $task->id }}">@json($taskEditorData)</script>
                </article>
            @empty
                <div id="empty-event-button-message" class="panel py-12 text-center"><span class="text-4xl" aria-hidden="true">📅</span><h2 class="mt-3 text-lg font-bold">No event buttons yet</h2><p class="mt-1 text-sm text-slate-500">Add a recurring event and choose when its button should appear.</p><button type="button" class="btn mt-4" data-event-definition-create>Create your first event</button></div>
            @endforelse
        </section>
    </div>

    <div id="event-definition-overlay" class="fixed inset-0 hidden" style="z-index:80" data-overlay="event-definition" data-overlay-side="right" role="dialog" aria-modal="true" aria-labelledby="event-definition-title">
        <button type="button" class="absolute inset-0 bg-slate-950/55 opacity-0 transition-opacity" data-overlay-backdrop data-overlay-close="event-definition" aria-label="Close event editor"></button>
        <aside class="absolute inset-y-0 right-0 w-full max-w-md translate-x-full overflow-y-auto bg-slate-100 p-4 shadow-2xl transition-transform duration-300 dark:bg-slate-950 sm:p-6" data-overlay-panel>
            <div id="event-definition-heading" class="mb-5 flex items-start gap-2"><div id="event-definition-heading-copy"><p class="text-xs font-bold uppercase tracking-wider text-indigo-600">Event setup</p><h2 id="event-definition-title" class="text-2xl font-black" data-event-definition-title>Add event</h2><p class="mt-1 text-sm text-slate-500">Configure its button, recurrence, and time slots.</p></div><button type="button" class="btn-secondary ml-auto" data-overlay-close="event-definition">Close</button></div>

            <form method="POST" action="{{ route('tasks.store') }}" data-create-action="{{ route('tasks.store') }}" class="space-y-4" data-ajax data-event-definition-form data-recurrence-form>
                @csrf
                <div id="event-definition-name-field"><label class="label" for="event-definition-name">Friendly name</label><input id="event-definition-name" class="input" name="name" required placeholder="Dog medication"></div>
                @include('partials.emoji-picker', ['pickerId' => 'event-definition-emoji', 'name' => 'emoji', 'value' => \App\Models\TaskDefinition::DEFAULT_EMOJI, 'label' => 'Event emoji'])
                @include('partials.icon-upload', ['pickerId' => 'event-definition-icon'])
                <div id="event-definition-color-field"><label class="label" for="event-definition-color">Button color</label><div id="event-definition-color-control" class="flex items-center gap-3"><span id="event-definition-color-preview" class="h-8 w-8 shrink-0 rounded-lg border border-slate-300 shadow-sm dark:border-slate-600"></span><input id="event-definition-color" data-color-input="event-definition-color-preview" type="color" name="color" value="#4f46e5" class="h-11 w-full cursor-pointer rounded-xl border border-slate-300 bg-white p-1 dark:border-slate-700 dark:bg-slate-950"></div></div>
                <div id="event-definition-recurrence-field"><label class="label" for="event-definition-recurrence">Repeats</label><select id="event-definition-recurrence" class="input" name="recurrence_type" data-recurrence-select><option value="daily">Every day</option><option value="weekly">Selected weekdays</option><option value="monthly">Selected days of the month</option></select></div>
                <fieldset data-recurrence-weekly class="hidden rounded-xl border border-slate-200 p-3 dark:border-slate-700"><legend class="px-1 text-sm font-semibold">Weekdays</legend><div id="event-definition-weekday-options" class="grid grid-cols-4 gap-2 text-sm">@foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $index => $weekday)<label class="flex items-center gap-1.5"><input type="checkbox" name="weekdays[]" value="{{ $index + 1 }}">{{ $weekday }}</label>@endforeach</div></fieldset>
                <div id="event-definition-month-days-field" data-recurrence-monthly class="hidden"><label class="label" for="event-definition-month-days">Days of the month</label><input id="event-definition-month-days" class="input" name="month_days_text" placeholder="1, 10, 15, 28"><p class="mt-1 text-xs text-slate-500">Use numbers from 1 to 31.</p></div>
                <div id="event-definition-time-slots-field"><label class="label">Time slots <span class="font-normal text-slate-500">(optional)</span></label><div id="event-definition-time-slots" data-time-slots data-name="scheduled_times[]" data-values="[]"><div id="event-definition-time-slot-list" class="space-y-2" data-time-slot-list></div><button type="button" class="btn-secondary mt-2 w-full" data-time-slot-add>+ Add time slot</button></div><p class="mt-1 text-xs text-slate-500">Tap a time to slide through hours and select minutes in five-minute steps.</p></div>
                <div id="event-definition-daily-count-field"><label class="label" for="event-definition-daily-count">Daily default count per time slot</label><input id="event-definition-daily-count" class="input" type="number" name="daily_default_count" value="1" min="1" max="999" inputmode="numeric" required><p class="mt-1 text-xs text-slate-500">Each sticky time-slot button disappears after this many entries. The total daily target is this count multiplied by the number of time slots. The event remains available in the Events dropdown.</p></div>
                <label class="flex gap-2 text-sm"><input type="checkbox" name="is_sticky" value="1" data-event-sticky-toggle><span><strong>Sticky event</strong><span class="block text-xs text-slate-500">Show its button in the planner. Optional time slots place it at specific hours.</span></span></label>
                <div id="event-definition-visibility-field" class="hidden rounded-2xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900" data-sticky-visibility-field>
                    <label class="flex gap-2 text-sm"><input type="checkbox" data-visible-after-toggle><span><strong>Become visible at a set time</strong><span class="block text-xs text-slate-500">On today’s planner, hide this sticky button until the selected time.</span></span></label>
                    <div id="event-definition-visible-after-picker" class="mt-3 hidden" data-visible-after-picker data-time-picker><button type="button" class="btn-secondary w-full justify-center text-lg font-bold" data-time-picker-open></button><input type="hidden" name="visible_after" value="18:00" disabled data-time-picker-input></div>
                    <p class="mt-2 text-xs text-slate-500">The event always remains available from the Events dropdown.</p>
                </div>
                <div id="event-definition-options-field"><label class="label" for="event-definition-options">Optional values</label><textarea id="event-definition-options" class="input" name="options_text" rows="3" placeholder="1, 2, 3, 4, 5"></textarea><p class="mt-1 text-xs text-slate-500">When values exist, one must be selected before tracking.</p></div>
                <button class="btn w-full" data-event-definition-submit>Create event</button>
            </form>

            <div id="event-definition-delete-section" class="mt-7 hidden border-t border-slate-200 pt-5 dark:border-slate-800" data-event-definition-delete-section><form method="POST" action="" data-confirm-event-delete data-event-definition-delete-form>@csrf @method('DELETE')<button class="btn-secondary w-full border-rose-300 text-rose-600 hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950">Delete event</button><p class="mt-2 text-center text-xs text-slate-500">Recorded entries become editable text.</p></form></div>
        </aside>
    </div>
    @include('partials.icon-cropper')
@endsection
