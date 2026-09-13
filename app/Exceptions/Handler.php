<?php

namespace App\Exceptions;

use App\Support\AdminPath;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            $request = app()->bound('request') ? request() : null;

            if ($e instanceof TokenMismatchException
                || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 419)) {
                Log::warning('security.csrf_token_mismatch', [
                    'path' => $request?->path(),
                    'method' => $request?->method(),
                    'ip' => $request?->ip(),
                    'user_id' => optional($request?->user())->id,
                    'business_id' => optional($request?->user('business'))->id,
                    'session_id' => $request?->hasSession() ? $request->session()->getId() : null,
                ]);

                return;
            }

            if ($e instanceof AuthenticationException) {
                Log::warning('security.authentication_exception', [
                    'path' => $request?->path(),
                    'method' => $request?->method(),
                    'ip' => $request?->ip(),
                    'guards' => method_exists($e, 'guards') ? $e->guards() : [],
                    'session_id' => $request?->hasSession() ? $request->session()->getId() : null,
                ]);

                return;
            }

            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403) {
                Log::warning('security.http_403_forbidden', [
                    'path' => $request?->path(),
                    'method' => $request?->method(),
                    'ip' => $request?->ip(),
                    'user_id' => optional($request?->user())->id,
                    'business_id' => optional($request?->user('business'))->id,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Throwable $e)
    {
        $response = $this->renderCsrfSoftRecover($request, $e);
        if ($response !== null) {
            return $response;
        }

        return parent::render($request, $e);
    }

    /**
     * Laravel converts TokenMismatchException → HttpException(419) before renderable
     * callbacks run, so soft-recover must happen in render() itself.
     *
     * @return \Symfony\Component\HttpFoundation\Response|null
     */
    private function renderCsrfSoftRecover(Request $request, Throwable $e)
    {
        $isCsrf = $e instanceof TokenMismatchException
            || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 419);

        if (! $isCsrf) {
            return null;
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Your session expired. Refresh the page and try again.',
                'csrf_token' => $request->hasSession() ? csrf_token() : null,
            ], 419);
        }

        if (! $this->shouldSoftRecoverCsrf($request)) {
            return null;
        }

        return redirect()
            ->to($this->csrfRecoverUrl($request))
            ->withInput($request->except($this->dontFlash))
            ->with('error', 'Your session expired or cookies were blocked. Please sign in again.');
    }

    private function shouldSoftRecoverCsrf(Request $request): bool
    {
        return AdminPath::requestIsAdminPanel($request)
            || $request->is('investor', 'investor/*')
            || $request->is('dashboard', 'dashboard/*')
            || $request->is('my-account/login', 'my-account/login/*');
    }

    private function csrfRecoverUrl(Request $request): string
    {
        if (AdminPath::requestIsAdminPanel($request)) {
            return url(AdminPath::urlPrefix().'/login');
        }

        if ($request->is('investor/access/*') && $request->isMethod('POST')) {
            return $request->url();
        }

        if ($request->is('dashboard', 'dashboard/*')) {
            return route('business.login');
        }

        if ($request->is('my-account/login', 'my-account/login/*')) {
            return route('account.login');
        }

        $referer = $request->headers->get('referer');
        if (is_string($referer) && $referer !== '') {
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            $refHost = parse_url($referer, PHP_URL_HOST);
            if ($appHost && $refHost && strcasecmp((string) $appHost, (string) $refHost) === 0) {
                return $referer;
            }
        }

        if ($request->is('investor', 'investor/*')) {
            return route('investor.gate.lookup');
        }

        return url('/');
    }
}
