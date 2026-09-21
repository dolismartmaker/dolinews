{{-- Frame of the account area: the same left-hand navigation as the
     back-office, inside the public chrome.

     The header and the footer stay, unlike the admin layout: this area also
     belongs to a plain reader managing subscriptions, and dropping them would
     leave them with no way back to the feed. --}}
@extends('layouts.public')

@section('content')
    <div class="lg:grid lg:grid-cols-[15rem_1fr] lg:gap-8">
        <aside class="mb-6 lg:mb-0">
            @include('partials.account-nav')
        </aside>

        <div class="min-w-0">
            @yield('account')
        </div>
    </div>
@endsection
