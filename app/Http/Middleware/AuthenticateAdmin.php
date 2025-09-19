<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticateAdmin
{
    /**
     * التعامل مع الطلب الوارد.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // التحقق من تسجيل دخول المستخدم
        if (!Auth::check()) {
            return redirect('/login');
        }

        // يمكن إضافة المزيد من التحقق هنا (مثل التحقق من دور المستخدم)

        return $next($request);
    }
}
