@extends('layouts.settings')

@section('settings-title', 'Sensors')
@section('settings-description', 'Connect services and manage automatic log updates.')

@section('settings-content')
        @if(session('error'))<div id="sensors-error-message" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-200">{{ session('error') }}</div>@endif
        <section id="github-sensor-card" class="panel">
            <div id="github-sensor-heading" class="flex items-start gap-3">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-slate-950 text-white dark:bg-white dark:text-slate-950" aria-hidden="true">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor"><path d="M12 .7a11.5 11.5 0 0 0-3.64 22.4c.58.1.79-.25.79-.56v-2.24c-3.22.7-3.9-1.37-3.9-1.37-.53-1.34-1.29-1.7-1.29-1.7-1.05-.72.08-.7.08-.7 1.16.08 1.78 1.2 1.78 1.2 1.03 1.77 2.71 1.26 3.37.96.1-.75.4-1.26.73-1.55-2.57-.3-5.27-1.29-5.27-5.69 0-1.26.45-2.28 1.19-3.09-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.16 1.18a10.96 10.96 0 0 1 5.76 0c2.2-1.49 3.16-1.18 3.16-1.18.63 1.59.23 2.76.11 3.05.74.81 1.19 1.83 1.19 3.09 0 4.41-2.71 5.39-5.29 5.68.42.36.79 1.06.79 2.15v3.25c0 .31.21.67.8.56A11.5 11.5 0 0 0 12 .7Z"/></svg>
                </span>
                <div id="github-sensor-heading-copy" class="min-w-0 flex-1"><h2 class="text-lg font-bold">GitHub commits</h2><p class="mt-1 text-sm text-slate-500">Add each commit to its day at the commit time. Log entries contain only the project name.</p></div>
                @if($githubSensor)<span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $githubSensor->enabled ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">{{ $githubSensor->enabled ? 'Enabled' : 'Disabled' }}</span>@endif
            </div>

            @if($githubSensor)
                <div id="github-sensor-linked-controls" class="mt-5 space-y-4 border-t border-slate-200 pt-5 dark:border-slate-800">
                    <div id="github-sensor-account" class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-900"><p class="text-xs font-bold uppercase tracking-wider text-slate-500">Linked account</p><p class="mt-1 text-lg font-bold">{{ '@'.$githubSensor->username }}</p>@if($githubSensor->last_checked_at)<p class="mt-1 text-xs text-slate-500">Last checked {{ auth()->user()->formatTime($githubSensor->last_checked_at) }} on {{ $githubSensor->last_checked_at->format('M j, Y') }}</p>@endif</div>
                    <form method="POST" action="{{ route('sensors.github.toggle') }}" data-ajax data-sensor-toggle-form>@csrf @method('PATCH')<input type="hidden" name="enabled" value="0"><label class="flex cursor-pointer items-center justify-between gap-4 rounded-2xl border border-slate-200 p-4 dark:border-slate-700"><span><strong>Automatic updates</strong><span class="block text-xs text-slate-500">Check GitHub whenever you open a current or unsynced past day.</span></span><span class="relative inline-flex h-7 w-12 shrink-0"><input type="checkbox" class="peer sr-only" name="enabled" value="1" data-sensor-enable @checked($githubSensor->enabled)><span class="absolute inset-0 rounded-full bg-slate-300 transition peer-checked:bg-indigo-600 dark:bg-slate-700"></span><span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span></span></label></form>
                    @if($githubSensor->last_error)<div id="github-sensor-error" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-200"><strong>Last check failed.</strong> {{ $githubSensor->last_error }}</div>@endif
                    <form method="POST" action="{{ route('sensors.github.sync') }}" data-ajax>@csrf<button type="submit" class="btn w-full justify-center" data-busy-label="Syncing…"><span data-button-label>Sync now</span></button></form>
                    @include('sensors.partials.github-sync-report', ['report' => data_get($githubSensor->settings, 'last_github_sync')])
                    <form method="POST" action="{{ route('sensors.github.unlink') }}" data-confirm-sensor-unlink>@csrf @method('DELETE')<button class="btn-secondary w-full border-rose-300 text-rose-600 hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950">Unlink GitHub</button></form>
                    <p class="text-center text-xs text-slate-500">Unlinking removes the saved token but keeps GitHub entries already added to your logs.</p>
                </div>
            @else
                <form method="POST" action="{{ route('sensors.github.link') }}" class="mt-5 space-y-4 border-t border-slate-200 pt-5 dark:border-slate-800">@csrf
                    <div id="github-username-field"><label class="label" for="github-username">GitHub username</label><input id="github-username" class="input" name="github_username" value="{{ old('github_username') }}" required autocomplete="username" placeholder="octocat">@error('github_username')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror</div>
                    <div id="github-token-field"><label class="label" for="github-token">Personal access token</label><input id="github-token" type="password" class="input" name="github_token" required autocomplete="off" placeholder="github_pat_…">@error('github_token')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror<p class="mt-1 text-xs text-slate-500">The token is validated against the username and encrypted before storage. Grant repository Contents read access if private commits should be included.</p></div>
                    <button class="btn w-full">Validate and link GitHub</button>
                </form>
            @endif
        </section>

        <section id="google-calendar-sensor-card" class="panel">
            <div id="google-calendar-sensor-heading" class="flex items-start gap-3">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-blue-600 text-2xl text-white" aria-hidden="true">📅</span>
                <div id="google-calendar-sensor-heading-copy" class="min-w-0 flex-1"><h2 class="text-lg font-bold">Google Calendar</h2><p class="mt-1 text-sm text-slate-500">Mirror your primary calendar’s events into daily logs and keep the current month updated.</p></div>
                <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $googleCalendarSensor?->enabled ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">{{ $googleCalendarSensor ? ($googleCalendarSensor->enabled ? 'Enabled' : 'Disabled') : 'Not linked' }}</span>
            </div>
            @if($googleCalendarSensor)
                <div id="google-calendar-linked-controls" class="mt-5 space-y-4 border-t border-slate-200 pt-5 dark:border-slate-800">
                    <div id="google-calendar-account" class="rounded-2xl bg-slate-50 p-4 dark:bg-slate-900"><p class="text-xs font-bold uppercase tracking-wider text-slate-500">Linked Google account</p><p class="mt-1 text-lg font-bold">{{ $googleCalendarSensor->username }}</p>@if($googleCalendarSensor->last_checked_at)<p class="mt-1 text-xs text-slate-500">Last synced {{ auth()->user()->formatTime($googleCalendarSensor->last_checked_at) }} on {{ $googleCalendarSensor->last_checked_at->format('M j, Y') }} · {{ data_get($googleCalendarSensor->settings, 'last_event_count', 0) }} events this month</p>@endif</div>
                    <form method="POST" action="{{ route('sensors.google-calendar.toggle') }}" data-ajax data-sensor-toggle-form>@csrf @method('PATCH')<input type="hidden" name="enabled" value="0"><label class="flex cursor-pointer items-center justify-between gap-4 rounded-2xl border border-slate-200 p-4 dark:border-slate-700"><span><strong>Automatic updates</strong><span class="block text-xs text-slate-500">Refresh this month hourly and when a current-month calendar or daily log is opened.</span></span><span class="relative inline-flex h-7 w-12 shrink-0"><input type="checkbox" class="peer sr-only" name="enabled" value="1" data-sensor-enable @checked($googleCalendarSensor->enabled)><span class="absolute inset-0 rounded-full bg-slate-300 transition peer-checked:bg-indigo-600 dark:bg-slate-700"></span><span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span></span></label></form>
                    @if($googleCalendarSensor->last_error)<div id="google-calendar-sensor-error" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-200"><strong>Last sync failed.</strong> {{ $googleCalendarSensor->last_error }}</div>@endif
                    <div id="google-calendar-actions" class="grid gap-2 sm:grid-cols-2"><form method="POST" action="{{ route('sensors.google-calendar.sync') }}" data-ajax>@csrf<button class="btn-secondary w-full">Sync this month now</button></form><a class="btn-secondary w-full justify-center" href="{{ route('sensors.google-calendar.connect') }}">Reconnect Google</a></div>
                    <form method="POST" action="{{ route('sensors.google-calendar.unlink') }}" data-confirm-sensor-unlink>@csrf @method('DELETE')<button class="btn-secondary w-full border-rose-300 text-rose-600 hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950">Unlink Google Calendar</button></form>
                    <p class="text-center text-xs text-slate-500">Total Log requests read-only calendar access. The refresh token is encrypted; event attendees are not stored.</p>
                </div>
            @else
                <div id="google-calendar-connect-controls" class="mt-5 space-y-4 border-t border-slate-200 pt-5 dark:border-slate-800">
                    @if($googleCalendarConfigured)
                        <p class="text-sm text-slate-500">Connect Google to import recurring and one-time events from the current month. Updates in Google will move, rename, or remove their matching log entries.</p>
                        <a class="btn w-full justify-center" href="{{ route('sensors.google-calendar.connect') }}">Connect Google Calendar</a>
                    @else
                        <div id="google-calendar-configuration-help" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"><p class="font-bold">Google OAuth setup required</p><p class="mt-1">Create a Web application OAuth client, enable Google Calendar API, and set <code>GOOGLE_CALENDAR_CLIENT_ID</code> and <code>GOOGLE_CALENDAR_CLIENT_SECRET</code>. Add this exact redirect URI:</p><code class="mt-2 block break-all rounded-xl bg-white/70 p-2 text-xs dark:bg-slate-950">{{ route('sensors.google-calendar.callback') }}</code></div>
                    @endif
                </div>
            @endif
        </section>

        <section id="desktop-sensor-card" class="panel">
            <div id="desktop-sensor-heading" class="flex items-start gap-3">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-cyan-700 text-2xl text-white" aria-hidden="true">🖥️</span>
                <div id="desktop-sensor-heading-copy" class="min-w-0 flex-1"><h2 class="text-lg font-bold">Windows desktop and Kindle</h2><p class="mt-1 text-sm text-slate-500">Track foreground applications and manually log the first book in Kindle Cloud Reader without uploading window titles, executable paths, reading position, Amazon passwords, or cookies.</p></div>
                <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $desktopSensor?->enabled ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">{{ $desktopSensor?->enabled ? 'Paired' : 'Not paired' }}</span>
            </div>
            <div id="desktop-sensor-controls" class="mt-5 space-y-4 border-t border-slate-200 pt-5 dark:border-slate-800">
                <div id="desktop-sensor-instructions" class="rounded-2xl bg-slate-50 p-4 text-sm dark:bg-slate-900"><p class="font-bold">Use Total Log Desktop</p><p class="mt-2 text-slate-500">Open the desktop app, confirm this site URL, and choose <strong>Pair in browser</strong>. Activity is collected locally and uploaded once every five minutes, then grouped into one timeline entry per hour. Kindle logging happens only when you manually choose <strong>Sync now · log first book</strong>.</p></div>
                @if($desktopSensor)
                    <div id="desktop-sensor-status" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950"><p class="font-bold text-emerald-800 dark:text-emerald-200">Desktop app paired</p>@if($desktopSensor->last_checked_at)<p class="mt-1 text-xs text-emerald-700 dark:text-emerald-300">Last activity batch {{ auth()->user()->formatTime($desktopSensor->last_checked_at) }} on {{ $desktopSensor->last_checked_at->format('M j, Y') }}</p>@else<p class="mt-1 text-xs text-emerald-700 dark:text-emerald-300">Waiting for the first five-minute activity batch.</p>@endif</div>
                    <form method="POST" action="{{ route('sensors.desktop.unlink') }}" data-confirm-sensor-unlink>@csrf @method('DELETE')<button class="btn-secondary w-full border-rose-300 text-rose-600 hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950">Unlink desktop app</button></form>
                @else
                    <p class="text-center text-xs text-slate-500">Pairing begins from the desktop app. The random key is stored here only as a secure hash.</p>
                @endif
            </div>
        </section>

        <section id="browser-sensor-card" class="panel">
            <div id="browser-sensor-heading" class="flex items-start gap-3">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-indigo-600 text-white" aria-hidden="true">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3.5 9h17M8 21c2-3 2-15 0-18M16 21c-2-3-2-15 0-18"/></svg>
                </span>
                <div id="browser-sensor-heading-copy" class="min-w-0 flex-1"><h2 class="text-lg font-bold">Chrome browsing</h2><p class="mt-1 text-sm text-slate-500">Track active desktop sites by duration and import synced mobile visits as hourly domain counts.</p></div>
                <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $desktopSensor?->enabled ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">{{ $desktopSensor?->enabled ? 'Desktop bridge ready' : 'Desktop required' }}</span>
            </div>
            <div id="browser-sensor-installation" class="mt-5 space-y-4 border-t border-slate-200 pt-5 dark:border-slate-800">
                <div id="browser-extension-instructions" class="rounded-2xl bg-slate-50 p-4 text-sm dark:bg-slate-900">
                    <p class="font-bold">Install the included Chrome extension</p>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-slate-500"><li>Pair and leave <strong>Total Log Desktop</strong> running.</li><li>Open <strong>chrome://extensions</strong> and enable Developer mode.</li><li>Choose <strong>Load unpacked</strong> and select <code class="rounded bg-slate-200 px-1 dark:bg-slate-800">public/totallog-chrome-extension</code>.</li><li>Open the extension settings and press <strong>Check desktop app</strong>.</li></ol>
                    <p class="mt-3 text-xs text-slate-500">The extension connects only to the desktop app on <code>127.0.0.1</code>. It never stores your Total Log server URL or pairing key.</p>
                </div>
                <div id="mobile-browser-sensor-info" class="rounded-2xl border border-violet-200 bg-violet-50 p-4 text-sm text-violet-950 dark:border-violet-900 dark:bg-violet-950 dark:text-violet-100"><p class="font-bold">Mobile browsing</p><p class="mt-1 text-xs leading-relaxed opacity-80">Enable <strong>History and tabs</strong> sync in Chrome on the iPhone and this desktop. Synced non-local visits are grouped into hourly log blocks and counted once per page visit. If several remote devices use the account, Chrome combines them.</p></div>
                @if($browserSensor)
                    <div id="browser-sensor-status" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950"><p class="font-bold text-amber-800 dark:text-amber-200">Legacy direct extension pairing</p><p class="mt-1 text-xs text-amber-700 dark:text-amber-300">The current extension uses the desktop key instead. You can safely remove this older pairing.</p></div>
                    <form method="POST" action="{{ route('sensors.browser.unlink') }}" data-confirm-browser-unlink>@csrf @method('DELETE')<button class="btn-secondary w-full border-rose-300 text-rose-600 hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-950">Unlink Chrome extension</button></form>
                @else
                    <p class="text-center text-xs text-slate-500">No second pairing key is needed. Browser data uses the paired desktop sensor.</p>
                @endif
            </div>
        </section>

        <section id="future-sensors-card" class="panel border-dashed"><p class="text-xs font-bold uppercase tracking-wider text-indigo-600">More sensors</p><h2 class="mt-1 text-lg font-bold">More automatic sources are coming</h2><p class="mt-1 text-sm text-slate-500">The sensor system is ready for additional activity, health, location, and service integrations.</p></section>
@endsection
