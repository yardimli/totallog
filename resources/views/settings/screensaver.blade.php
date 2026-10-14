@extends('layouts.settings')

@section('settings-title', 'Screensaver')
@section('settings-description', 'Choose what appears when you take a break.')

@section('settings-content')
    <form method="POST" action="{{ route('settings.screensaver.update') }}" enctype="multipart/form-data" class="space-y-5" data-screensaver-settings-form>
        @csrf @method('PATCH')
        <section class="panel">
            <div id="screensaver-idle-heading" class="flex flex-wrap items-start justify-between gap-4">
                <div id="screensaver-idle-copy"><h2 class="text-lg font-bold">Idle behavior</h2><p class="mt-1 text-sm text-slate-500">The screensaver starts after no keyboard, pointer, touch, or scrolling activity.</p></div>
                <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold dark:border-slate-700"><input type="hidden" name="screensaver_enabled" value="0"><input type="checkbox" name="screensaver_enabled" value="1" @checked(auth()->user()->screensaver_enabled)>Enable screensaver</label>
            </div>
            <div id="screensaver-behavior-fields" class="mt-5 grid gap-4 sm:grid-cols-2">
                <div id="screensaver-wait-field"><label class="label" for="screensaver-wait">Wait before starting</label><select id="screensaver-wait" class="input" name="screensaver_wait_minutes">@foreach([1,2,5,10,15,30,60] as $minutes)<option value="{{ $minutes }}" @selected((auth()->user()->screensaver_wait_minutes ?? 10) === $minutes)>{{ $minutes }} {{ $minutes === 1 ? 'minute' : 'minutes' }}</option>@endforeach</select></div>
                <div id="screensaver-speed-field"><label class="label" for="screensaver-speed">Animation speed</label><select id="screensaver-speed" class="input" name="screensaver_speed" data-screensaver-speed>@foreach([['0.5','Very slow'],['0.75','Slow'],['1','Normal'],['1.25','Quick'],['1.5','Fast'],['2','Very fast']] as [$speed, $label])<option value="{{ $speed }}" @selected((float) (auth()->user()->screensaver_speed ?? 1) === (float) $speed)>{{ $label }} ({{ $speed }}×)</option>@endforeach</select></div>
            </div>
        </section>

        <section class="panel">
            <div id="screensaver-picker-heading"><h2 class="text-lg font-bold">Choose a screensaver</h2><p class="mt-1 text-sm text-slate-500">Select one on the left and tune its live preview on the right.</p></div>
            <div id="screensaver-picker" class="mt-5 grid gap-5 md:grid-cols-[15rem_minmax(0,1fr)]">
                <div id="screensaver-list" class="max-h-[34rem] space-y-1 overflow-y-auto pr-1" role="radiogroup" aria-label="Screensavers">
                    @foreach($screensavers as $value => $label)
                        <label class="screensaver-list-option flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold hover:bg-slate-100 dark:hover:bg-slate-800"><input type="radio" name="screensaver_style" value="{{ $value }}" data-screensaver-option data-screensaver-label="{{ $label }}" @checked((auth()->user()->screensaver_style ?? 'flying-toasters') === $value)><span>{{ $label }}</span></label>
                    @endforeach
                </div>
                <div id="screensaver-preview-column" class="min-w-0 space-y-4">
                    <div id="screensaver-preview-heading" class="flex items-center justify-between gap-3"><h3 class="font-bold" data-screensaver-preview-title></h3><button type="button" class="btn-secondary" data-screensaver-start>Preview full screen</button></div>
                    <div id="screensaver-preview-stage" class="relative aspect-video overflow-hidden rounded-2xl border border-slate-700 bg-black shadow-inner">
                        <iframe class="h-full w-full border-0" title="Screensaver preview" tabindex="-1" data-screensaver-preview></iframe>
                        <div id="screensaver-spotlight-preview" class="absolute inset-0 hidden overflow-hidden bg-slate-100 text-slate-900" data-screensaver-spotlight-preview aria-hidden="true"><span class="absolute inset-x-0 top-0 h-[18%] bg-white shadow-sm"></span><span class="absolute left-[6%] top-[6%] h-[7%] w-[28%] rounded bg-indigo-600"></span><span class="absolute left-[8%] top-[27%] h-[56%] w-[38%] rounded-xl bg-white shadow"></span><span class="absolute right-[8%] top-[27%] h-[24%] w-[38%] rounded-xl bg-white shadow"></span><span class="screensaver-spotlight-lens"></span></div>
                    </div>
                    <div id="screensaver-message-field" class="hidden" data-screensaver-message-setting><label class="label" for="screensaver-message">Custom message</label><input id="screensaver-message" class="input" name="screensaver_message" maxlength="120" value="{{ old('screensaver_message', auth()->user()->screensaver_message ?? 'OUT TO LUNCH') }}" data-screensaver-message><p class="mt-1 text-xs text-slate-500">Changes appear in the preview immediately.</p></div>
                    <div id="screensaver-logo-field" class="hidden" data-screensaver-logo-setting><label class="label" for="screensaver-logo">Custom logo</label><input id="screensaver-logo" type="file" class="input" name="screensaver_logo" accept="image/png,image/jpeg,image/gif,image/webp" data-screensaver-logo><p class="mt-1 text-xs text-slate-500">PNG, JPEG, GIF, or WebP up to 4 MB. It appears in the preview immediately.</p></div>
                </div>
            </div>
            <p class="mt-4 text-xs text-slate-500">After Dark in CSS is used under the MIT License; original artwork remains © Berkeley Systems Inc. Starry Night is a local reimplementation inspired by lropero's project.</p>
        </section>

        <div id="screensaver-form-actions" class="flex flex-wrap items-center gap-3"><button class="btn">Save screensaver settings</button><button type="button" class="btn-secondary" data-screensaver-start>Start now</button></div>
    </form>
@endsection
