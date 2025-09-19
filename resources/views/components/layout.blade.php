<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'نظام المساعدة' }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- استيراد الخط العربي -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Cairo', sans-serif;
        }
    </style>
    <!-- إضافة أي أنماط CSS إضافية هنا -->
    {{ $styles ?? '' }}
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Header navigation -->
    <header class="bg-white shadow">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center">
                <a href="/dashboard" class="text-2xl font-bold text-gray-800">نظام المساعدة</a>
            </div>

            @auth
            <div class="flex items-center space-x-4 space-x-reverse">
                <span class="text-gray-600">مرحباً، {{ Auth::user()->name }}</span>
                <form action="/logout" method="POST" class="inline">
                    @csrf
                    <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded">
                        تسجيل الخروج
                    </button>
                </form>
            </div>
            @endauth

            @guest
            <div>
                <a href="/login" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">
                    تسجيل الدخول
                </a>
            </div>
            @endguest
        </div>
    </header>

    <!-- Sidebar and main content -->
    <div class="container mx-auto px-4 py-8 flex flex-wrap">
        @auth
        <!-- Sidebar -->
        <aside class="w-full md:w-1/5 pl-4 mb-6 md:mb-0">
            <nav class="bg-white shadow rounded p-4">
                <ul class="space-y-2">
                    <li>
                        <a href="/dashboard" class="block py-2 px-4 rounded hover:bg-gray-100 {{ request()->is('dashboard') ? 'bg-gray-100 font-bold' : '' }}">
                            <i class="fas fa-tachometer-alt ml-2"></i> لوحة التحكم
                        </a>
                    </li>
                    <li>
                        <a href="/questions" class="block py-2 px-4 rounded hover:bg-gray-100 {{ request()->is('questions*') ? 'bg-gray-100 font-bold' : '' }}">
                            <i class="fas fa-question-circle ml-2"></i> الأسئلة والأجوبة
                        </a>
                    </li>
                    <li>
                        <a href="/messages" class="block py-2 px-4 rounded hover:bg-gray-100 {{ request()->is('messages*') ? 'bg-gray-100 font-bold' : '' }}">
                            <i class="fas fa-envelope ml-2"></i> الرسائل
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>
        @endauth

        <!-- Main content -->
        <main class="w-full {{ Auth::check() ? 'md:w-4/5' : '' }}">
            @if (session('status'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4" role="alert">
                {{ session('status') }}
            </div>
            @endif

            @if (session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4" role="alert">
                {{ session('error') }}
            </div>
            @endif

            {{ $slot }}
        </main>
    </div>

    <!-- Footer -->
    <footer class="bg-white shadow mt-8 py-4">
        <div class="container mx-auto px-4 text-center text-gray-600">
            &copy; {{ date('Y') }} نظام المساعدة. جميع الحقوق محفوظة.
        </div>
    </footer>

    <!-- إضافة أي سكريبتات JS إضافية هنا -->
    {{ $scripts ?? '' }}
</body>
</html>
