<section aria-labelledby="github-sync-report-title" class="overflow-hidden rounded-2xl border border-slate-200 dark:border-slate-700">
    <header class="border-b border-slate-200 p-4 dark:border-slate-700">
        <h3 id="github-sync-report-title" class="font-bold">Latest 20 commits</h3>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Latest commits returned by GitHub for your linked account. “New” means inserted during the sync shown below.</p>
        @if($report)
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Last successful manual sync: {{ \Carbon\Carbon::parse($report['synced_at'])->setTimezone(config('app.timezone'))->format('M j, Y') }} at {{ auth()->user()->formatTime(\Carbon\Carbon::parse($report['synced_at'])->setTimezone(config('app.timezone'))) }}</p>
        @endif
    </header>
    @if(!$report)
        <p class="p-4 text-sm text-slate-500">Click Sync now to fetch commits and see which have been added to your logs.</p>
    @else
        <ol class="max-h-96 overflow-y-auto divide-y divide-slate-200 dark:divide-slate-700" tabindex="0" aria-label="Recent GitHub sync results">
            @forelse($report['commits'] as $commit)
                @php($date = \Carbon\Carbon::parse($commit['occurred_at'])->setTimezone(config('app.timezone')))
                <li class="flex flex-wrap items-start gap-3 p-4 text-sm">
                    <div class="block-github-sync-details min-w-0 flex-1 basis-48">
                        <p class="break-words font-semibold">{{ $commit['message'] ?: 'Commit '.substr($commit['sha'], 0, 7) }}</p>
                        <p class="mt-1 break-all text-xs text-slate-500 dark:text-slate-400">{{ $commit['repository'] ?: $commit['project'] }} · {{ substr($commit['sha'], 0, 7) }}</p>
                        <a class="mt-1 inline-block text-xs font-semibold text-indigo-600 dark:text-indigo-400" href="{{ route('logs.show', $date->toDateString()) }}"><time datetime="{{ $date->toIso8601String() }}">{{ $date->format('D, M j, Y') }} · {{ auth()->user()->formatTime($date) }}</time></a>
                    </div>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold {{ $commit['status'] === 'new' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">{{ $commit['status'] === 'new' ? 'New' : 'Already inserted' }}</span>
                </li>
            @empty
                <li class="p-4 text-sm text-slate-500">GitHub returned no matching commits. Check that the linked token has access to the repositories you expect.</li>
            @endforelse
        </ol>
    @endif
</section>
