<?php

use App\Platform\Exceptions\ApiExceptionRenderer;
use App\Platform\Http\Middleware\AttachRequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/*
| Les routes des modules ne sont pas déclarées ici : chaque module enregistre
| les siennes depuis son ServiceProvider, sur son propre sous-domaine.
|
| @see app/Platform/Support/ModuleServiceProvider.php
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            AttachRequestId::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(new ApiExceptionRenderer);
    })->create();
