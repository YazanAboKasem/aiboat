<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>تسجيل الدخول - نظام إدارة الأسئلة والأجوبة</title>
    <!-- دعم Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap');
        body {
            font-family: 'Tajawal', sans-serif;
        }
        .login-bg {
            background-image: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .login-container {
            backdrop-filter: blur(10px);
        }
    </style>
</head>
<body class="login-bg font-sans antialiased min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full bg-white/90 backdrop-blur-sm rounded-lg shadow-2xl p-8 login-container">
        <div class="text-center mb-8">
            <div class="inline-flex justify-center items-center w-16 h-16 rounded-full bg-indigo-100 text-indigo-500 mb-4">
                <i class="fas fa-lock text-2xl"></i>
            </div>
            <h2 class="text-3xl font-bold text-gray-800">تسجيل الدخول</h2>
            <p class="text-gray-600 mt-2">نظام إدارة الأسئلة والأجوبة</p>
        </div>
<x-layout>
    <x-slot:title>تسجيل الدخول</x-slot:title>

    <div class="w-full max-w-md mx-auto">
        <div class="bg-white shadow-md rounded px-8 pt-6 pb-8 mb-4">
            <h2 class="text-2xl font-bold mb-6 text-center">تسجيل الدخول</h2>

            <form method="POST" action="/login" class="space-y-6">
                @csrf

                <div>
                    <label for="email" class="block text-gray-700 text-sm font-bold mb-2">البريد الإلكتروني</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email') }}"
                        required
                        autocomplete="email"
                        autofocus
                        class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline @error('email') border-red-500 @enderror"
                    >
                    @error('email')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-gray-700 text-sm font-bold mb-2">كلمة المرور</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline @error('password') border-red-500 @enderror"
                    >
                    @error('password')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center">
                    <input
                        id="remember"
                        name="remember"
                        type="checkbox"
                        class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                        {{ old('remember') ? 'checked' : '' }}
                    >
                    <label for="remember" class="mr-2 block text-sm text-gray-700">تذكرني</label>
                </div>

                <div>
                    <button
                        type="submit"
                        class="w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline"
                    >
                        تسجيل الدخول
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-layout>
        @if ($errors->any())
            <div class="bg-red-100 border-r-4 border-red-500 text-red-700 px-4 py-3 rounded mb-6 shadow-sm">
                <div class="flex">
                    <div class="py-1"><i class="fas fa-exclamation-circle text-red-500 mr-3"></i></div>
                    <div>
                        <ul class="list-inside">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif


        <div class="mt-6 text-center">
            <p class="text-sm text-gray-600">
                <i class="fas fa-shield-alt text-indigo-500 ml-1"></i>
                تم تأمين هذا الموقع وتشفير جميع البيانات
            </p>
        </div>
    </div>
</body>
</html>
