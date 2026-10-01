<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLivePipelineLabEnabled
{
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        if (
            ! (bool) config(
                'live_pipeline.lab_enabled',
                false,
            )
        ) {
            abort(404);
        }

        return $next($request);
    }
}
