<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     * Para API, sempre retornamos null para forçar resposta 401 JSON
     */
    protected function redirectTo(Request $request): ?string
    {
        // Como é uma API separada, nunca fazemos redirect
        return null;
    }
}
