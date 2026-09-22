<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Without a Vite build, any test rendering a view would fail on
        // the @vite directive (socle pitfall 6): public pages carry no
        // bundle at all, admin uses Livewire's CDN-free scripts.
        $this->withoutVite();

        // Symfony's test request carries 'en-us,en;q=0.5' by default,
        // and the interface now negotiates the language (SetLocale):
        // without this, every suite would silently assert against the
        // English interface. The reference browser here speaks the
        // source language; the negotiation tests override the header.
        $this->withHeaders(['Accept-Language' => 'fr']);
    }
}
