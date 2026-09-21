@extends('layouts.guest')

@section('title', __('Validation de l\'adresse'))

@section('content')
    <h1 class="text-xl font-semibold tracking-tight">{{ __('Validation de l\'adresse') }}</h1>

    <p class="mt-3 text-slate-700 dark:text-slate-200">
        {{ __('Un lien de validation a été envoyé à votre adresse. Il n\'est valable qu\'une fois.') }}
    </p>

    @auth
        <form method="POST" action="{{ route('verification.resend') }}" class="mt-5">
            @csrf
            <button type="submit" class="btn btn-primary w-full">{{ __('Renvoyer le courriel de validation') }}</button>
        </form>
    @endauth
@endsection
