<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class KeywordExtractorService
{
    /**
     * استخراج الكلمات المفتاحية من النص
     *
     * @param string $text
     * @return array
     */
    public function extract(string $text): array
    {
        try {
            // قائمة كلمات غير مهمة في اللغة العربية (stop words)
            $stopWords = [
                'من', 'إلى', 'عن', 'على', 'في', 'مع', 'هذا', 'هذه', 'تلك', 'ذلك',
                'أنا', 'نحن', 'هو', 'هي', 'هم', 'أنت', 'أنتم', 'أنتن',
                'الذي', 'التي', 'الذين', 'هل', 'ما', 'ماذا', 'كيف', 'لماذا', 'متى',
                'أين', 'أي', 'كم', 'أو', 'ثم', 'لكن', 'و', 'ف', 'ل', 'ب',
                'يمكن', 'يجب', 'كان', 'ليس', 'كانت', 'سوف', 'هناك', 'عندي', 'عندنا',
                'لكم', 'لنا', 'انتو', 'انتم', 'بتقدرو', 'بتقدر', 'ممكن', 'تقدر', 'بدي',
                'بدنا', 'عايز', 'عايزين', 'أريد', 'نريد', 'بس', 'فقط', 'شي', 'شيء'
            ];

            // تنظيف النص
            $text = $this->cleanText($text);

            // تقسيم النص إلى كلمات
            $words = preg_split('/\s+/u', $text);

            // تصفية الكلمات الغير مهمة والكلمات القصيرة
            $filteredWords = array_filter($words, function($word) use ($stopWords) {
                // تحويل الكلمة إلى الحروف الصغيرة للمقارنة مع قائمة الكلمات التوقف
                $wordLower = mb_strtolower($word, 'UTF-8');
                return !in_array($wordLower, $stopWords) && mb_strlen($wordLower, 'UTF-8') > 2;
            });

            // إحصاء تكرار الكلمات
            $wordCount = array_count_values($filteredWords);

            // ترتيب الكلمات حسب التكرار (تنازلي)
            arsort($wordCount);

            // استخراج أهم الكلمات المفتاحية (أقصى 15 كلمات)
            $keywords = array_slice(array_keys($wordCount), 0, 15);

            // إضافة كلمات مفتاحية إضافية مرتبطة بالنص
            $additionalKeywords = $this->extractAdditionalKeywords($text);
            $allKeywords = array_unique(array_merge($keywords, $additionalKeywords));

            // تسجيل الكلمات المفتاحية للتأكد من العملية
            Log::info('Keywords extracted', [
                'text' => mb_substr($text, 0, 50) . '...',
                'keywords' => $allKeywords,
                'count' => count($allKeywords)
            ]);

            return $allKeywords;
        } catch (\Exception $e) {
            Log::error('Error extracting keywords', [
                'error' => $e->getMessage(),
                'text' => $text
            ]);

            return [];
        }
    }

    /**
     * استخراج كلمات مفتاحية إضافية مرتبطة بالسياق
     *
     * @param string $text
     * @return array
     */
    private function extractAdditionalKeywords(string $text): array
    {
        $additionalKeywords = [];

        // مجالات محددة يمكن أن تكون ذات صلة بالسؤال
        $domains = [
            // سياحة
            'سياحة' => ['سياحة', 'زيارة', 'سفر', 'رحلة', 'سائح', 'سياحي'],
            'متحف' => ['متحف', 'متاحف', 'معرض', 'قطع أثرية', 'آثار'],
            'حديقة' => ['حديقة', 'منتزه', 'بارك', 'متنزه'],
            'معلم' => ['معلم', 'معالم', 'مزار', 'مزارات', 'شهير', 'مشهور'],
            'فندق' => ['فندق', 'إقامة', 'سكن', 'نزل'],

            // مواصلات
            'مواصلات' => ['مواصلات', 'نقل', 'تنقل'],
            'سيارة' => ['سيارة', 'تاكسي', 'أجرة'],
            'قطار' => ['قطار', 'مترو', 'ترام'],
            'باص' => ['باص', 'حافلة', 'أتوبيس'],

            // أنشطة
            'نشاط' => ['نشاط', 'فعالية', 'حدث', 'مهرجان'],
            'تسوق' => ['تسوق', 'سوق', 'مول', 'محل'],
            'مطعم' => ['مطعم', 'أكل', 'طعام', 'مأكولات'],

            // وقت
            'مواعيد' => ['موعد', 'مواعيد', 'توقيت', 'أوقات'],
            'فتح' => ['فتح', 'يفتح', 'مفتوح'],
            'إغلاق' => ['إغلاق', 'يغلق', 'مغلق'],

            // أسعار
            'سعر' => ['سعر', 'تكلفة', 'ثمن', 'تذكرة'],
            'خصم' => ['خصم', 'تخفيض', 'عرض', 'أوفر'],
        ];

        // البحث عن الكلمات المفتاحية ذات الصلة بالمجالات
        foreach ($domains as $key => $relatedWords) {
            foreach ($relatedWords as $word) {
                if (mb_stripos($text, $word) !== false) {
                    $additionalKeywords[] = $key;
                    break; // نضيف المجال مرة واحدة فقط
                }
            }
        }

        return $additionalKeywords;
    }

    /**
     * إنشاء قاموس المرادفات
     *
     * @param string $text
     * @return array
     */
    public function generateLexMap(string $text): array
    {
        try {
            // قائمة من المرادفات الشائعة في اللغة العربية
            // المفتاح هو الكلمة المستخدمة في السؤال والقيمة هي المرادف المعياري
            $commonSynonyms = [
                // الوجود
                'يوجد' => 'موجود',
                'متوفر' => 'موجود',
                'عندكم' => 'موجود',
                'عندك' => 'موجود',
                'لديكم' => 'موجود',
                'متواجد' => 'موجود',
                'في' => 'موجود',
                'فيه' => 'موجود',
                'فيها' => 'موجود',
                'عندكو' => 'موجود',

                // السعر
                'سعر' => 'تكلفة',
                'ثمن' => 'تكلفة',
                'كلفة' => 'تكلفة',
                'يكلف' => 'تكلفة',
                'بكم' => 'تكلفة',
                'بكام' => 'تكلفة',
                'كام' => 'تكلفة',
                'تذكرة' => 'تكلفة',

                // الموقع
                'مكان' => 'موقع',
                'موضع' => 'موقع',
                'محل' => 'موقع',
                'وين' => 'موقع',
                'فين' => 'موقع',
                'أين' => 'موقع',
                'عنوان' => 'موقع',
                'مقر' => 'موقع',

                // الوقت
                'وقت' => 'زمن',
                'متى' => 'زمن',
                'ساعة' => 'زمن',
                'زمان' => 'زمن',
                'توقيت' => 'زمن',
                'موعد' => 'زمن',
                'مواعيد' => 'زمن',
                'امتى' => 'زمن',
                'إيمتى' => 'زمن',

                // الطريقة
                'كيف' => 'طريقة',
                'طريق' => 'طريقة',
                'أسلوب' => 'طريقة',
                'كيفية' => 'طريقة',
                'ازاي' => 'طريقة',
                'إزاي' => 'طريقة',

                // لهجات محلية وتعابير شائعة
                'شلون' => 'كيف',
                'منين' => 'من أين',
                'منوين' => 'من أين',
                'لوين' => 'إلى أين',
                'علوين' => 'إلى أين',

                // الاستفسار
                'استفسار' => 'سؤال',
                'استعلام' => 'سؤال',
                'معلومة' => 'سؤال',
                'معلومات' => 'سؤال',
            ];

            // تنظيف النص
            $text = $this->cleanText($text);

            // إنشاء قاموس المرادفات بناءً على النص المدخل
            $lexMap = [];

            foreach ($commonSynonyms as $word => $standardForm) {
                // إذا كانت الكلمة موجودة في النص، أضفها إلى القاموس
                if (mb_stripos($text, $word) !== false) {
                    $lexMap[$word] = $standardForm;
                }
            }

            // تسجيل القاموس للتأكد من العملية
            Log::info('Lex map generated', [
                'text' => mb_substr($text, 0, 50) . '...',
                'lex_map' => $lexMap,
                'count' => count($lexMap)
            ]);

            return $lexMap;
        } catch (\Exception $e) {
            Log::error('Error generating lex map', [
                'error' => $e->getMessage(),
                'text' => $text
            ]);

            return [];
        }
    }

    /**
     * تنظيف النص من العلامات والرموز غير المرغوبة
     *
     * @param string $text
     * @return string
     */
    private function cleanText(string $text): string
    {
        // تحويل إلى unicode والتخلص من النصوص الخاصة
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // إزالة علامات الترقيم والرموز الخاصة
        $text = preg_replace('/[^\p{Arabic}\p{Latin}0-9\s]/u', ' ', $text);

        // توحيد الـ Whitespace
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
