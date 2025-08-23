<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class FallbackController extends Controller
{
    public function __invoke(Request $request)
    {
        $url = $request->fullUrl();
        $method = $request->method();
        $allRoutes = collect(Route::getRoutes())->map(function ($route) {
            return [
                'methods' => $route->methods(),
                'uri' => $route->uri(),
                'name' => $route->getName(),
            ];
        });

        return response()->json([
            'error' => 'Not Found',
            'message' => "The requested URL {$url} was not found on this server.",
            'requested_method' => $method,
            'registered_routes' => $allRoutes,
        ], 404);
    }
}
