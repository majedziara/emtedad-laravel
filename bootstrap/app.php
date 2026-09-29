<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__ . '/../routes/web.php', api: __DIR__ . '/../routes/api.php', commands: __DIR__ . '/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn(Request $request) => $request->is('api/*') ? null : '/');
        $middleware->api(prepend: [SetApiLocale::class]);
        $middleware->alias(['active' => EnsureAccountIsActive::class, 'verified' => EnsureEmailIsVerified::class, 'role' => RoleMiddleware::class, 'permission' => PermissionMiddleware::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['success' => false, 'message' => __('content.conflict')], 409);
            }
        });
        $exceptions->shouldRenderJsonWhen(fn(Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson());
        $exceptions->respond(function (Response $response) {
            if (request()->is('api/*') && $response->getStatusCode() >= 400) {
                $original = json_decode($response->getContent(), true) ?: [];
                $body = ['success' => false, 'message' => $response->getStatusCode() >= 500 ? __('api.server_error') : ($original['message'] ?? __('api.request_failed'))];
                if (isset($original['errors'])) {
                    $body['errors'] = $original['errors'];
                }
                $response->setContent(json_encode($body, JSON_UNESCAPED_UNICODE));
                $response->headers->set('Content-Type', 'application/json');
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->remove('Content-Length');
            }

            return $response;
        });
    })->create();
