<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Assistant extends Model
{
    use HasFactory;

    protected $fillable = [
        'assistant_id',
        'vector_store_id',
        'name',
    ];

    /**
     * استعلام التعامل مع الخطأ عند عدم وجود الجدول
     *
     * @param  mixed  $query
     * @return mixed
     */
    public function scopeSafe($query)
    {
        if (Schema::hasTable('assistants')) {
            return $query;
        }

        // إرجاع استعلام فارغ إذا لم يكن الجدول موجوداً
        return $query->whereRaw('1 = 0');
    }

    /**
     * الحصول على المساعد الأول بأمان
     */
    public static function safeFirst()
    {
        if (!Schema::hasTable('assistants')) {
            return null;
        }

        try {
            $assistant = self::first();

            // إذا لم يتم العثور على سجل، إرجاع null
            if (!$assistant) {
                return null;
            }

            return $assistant;
        } catch (\Exception $e) {
            \Log::error('خطأ في الوصول إلى جدول المساعدين: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * إنشاء سجل المساعد أو الحصول عليه بأمان
     */
    public static function safeGetOrCreate($name = 'المساعد الثاني')
    {
        if (!Schema::hasTable('assistants')) {
            return null;
        }

        try {
            $assistant = self::first();

            if (!$assistant) {
                $assistant = new self();
                $assistant->name = $name;
                $assistant->save();
            }

            return $assistant;
        } catch (\Exception $e) {
            \Log::error('خطأ في إنشاء سجل المساعد: ' . $e->getMessage());
            return null;
        }
    }
    public function saveOrReplace(Request $request)
    {
        // التحقق من صحة المدخلات
        $request->validate([
            'name' => 'required|string',
            'assistant_id' => 'required|string',
            'vector_store_id' => 'required|string',
        ]);

        // حذف جميع السجلات الموجودة
        Assistant::truncate();

        // إنشاء السجل الجديد فقط
        Assistant::create([
            'name' => $request->input('name'),
            'assistant_id' => $request->input('assistant_id'),
            'vector_store_id' => $request->input('vector_store_id'),
        ]);

        return null;
    }

}
