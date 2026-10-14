<section>
    <header>
        <h2 class="text-lg font-bold">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div id="profile-name-field">
            <label for="name" class="label">{{ __('Name') }}</label>
            <input id="name" name="name" type="text" class="input mt-1 block w-full" value="{{ old('name', $user->name) }}" required autofocus autocomplete="name">
            @if($errors->get('name'))<ul class="mt-2 space-y-1 text-sm font-medium text-rose-600 dark:text-rose-400">@foreach($errors->get('name') as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
        </div>

        <div id="profile-email-field">
            <label for="email" class="label">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" class="input mt-1 block w-full" value="{{ old('email', $user->email) }}" required autocomplete="username">
            @if($errors->get('email'))<ul class="mt-2 space-y-1 text-sm font-medium text-rose-600 dark:text-rose-400">@foreach($errors->get('email') as $message)<li>{{ $message }}</li>@endforeach</ul>@endif

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div id="email-verification-message">
                    <p class="text-sm mt-2 text-gray-800">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="underline text-sm text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:text-slate-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-sm text-green-600">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div id="profile-form-actions" class="flex items-center gap-4">
            <button type="submit" class="btn">{{ __('Save') }}</button>

            @if (session('status') === 'profile-updated')
                <p class="text-sm text-slate-500 dark:text-slate-400" data-auto-dismiss>{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
