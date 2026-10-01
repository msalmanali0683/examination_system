<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global (not just the web group) so 404s, redirects and other error responses carry the headers too.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A URL pointing at a record that no longer exists (e.g. a bookmarked or already-open session,
        // room, etc. that's since been deleted) — a bare 404 leaves the admin stuck; send them
        // somewhere useful instead. Left alone for requests that actually want JSON, and for a 404
        // that ISN'T a deleted record (a route that never matched at all stays a plain 404) — the
        // framework rewraps a route-model-binding failure as NotFoundHttpException(..., $original)
        // before any render() callback sees it (see Handler::prepareException()), so the only way to
        // tell the two apart here is the wrapped previous exception.
        // Built with `new RedirectResponse(...)` directly rather than the redirect() helper: inside a
        // Livewire full-page component's own mount() (e.g. a manual findOrFail() on a plain route
        // parameter, as opposed to implicit model binding, which fails before Livewire even starts),
        // Livewire has already rebound the 'redirect' service to its own Features\SupportRedirects\
        // Redirector, whose to()/route() return $this instead of a real response — fine for a
        // genuine Livewire action, but not usable as an HTTP exception handler's return value.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($e->getPrevious() instanceof ModelNotFoundException && ! $request->expectsJson()) {
                session()->flash('error', 'That page no longer exists — it may have been deleted.');

                return new RedirectResponse(route('dashboard'));
            }
        });

        // A stale CSRF token almost always means the session itself died underneath the open tab
        // (expired, or the app was redeployed) — the default 419 "Page Expired" page is a dead end,
        // since re-submitting just fails the same way. Routing to login is also what the user would
        // land on anyway the moment they refresh, since the session being gone signs them out too.
        // Same prepareException() rewrapping as above: a TokenMismatchException arrives here as a
        // plain HttpException with status 419, so that status is what's actually checked.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() === 419 && ! $request->expectsJson()) {
                session()->flash('status', 'Your session expired — please log in again.');

                return new RedirectResponse(route('login'));
            }
        });
    })->create();
