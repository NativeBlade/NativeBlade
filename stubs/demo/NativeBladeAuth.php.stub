<?php

namespace App\Http\Middleware;

use Closure;
use NativeBlade\Facades\NativeBlade;

class NativeBladeAuth
{
    public function handle($request, Closure $next)
    {
        $user = NativeBlade::getState('auth.user');

        if (!$user) {
            return NativeBlade::navigate('/login')->toResponse();
        }

        return $next($request);
    }
}
