<section class="space-y-6">
    <header>
        <h2 class="text-lg font-bold">
            {{ __('Delete Account') }}
        </h2>

        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
        </p>
    </header>

    <button type="button" class="inline-flex items-center rounded-xl border border-transparent bg-rose-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition hover:bg-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2" data-account-delete-open>{{ __('Delete Account') }}</button>

    <dialog class="w-full max-w-2xl rounded-2xl bg-white p-0 text-slate-900 shadow-xl backdrop:bg-slate-950/60 dark:bg-slate-900 dark:text-slate-100" data-account-delete-dialog @if($errors->userDeletion->isNotEmpty()) data-open="true" @endif>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="text-lg font-bold">
                {{ __('Are you sure you want to delete your account?') }}
            </h2>

            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
            </p>

            <div id="account-deletion-password-field" class="mt-6">
                <label for="delete_account_password" class="sr-only">{{ __('Password') }}</label>
                <input id="delete_account_password" name="password" type="password" class="input mt-1 block w-3/4" placeholder="{{ __('Password') }}">
                @if($errors->userDeletion->get('password'))<ul class="mt-2 space-y-1 text-sm font-medium text-rose-600 dark:text-rose-400">@foreach($errors->userDeletion->get('password') as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
            </div>

            <div id="account-deletion-actions" class="mt-6 flex justify-end">
                <button type="button" class="btn-secondary" data-account-delete-close>{{ __('Cancel') }}</button>
                <button type="submit" class="ms-3 inline-flex items-center rounded-xl border border-transparent bg-rose-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition hover:bg-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">{{ __('Delete Account') }}</button>
            </div>
        </form>
    </dialog>
</section>
