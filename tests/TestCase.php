<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Disable CSRF token verification for all feature tests.
        // CSRF is a browser-protection mechanism; API/feature tests bypass it
        // by design. In production, APP_ENV=testing + runningInConsole() should
        // bypass CSRF automatically, but this ensures portability when tests run
        // outside the Docker environment (IDE runners, CI, local PHP, etc.).
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }
}

