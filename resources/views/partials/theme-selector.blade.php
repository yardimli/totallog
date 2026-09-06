@php($navigationMenu = $navigationMenu ?? false)
<details class="theme-selector relative {{ $class ?? '' }}" data-theme-menu>
    <summary class="nav-link {{ $navigationMenu ? 'flex items-center gap-2' : 'grid h-9 w-9 place-items-center p-0' }} cursor-pointer list-none [&::-webkit-details-marker]:hidden" aria-label="Toggle theme" title="Choose theme" data-theme-current>
        <svg class="h-5 w-5" data-theme-icon="light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.66 6.34l1.41-1.41"/></svg>
        <svg class="hidden h-5 w-5" data-theme-icon="paper" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 4c-7 0-12 4-12 10 0 3 2 5 5 5 6 0 7-8 7-15Z"/><path d="M4 20c3-5 7-8 12-11"/></svg>
        <svg class="hidden h-5 w-5" data-theme-icon="blue" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3S5 11 5 16a7 7 0 0 0 14 0c0-5-7-13-7-13Z"/><path d="M9 17c.7 1.3 1.7 2 3 2"/></svg>
        <svg class="hidden h-5 w-5" data-theme-icon="red" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22c4 0 7-3 7-7 0-5-4-7-3-12-4 2-7 6-7 10-1-1-2-2-2-4-2 2-2 4-2 6 0 4 3 7 7 7Z"/><path d="M10 18c0-2 1-3 3-5 1 2 2 3 1 5"/></svg>
        <svg class="hidden h-5 w-5" data-theme-icon="dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3a6 6 0 1 0 9 9 9 9 0 1 1-9-9Z"/></svg>
        @if($navigationMenu)<span>Appearance</span>@endif
    </summary>
    <div class="navigation-theme-selector-menu {{ $navigationMenu ? 'mt-1 w-full' : 'absolute right-0 top-full z-[90] mt-2 w-52 shadow-2xl' }} rounded-2xl border border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-900" role="menu" aria-label="Color theme">
        @foreach([
            ['light', 'Light', '☀️'],
            ['paper', 'Paper garden', '🌿'],
            ['blue', 'Blue horizon', '💧'],
            ['red', 'Red alert', '🔥'],
            ['dark', 'Dark', '🌙'],
        ] as [$themeValue, $themeLabel, $themeEmoji])
            <button type="button" class="theme-selector-option flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left text-sm font-semibold hover:bg-slate-100 dark:hover:bg-slate-800" role="menuitemradio" aria-checked="false" data-theme-option="{{ $themeValue }}"><span class="text-lg" aria-hidden="true">{{ $themeEmoji }}</span><span class="flex-1">{{ $themeLabel }}</span><span class="opacity-0" data-theme-check aria-hidden="true">✓</span></button>
        @endforeach
    </div>
</details>
