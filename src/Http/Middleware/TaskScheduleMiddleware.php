<?php
declare(strict_types=1);
namespace DagaSmart\TaskSchedule\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;

class TaskScheduleMiddleware
{
    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next)
    {
        // API 限流：每分钟最多 60 次请求
        $key = 'task-schedule:' . ($request->user()?->id ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json([
                'code' => 429,
                'message' => '请求过于频繁，请稍后再试',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        // 记录请求日志
        Log::channel('stack')->debug('Task Schedule API Request', [
            'method' => $request->method(),
            'path' => $request->path(),
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
        ]);

        $response = $next($request);

        // 添加响应头
        $response->headers->set('X-Scheduler-Version', '1.0.0');

        return $response;
    }
}
