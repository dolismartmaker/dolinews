@extends('errors.minimal')

@section('title', __('Erreur du service'))

{{-- Same concern as the maintenance page: someone landing here wants to
     know whether they lost something. A submission in progress is the
     only thing they could have lost, and the feed never is. --}}
@section('lead', __('Le service a rencontré une erreur inattendue, qui a été enregistrée. Les annonces publiées, les flux et les abonnements ne sont pas affectés.'))
