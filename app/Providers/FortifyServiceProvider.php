<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // NostosEMR is fully passwordless — we disable Fortify's built-in
        // authentication views and only use our custom OTP flow.
        Fortify::loginView(fn () => inertia('Auth/Login'));
    }
}
