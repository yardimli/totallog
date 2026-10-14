<nav id="account-setup-tabs" class="overflow-x-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-sm dark:border-slate-800 dark:bg-slate-900" aria-label="Settings">
    <div id="account-setup-tab-list" class="flex min-w-max gap-1">
        <a class="nav-link {{ request()->routeIs('profile.*') ? 'nav-active' : '' }}" href="{{ route('profile.edit') }}" @if(request()->routeIs('profile.*')) aria-current="page" @endif>Account</a>
        <a class="nav-link {{ request()->routeIs('settings.edit', 'settings.update') ? 'nav-active' : '' }}" href="{{ route('settings.edit') }}" @if(request()->routeIs('settings.edit', 'settings.update')) aria-current="page" @endif>API & display</a>
        <a class="nav-link {{ request()->routeIs('settings.screensaver*') ? 'nav-active' : '' }}" href="{{ route('settings.screensaver') }}" @if(request()->routeIs('settings.screensaver*')) aria-current="page" @endif>Screensaver</a>
        <a class="nav-link {{ request()->routeIs('sensors.*') ? 'nav-active' : '' }}" href="{{ route('sensors.index') }}" @if(request()->routeIs('sensors.*')) aria-current="page" @endif>Sensors</a>
        <a class="nav-link {{ request()->routeIs('api-usage.*') ? 'nav-active' : '' }}" href="{{ route('api-usage.index') }}" @if(request()->routeIs('api-usage.*')) aria-current="page" @endif>API usage</a>
        @if(auth()->user()->is_admin)
            <a class="nav-link {{ request()->routeIs('admin.*') ? 'nav-active' : '' }}" href="{{ route('admin.users') }}" @if(request()->routeIs('admin.*')) aria-current="page" @endif>Admin</a>
        @endif
    </div>
</nav>
