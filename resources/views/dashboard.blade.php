<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>لوحة التحكم - نظام إدارة الأسئلة والأجوبة</title>
    <!-- دعم Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap');
        body {
            font-family: 'Tajawal', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-100 font-sans antialiased">
    <div class="min-h-screen flex flex-col">
        <!-- شريط التنقل العلوي -->

        <!-- المحتوى الرئيسي -->
        <main class="flex-1">
            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                <!-- شريط العنوان -->
                <div class="flex items-center justify-between mb-6">
                    <h1 class="text-2xl font-bold text-gray-900">
                        <i class="fas fa-tachometer-alt text-indigo-500 ml-2"></i>
                        لوحة التحكم
                    </h1>
                    <span class="text-sm text-gray-500">
                        <i class="far fa-clock ml-1"></i>
                        {{ now()->format('Y-m-d h:i A') }}
                    </span>
                </div>

                <!-- مؤشرات الإحصائيات -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                        <div class="p-5 border-b border-gray-200">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 bg-indigo-100 rounded-md p-3">
                                    <i class="fas fa-question-circle text-indigo-600 text-xl"></i>
                                </div>
                                <div class="mr-5">
                                    <div class="text-sm font-medium text-gray-500">إجمالي الأسئلة</div>
                                    <div class="mt-1 text-3xl font-semibold text-gray-900">{{ $stats['total_questions'] ?? 0 }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                        <div class="p-5 border-b border-gray-200">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 bg-green-100 rounded-md p-3">
                                    <i class="fas fa-key text-green-600 text-xl"></i>
                                </div>
                                <div class="mr-5">
                                    <div class="text-sm font-medium text-gray-500">تسجيلات الدخول الناجحة</div>
                                    <div class="mt-1 text-3xl font-semibold text-gray-900">{{ $stats['successful_logins'] ?? 0 }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                        <div class="p-5 border-b border-gray-200">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 bg-red-100 rounded-md p-3">
                                    <i class="fas fa-user-shield text-red-600 text-xl"></i>
                                </div>
                                <div class="mr-5">
                                    <div class="text-sm font-medium text-gray-500">محاولات دخول فاشلة</div>
                                    <div class="mt-1 text-3xl font-semibold text-gray-900">{{ $stats['failed_attempts'] ?? 0 }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                        <div class="p-5 border-b border-gray-200">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 bg-yellow-100 rounded-md p-3">
                                    <i class="fas fa-user-clock text-yellow-600 text-xl"></i>
                                </div>
                                <div class="mr-5">
                                    <div class="text-sm font-medium text-gray-500">إجمالي المحاولات</div>
                                    <div class="mt-1 text-3xl font-semibold text-gray-900">{{ $stats['login_attempts'] ?? 0 }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- أقسام لوحة التحكم -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- قسم الوصول السريع -->
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg lg:col-span-2">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h2 class="text-lg font-medium text-gray-900">
                                <i class="fas fa-bolt text-indigo-500 ml-2"></i>
                                الوصول السريع
                            </h2>
                        </div>
                        <div class="p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <a href="{{ route('questions.index') }}" class="block p-5 bg-indigo-50 hover:bg-indigo-100 rounded-lg shadow-sm transition-colors">
                                    <div class="flex items-center">
                                        <div class="rounded-full bg-indigo-100 p-3">
                                            <i class="fas fa-question text-indigo-600 text-xl"></i>
                                        </div>
                                        <div class="mr-4">
                                            <h3 class="text-lg font-semibold text-indigo-700">إدارة الأسئلة والأجوبة</h3>
                                            <p class="text-gray-600 mt-1">عرض وإضافة وتعديل وحذف الأسئلة والأجوبة</p>
                                        </div>
                                    </div>
                                </a>

                                <a href="{{ route('messages.index') }}" class="block p-5 bg-blue-50 hover:bg-blue-100 rounded-lg shadow-sm transition-colors">
                                    <div class="flex items-center">
                                        <div class="rounded-full bg-blue-100 p-3">
                                            <i class="fas fa-comments text-blue-600 text-xl"></i>
                                        </div>
                                        <div class="mr-4">
                                            <h3 class="text-lg font-semibold text-blue-700">إدارة الرسائل</h3>
                                            <p class="text-gray-600 mt-1">عرض والرد على الرسائل والاستفسارات</p>
                                        </div>
                                    </div>
                                </a>
                                <a href="{{ route('ai.settings') }}" class="btn btn-outline-success">
                                    <i class="fas fa-cog me-2"></i> إعدادات الذكاء الاصطناعي
                                    @if(isset($aiSettings))
                                        <span class="badge bg-info ms-2">
                                            {{ $aiSettings->where('key', 'ai_model')->first()->value === 'model_one' ? 'ChatGPT' : 'المساعد' }}
                                        </span>
                                    @endif
                                </a>
                                <a href="{{ route('messages.ask') }}" class="block p-5 bg-blue-50 hover:bg-blue-100 rounded-lg shadow-sm transition-colors">
                                    <div class="flex items-center">
                                        <div class="rounded-full bg-blue-100 p-3">
                                            <i class="fas fa-comments text-blue-600 text-xl"></i>
                                        </div>
                                        <div class="mr-4">
                                            <h3 class="text-lg font-semibold text-blue-700">الشات</h3>
                                            <p class="text-gray-600 mt-1">تجربة الشات</p>
                                        </div>
                                    </div>
                                </a>
                                <a href="{{ route('second.assistant') }}" class="list-group-item list-group-item-action">
                                    <i class="fas fa-robot"></i> إدارة المساعد الثاني
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- قسم آخر محاولات تسجيل الدخول -->
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h2 class="text-lg font-medium text-gray-900">
                                <i class="fas fa-history text-indigo-500 ml-2"></i>
                                آخر محاولات تسجيل الدخول
                            </h2>
                        </div>
                        <div class="p-6">
                            <ul class="divide-y divide-gray-200">
                                @forelse($recentAttempts as $attempt)
                                <li class="py-3">
                                    <div class="flex items-center">
                                        <div class="{{ $attempt->successful ? 'text-green-500' : 'text-red-500' }} mr-2">
                                            <i class="fas {{ $attempt->successful ? 'fa-check-circle' : 'fa-times-circle' }}"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600">{{ $attempt->email }}</p>
                                            <div class="flex text-xs text-gray-500 mt-1">
                                                <span class="ml-2">
                                                    <i class="fas fa-globe ml-1"></i>
                                                    {{ $attempt->ip_address }}
                                                </span>
                                                <span>
                                                    <i class="far fa-clock ml-1"></i>
                                                    {{ $attempt->created_at->diffForHumans() }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                                @empty
                                <li class="py-3 text-center text-gray-500">
                                    <i class="fas fa-info-circle ml-1"></i>
                                    لا توجد محاولات تسجيل دخول حتى الآن
                                </li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- الفوتر -->
        <footer class="bg-white border-t border-gray-200 py-4 mt-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center">
                    <div class="text-sm text-gray-500">
                        جميع الحقوق محفوظة &copy; {{ date('Y') }} نظام إدارة الأسئلة والأجوبة
                    </div>
                    <div class="text-sm text-gray-500">
                        <span class="ml-1">إصدار</span>
                        <span class="text-indigo-600">1.0.0</span>
                    </div>
                </div>
            </div>
        </footer>
    </div>

    <script>
        // التبديل بين إظهار وإخفاء قائمة الهاتف
        document.getElementById('mobile-menu-button').addEventListener('click', function() {
            const mobileMenu = document.getElementById('mobile-menu');
            mobileMenu.classList.toggle('hidden');
        });
    </script>
</body>
</html>
