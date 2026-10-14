@extends('layouts.settings')

@section('settings-title', 'Account')
@section('settings-description', 'Manage your profile, password, and account.')

@section('settings-content')
            <div id="profile-information-panel" class="panel">
                <div id="profile-information-content" class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div id="password-update-panel" class="panel">
                <div id="password-update-content" class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div id="account-deletion-panel" class="panel">
                <div id="account-deletion-content" class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
@endsection
