@extends('layouts.guest')

@section('content')
    <h1>{{ __('Validation de l\'adresse') }}</h1>

    <p>{{ __('Un lien de validation a été envoyé à votre adresse. Il n\'est valable qu\'une fois.') }}</p>

    @auth
        <form method="POST" action="{{ route('verification.resend') }}" class="stack" style="margin-top:1rem">
            @csrf
            <button type="submit">{{ __('Renvoyer le courriel de validation') }}</button>
        </form>
    @endauth
@endsection
