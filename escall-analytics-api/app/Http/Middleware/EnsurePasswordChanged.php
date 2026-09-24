<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePasswordChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password) {
            return response()->json([
                'message' => 'Debes cambiar tu contraseña antes de continuar.',
                'mustChangePassword' => true,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
