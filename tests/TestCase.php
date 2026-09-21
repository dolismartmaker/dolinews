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
    }
}
