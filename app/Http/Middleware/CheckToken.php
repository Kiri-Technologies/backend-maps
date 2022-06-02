<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CheckToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // check if bearer token exists
        if (!$request->bearerToken()) {
            return response()->json([
                'message' => 'Missing bearer token'
            ], 401);
        }

        $response = Http::withToken(
            $request->bearerToken()
        )->get(
            env('API_ENDPOINT') . 'profile',
        );

        // if data exist return data if not return false
        if (!$response->successful()) {
            return response()->json([
                'message' => 'Unauthorized: Your Token Expired.'
            ], 401);
        }

        $request->attributes->add(['user' => json_decode($response->body(), true)]);
        return $next($request);
    }
}
