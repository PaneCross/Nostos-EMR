<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'department.access' => \App\Http\Middleware\CheckDepartmentAccess::class,
            'audit.log'         => \App\Http\Middleware\LogAuditEvent::class,
            'fhir.auth'         => \App\Http\Middleware\FhirAuthMiddleware::class,
            'site.context'      => \App\Http\Middleware\SiteContextMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Render custom Inertia error pages for 404, 500, and 503 HTTP errors.
        // 403 is handled directly by CheckDepartmentAccess (renders Errors/403 inline).
        // 503 renders without AppShell layout since the app may not be fully booted.
        $exceptions->respond(function (Response $response) {
            $status = $response->getStatusCode();
            if (in_array($status, [404, 500, 503]) && ! app()->runningInConsole()) {
                return Inertia::render("Errors/{$status}")
                    ->toResponse(request())
                    ->setStatusCode($status);
            }
            return $response;
        });
    })->create();
