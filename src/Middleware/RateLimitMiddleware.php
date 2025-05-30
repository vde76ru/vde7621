<?php
namespace App\Middleware;

use App\Core\Logger;

class ApiMiddleware
{
    public static function handle(string $route, callable $next)
    {
        // Используем RateLimitMiddleware вместо RateLimiter
        if (!RateLimitMiddleware::handle(null, 100, 60)) {
            return; // RateLimitMiddleware уже отправил ответ
        }
        
        // CORS headers
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        
        // Logging
        $startTime = microtime(true);
        $result = $next();
        
        Logger::info('API request', [
            'route' => $route,
            'duration' => microtime(true) - $startTime
        ]);
        
        return $result;
    }
}