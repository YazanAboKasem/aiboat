<?php

namespace App\Http\Controllers;

use App\Models\LoginAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * عرض صفحة تسجيل الدخول
     */

    public function showLoginForm()
    {
        if (Auth::check()) {
            return redirect('/dashboard');
        }

        return view('auth.login');
    }

    /**
     * معالجة طلب تسجيل الدخول
     */
    public function login(Request $request)
    {
        // التحقق من البيانات
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // معلومات محاولة تسجيل الدخول
        $ip = $request->ip();
        $userAgent = $request->header('User-Agent');
        $email = $request->email;

        // إنشاء سجل محاولة تسجيل دخول
        $attempt = [
            'email' => $email,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'successful' => false
        ];

        // محاولة تسجيل الدخول
        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            // تحديث سجل المحاولة
            $attempt['successful'] = true;
            $attempt['user_id'] = Auth::id();

            // حفظ المحاولة الناجحة
            LoginAttempt::create($attempt);

            // سجل تسجيل دخول ناجح
            Log::info('تم تسجيل دخول مستخدم بنجاح', [
                'user_id' => Auth::id(),
                'email' => Auth::user()->email,
                'ip' => $ip
            ]);

            return redirect()->intended('/dashboard');
        }

        // حفظ محاولة تسجيل الدخول الفاشلة
        LoginAttempt::create($attempt);

        // سجل فشل تسجيل الدخول
        Log::warning('فشل في محاولة تسجيل الدخول', [
            'email' => $email,
            'ip' => $ip
        ]);

        // فشل تسجيل الدخول
        return back()->withErrors([
            'email' => 'بيانات الاعتماد المقدمة غير صحيحة.',
        ])->onlyInput('email');
    }

    /**
     * تسجيل الخروج
     */
    public function logout(Request $request)
    {
        Log::info('تم تسجيل خروج مستخدم', [
            'user_id' => Auth::id(),
            'email' => Auth::user()->email
        ]);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /**
     * عرض لوحة التحكم
     */
    public function dashboard()
    {
        return view('dashboard');
    }
}
