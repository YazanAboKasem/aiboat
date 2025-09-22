<?php

namespace App\Http\Controllers;

use App\Models\LoginAttempt;
use App\Models\Question;
use App\Models\Assistant;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
set_time_limit(120);

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

        // الحصول على إعدادات الذكاء الاصطناعي
        $aiSettings = null;

        if (Schema::hasTable('settings')) {
            $aiSettings = Setting::getGroup('ai');
        }

        return view('dashboard', [
            'user' => Auth::user(),
            'stats' => $stats,
            'recentAttempts' => $recentAttempts,
            'aiSettings' => $aiSettings
        ]);
    }

    /**
     * عرض صفحة إدارة إعدادات الذكاء الاصطناعي
     */
    public function manageAISettings()
    {
        // التحقق من وجود جدول الإعدادات
        $tableExists = true;

        try {
            if (!\Schema::hasTable('settings')) {
                $tableExists = false;
                \Log::warning('جدول settings غير موجود في قاعدة البيانات');
            }
        } catch (\Exception $e) {
            \Log::error('خطأ أثناء التحقق من جدول الإعدادات: ' . $e->getMessage());
            $tableExists = false;
        }

        // الحصول على إعدادات الذكاء الاصطناعي
        $currentModel = 'assistant'; // القيمة الافتراضية

        if ($tableExists) {
            $currentModel = Setting::get('ai_model', 'assistant');
        }

        // التحقق من وجود المساعد
        $assistant = Assistant::safeFirst();

        return view('dashboard.ai-settings', [
            'tableExists' => $tableExists,
            'currentModel' => $currentModel,
            'assistant' => $assistant
        ]);
    }

    /**
     * تحديث إعدادات الذكاء الاصطناعي
     */
    public function updateAISettings(Request $request)
    {
        // التحقق من وجود جدول الإعدادات
        if (!\Schema::hasTable('settings')) {
            // محاولة إنشاء جدول الإعدادات
            try {
                Artisan::call('migrate', ['--path' => 'database/migrations/2025_09_21_000000_create_settings_table.php']);
                \Log::info('تم إنشاء جدول الإعدادات بنجاح');
            } catch (\Exception $e) {
                \Log::error('فشل في إنشاء جدول الإعدادات: ' . $e->getMessage());
                return back()->with('error', 'فشل في إنشاء جدول الإعدادات. يرجى تشغيل الهجرة يدويًا: php artisan migrate');
            }
        }

        // التحقق من صحة المدخلات
        $request->validate([
            'ai_model' => 'required|in:model_one,assistant'
        ]);

        // تحديث إعداد نموذج الذكاء الاصطناعي
        $success = Setting::set('ai_model', $request->input('ai_model'));

        if (!$success) {
            return back()->with('error', 'فشل في تحديث إعدادات الذكاء الاصطناعي');
        }

        return back()->with('success', 'تم تحديث إعدادات الذكاء الاصطناعي بنجاح');
    }

    /**
     * عرض صفحة إدارة المساعد الثاني
     */
    public function manageSecondAssistant()
    {
        // التحقق من وجود جدول assistants
        $tableExists = true;
        $assistant = null;

        try {
            // محاولة الوصول إلى المساعد دون التحقق المسبق
            $assistant = Assistant::safeFirst();

            // إذا لم نستطع الوصول إلى المساعد، نتحقق من وجود الجدول
            if ($assistant === null && !\Schema::hasTable('assistants')) {
                $tableExists = false;
                \Log::warning('جدول assistants غير موجود في قاعدة البيانات');
            }
        } catch (\Exception $e) {
            // تسجيل الخطأ
            \Log::error('خطأ أثناء الوصول إلى جدول المساعدين: ' . $e->getMessage());
            $tableExists = false;
        }

        // قراءة الملف من التخزين - التحقق من وجود الملف
        $filePath = storage_path('app/company_knowledge.txt');
        $content = '';
        $fileExists = false;

        try {
            // محاولة قراءة الملف
            if (file_exists($filePath)) {
                $content = file_get_contents($filePath);
                $fileExists = true;
            } else {
                // إنشاء ملف فارغ إذا لم يكن موجودًا
                $content = "# معلومات المساعد الثاني\nأدخل المعلومات هنا، سطر واحد لكل معلومة.";
                file_put_contents($filePath, $content);
                $fileExists = true; // الآن أصبح الملف موجوداً
            }
        } catch (\Exception $e) {
            // إذا حدث خطأ في القراءة
            $content = "# حدث خطأ في قراءة الملف\nسيتم إنشاء ملف جديد عند الحفظ.";
        }

        return view('dashboard.second-assistant', [
            'content' => $content,
            'fileExists' => $fileExists,
            'assistant' => $assistant,
            'tableExists' => $tableExists
        ]);
    }

    /**
     * تحديث المساعد الثاني
     */
    public function updateSecondAssistant(Request $request)
    {
        // التحقق من صحة المدخلات
        $request->validate([
            'content' => 'required',
            'vector_store_id' => 'nullable|string',
            'skip_vector_setup' => 'nullable|boolean'
        ]);

        // تحديث محتوى الملف
        $filePath = storage_path('app/company_knowledge.txt');
        file_put_contents($filePath, $request->input('content'));

        // تأكيد تحديث الملف أولاً
        if (!file_exists($filePath)) {
            return back()->with('error', 'فشل في حفظ ملف المعرفة.');
        }

        try {
            // البحث عن المساعد الحالي أو إنشاء واحد جديد
//            Assistant::truncate();
//
//            $assistant = Assistant::firstOrNew([]);
//            $assistant->name = 'المساعد الثاني';

            // التحقق مما إذا كان المستخدم قد وفر vector_store_id مخصص
            $customVectorStoreId = $request->input('vector_store_id');
            $skipVectorSetup = $request->boolean('skip_vector_setup');
            if (!empty($customVectorStoreId)) {
                // استخدام معرف مخزن المتجهات المخصص المقدم من المستخدم
              //  $assistant->vector_store_id = $customVectorStoreId;
              //  $assistant->save();

                // استمرار مباشرة إلى إنشاء المساعد
                $vectorStoreId = $customVectorStoreId;
            }
            elseif ($skipVectorSetup && !empty($assistant->vector_store_id)) {
                // استخدام معرف مخزن المتجهات الموجود إذا طلب المستخدم تخطي الإعداد
                $vectorStoreId = $assistant->vector_store_id;
            } else {
                // استدعاء endpoint /vector-store/setup بمهلة أطول
                try {
                    $vectorStoreController = new VectorStoreController();

                    // Invoke the setup method - it's expected to return the response directly
                    $vectorStoreSetupResponse = $vectorStoreController->setup($request);
                    $responseData = $vectorStoreSetupResponse->getData(true); // Convert JSON response to an associative array

                    if (!isset($responseData['vector_store_id'])) {
                        \Log::error('فشل إعداد مخزن المتجهات: ', $responseData); // Log the full response for debugging

                        return back()->with('warning', 'تم تحديث ملف المعرفة، ولكن فشل إعداد مخزن المتجهات، جرب معرف مخصص.');
                    }
                    $vectorStoreId = $responseData['vector_store_id'];


                    // Save the vector store ID
//                    $assistant->vector_store_id = $vectorStoreId;
//                    $assistant->save();
                } catch (\Exception $e) {
                    \Log::error('فشل إعداد مخزن المتجهات: ' . $e->getMessage());

                    if (!empty($assistant->vector_store_id)) {
                        return back()->with('warning', 'تم تحديث ملف المعرفة، ولكن فشل إعداد مخزن المتجهات. جرب معرف مخزن المتجهات الحالي.');
                    }

                    return back()->with('warning', 'فشل إعداد مخزن المتجهات: ' . $e->getMessage());
                }

            }

            // إذا وصلنا إلى هنا، فلدينا معرف مخزن متجهات للاستخدام

            // محاولة إنشاء المساعد
            try {
                $response = Http::timeout(30)
                    ->retry(2, 1000)
                    ->post(route('assistant_create'), [
                        'vector_store_id' => $vectorStoreId
                    ]);

                if (!$response->successful() || !isset($response['assistant_id'])) {
                    \Log::warning('تم تحديث مخزن المتجهات ولكن فشل إنشاء المساعد', [
                        'status' => $response->status(),
                        'body' => $response->body()
                    ]);

                    return back()->with('warning', 'تم تحديث ملف المعرفة ومخزن المتجهات، ولكن فشل إنشاء المساعد: ' .
                        ($response['error'] ?? 'خطأ غير معروف'));
                }

                // حفظ معرف المساعد
                $assistant->assistant_id = $response['assistant_id'];
                $assistant->save();

                return back()->with('success', 'تم تحديث المساعد الثاني بنجاح.');
            } catch (\Exception $e) {
                \Log::error('فشل إنشاء المساعد: ' . $e->getMessage());

                return back()->with('warning', 'تم تحديث ملف المعرفة ومخزن المتجهات (ID: ' . $vectorStoreId . ')، ' .
                    'ولكن فشل إنشاء المساعد: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            \Log::error('خطأ أثناء تحديث المساعد: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            // تأكيد تحديث الملف على الأقل
            return back()->with('warning', 'تم تحديث ملف المعرفة، ولكن حدث خطأ أثناء تحديث المساعد: ' . $e->getMessage());
        }
    }
}
