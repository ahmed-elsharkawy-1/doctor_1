<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * No test reaches a real provider. A test that forgets `Http::fake()`
     * fails loudly instead of spending an SMS credit — which is what a local
     * `.env` pointed at ZADX quietly did.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
