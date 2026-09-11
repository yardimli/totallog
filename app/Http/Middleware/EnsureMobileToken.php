<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureMobileToken
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(! $request->user()->is_guest && $request->user()->tokenCan('mobile'), 403);

        return $next($request);
    }
}
