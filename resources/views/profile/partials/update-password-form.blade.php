<section>
    <header>
        <h2 class="text-lg font-bold">
            {{ __('Update Password') }}
        </h2>

        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ __('Ensure your account is using a long, random password to stay secure.') }}
        </p>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('put')

        <div id="current-password-field">
            <label for="update_password_current_password" class="label">{{ __('Current Password') }}</label>
            <input id="update_password_current_password" name="current_password" type="password" class="input mt-1 block w-full" autocomplete="current-password">
            @if($errors->updatePassword->get('current_password'))<ul class="mt-2 space-y-1 text-sm font-medium text-rose-600 dark:text-rose-400">@foreach($errors->updatePassword->get('current_password') as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
        </div>

        <div id="new-password-field">
            <label for="update_password_password" class="label">{{ __('New Password') }}</label>
            <input id="update_password_password" name="password" type="password" class="input mt-1 block w-full" autocomplete="new-password">
            @if($errors->updatePassword->get('password'))<ul class="mt-2 space-y-1 text-sm font-medium text-rose-600 dark:text-rose-400">@foreach($errors->updatePassword->get('password') as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
        </div>

        <div id="new-password-confirmation-field">
            <label for="update_password_password_confirmation" class="label">{{ __('Confirm Password') }}</label>
            <input id="update_password_password_confirmation" name="password_confirmation" type="password" class="input mt-1 block w-full" autocomplete="new-password">
            @if($errors->updatePassword->get('password_confirmation'))<ul class="mt-2 space-y-1 text-sm font-medium text-rose-600 dark:text-rose-400">@foreach($errors->updatePassword->get('password_confirmation') as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
        </div>

        <div id="password-form-actions" class="flex items-center gap-4">
            <button type="submit" class="btn">{{ __('Save') }}</button>

            @if (session('status') === 'password-updated')
                <p class="text-sm text-slate-500 dark:text-slate-400" data-auto-dismiss>{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
