<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDemoIsReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_guest && $request->isMethodSafe() && ! $request->routeIs(
            'demo.index',
            'demo.enter',
            'dashboard',
            'calendar',
            'logs.today',
            'logs.show',
            'search.index',
            'attachments.show',
            'goals.index',
            'goals.show',
            'emojis.index',
        )) {
            return redirect()->route('calendar')->with('status', 'That area is unavailable in the read-only demo.');
        }

        $canLeaveDemo = $request->routeIs('demo.leave', 'logout');
        if ($request->user()?->is_guest && ! $request->isMethodSafe() && ! $canLeaveDemo) {
            abort(403, 'The demo is read-only. Sign up to save your own entries and settings.');
        }

        return $next($request);
    }
}
