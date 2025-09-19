<?php

namespace App\Http\Controllers;

use App\Models\LoginAttempt;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /**
     * تطبيق وسائط المصادقة على المتحكم
     */
    public function __construct()
    {
     //   $this->middleware('auth');
    }

    /**
     * عرض لوحة التحكم الرئيسية
     */
    public function index()
    {
        // إحصائيات عامة
        $stats = [
            'total_questions' => Question::count(),
            'login_attempts' => LoginAttempt::count(),
            'failed_attempts' => LoginAttempt::where('successful', false)->count(),
            'successful_logins' => LoginAttempt::where('successful', true)->count(),
        ];

        // آخر 5 محاولات تسجيل دخول
        $recentAttempts = LoginAttempt::orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return view('dashboard', [
            'user' => Auth::user(),
            'stats' => $stats,
            'recentAttempts' => $recentAttempts
        ]);
    }
}
