@extends('errors.minimal')

@section('title', __('Page introuvable'))

{{-- What the reader most often did: follow a link to an announcement
     that was withdrawn, or one a mail client cut in half. Saying it
     leaves them something to do; "Not Found" alone does not. --}}
@section('lead', __('Cette adresse ne correspond à aucune page du service. Le lien est peut-être tronqué, ou l\'annonce a été retirée.'))
