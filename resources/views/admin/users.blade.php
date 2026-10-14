@extends('layouts.settings')

@section('settings-title', 'Users')
@section('settings-description', 'Manage registered users and the shared demo.')

@section('settings-content')
        <section id="admin-users-heading" class="panel flex flex-wrap items-start gap-4">
            <div id="admin-users-heading-copy" class="min-w-0 flex-1">
                <p class="text-xs font-bold uppercase tracking-wider text-indigo-600">Administration</p>
                <h2 class="text-lg font-bold">User administration</h2>
                <p class="mt-2 text-sm text-slate-500">Review registered users and reset the shared read-only demo.</p>
            </div>
            <form method="POST" action="{{ route('admin.demo-data.destroy') }}" data-confirm-demo-delete>
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-secondary border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-800 dark:text-amber-300 dark:hover:bg-amber-950">Reset demo data</button>
            </form>
        </section>

        <section id="admin-user-list" class="panel overflow-hidden p-0">
            <div id="admin-user-summary" class="border-b border-slate-200 px-4 py-3 text-sm text-slate-500 dark:border-slate-800">
                {{ $users->total() }} {{ Str::plural('user', $users->total()) }} · {{ $demoUserCount }} demo
            </div>
            <div id="admin-user-table-wrap" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm dark:divide-slate-800">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:bg-slate-900">
                        <tr><th class="px-4 py-3">User</th><th class="px-4 py-3">Access</th><th class="px-4 py-3">Logs</th><th class="px-4 py-3">Events</th><th class="px-4 py-3">Created</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        @forelse($users as $listedUser)
                            <tr>
                                <td class="px-4 py-3"><strong class="block">{{ $listedUser->name }}</strong><span class="text-xs text-slate-500">{{ $listedUser->email }}</span></td>
                                <td class="px-4 py-3"><div class="navigation-admin-user-access flex flex-wrap gap-1">@if($listedUser->is_admin)<span class="rounded-full bg-indigo-100 px-2 py-1 text-xs font-bold text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200">Admin</span>@endif @if($listedUser->is_guest)<span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-200">Demo</span>@elseif(! $listedUser->is_admin)<span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">User</span>@endif</div></td>
                                <td class="px-4 py-3">{{ $listedUser->daily_logs_count }}</td>
                                <td class="px-4 py-3">{{ $listedUser->task_definitions_count }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $listedUser->created_at->format('M j, Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No users found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($users->hasPages())<div id="admin-user-pagination" class="border-t border-slate-200 px-4 py-3 dark:border-slate-800">{{ $users->links() }}</div>@endif
        </section>
@endsection
