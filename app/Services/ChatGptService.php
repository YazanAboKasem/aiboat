<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatGptService
{
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private float $temperature;
    private KeywordExtractorService $keywordExtractor;

    public function __construct(
        KeywordExtractorService $keywordExtractor,
        ?string $apiKey = null,
        ?string $model = null,
        ?int $maxTokens = null,
        ?float $temperature = null
    ) {
        $this->keywordExtractor = $keywordExtractor;
        $this->apiKey = $apiKey ?? config('services.openai.key', env('OPENAI_API_KEY'));
        $this->model = $model ?? env('OPENAI_CHAT_MODEL', 'gpt-4-turbo');
        $this->maxTokens = $maxTokens ?? 1000;
        $this->temperature = $temperature ?? 0.7;
    }

    /**
     * إرسال سؤال والبيانات المتاحة إلى ChatGPT للحصول على إجابة
     *
     * @param string $userQuestion سؤال المستخدم
     * @param array $relatedData بيانات ذات صلة من قاعدة البيانات (أسئلة وأجوبة)
     * @return array ['answer' => string, 'source' => string, 'confidence' => float, 'keywords' => array, 'lex_map' => array]
     * @throws \Exception
     */
    public function getAnswer(string $userQuestion, array $relatedData): array
    {
        // تنسيق البيانات المرتبطة لتقديمها إلى ChatGPT
        $formattedData = $this->formatRelatedData($relatedData);

        // إنشاء محتوى الرسالة مع تعليمات واضحة
        $messages = [
            [
                'role' => 'system',
                'content' => "أنت مساعد محادثة مختص في الإجابة على الأسئلة بناءً على البيانات المقدمة فقط.\n\n" .
                    "إرشادات هامة:\n" .
                    "1. استخدم فقط المعلومات المقدمة في البيانات بشكل كامل.\n" .
                    "2. عند صياغة الإجابة النهائية، حدد بصراحة الايديات المرفقة بالأسئلة التي استندت إليها لإنتاج الإجابة.\n" .
                    "3. إذا كنت لا تستطيع الإجابة، أجب بـ \"غير موجود\".\n" .
                    "4. قدم النتيجة بصيغة JSON بالشكل التالي:\n\n" .
                    "{\n" .
                    "  \"answer\": \"الإجابة النهائية هنا\",\n" .
                    "  \"used_questions\": [\n" .
                    "    {\"id\": 1},\n" .
                    "    {\"id\": 2}\n" .
                    "  ]\n" .
                    "}\n\n" .
                    "توضيح إضافي: أي أسئلة تشمل \"الموقع\" أو \"التوقيت\" المقصود بها هو موقع أو توقيت \"المهرجان\" الذي ننظمه. على سبيل المثال: \"أين موقعكم؟\" يعني \"أين موقع المهرجان؟\" و\"متى تفتحون؟\" تعني \"متى يفتح المهرجان؟\"."
            ],
            [
                'role' => 'user',
                'content' => "السؤال: $userQuestion\n\n" .
                    "البيانات المتاحة:\n$formattedData"
            ]
        ];



        try {
            // إرسال الطلب إلى ChatGPT
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
            ])->throw()->json();

            // استخراج الإجابة

            // تحديد مستوى الثقة بناءً على محتوى الإجابة

            $content = $response['choices'][0]['message']['content'] ?? '';

            $result = json_decode($content, true);

            $answer = $result['answer'];
            Log::error('ChatGPT API data', [
                'error' => $result,
                'userQuestion' => $userQuestion
            ]);
            $usedQuestions = $result['used_questions']; // قائمة الأسئلة المستعملة
            $confidence = 0.0;
            if (preg_match('/(غير موجود|لا أستطيع الإجابة|لا توجد معلومات كافية|لا يمكنني تقديم إجابة)/i', $answer)) {
                $confidence = 0.0; // تطابق غير موجود
            } else {
                $confidence = 0.85; // تطابق قريب أو مؤكد
            }


            // تحديث قاعدة البيانات إذا تم العثور على تطابق
            $lexMap = [];
            foreach ($usedQuestions as $usedQuestion) {
                $question = \App\Models\Question::find($usedQuestion['id']);
                if ($question) {
                    $analysis = $this->enhanceQuestion($userQuestion,$question['content']); // تحليل الكلمات المفتاحية وقاموس الاستبدال
                    $lexMap = $analysis['lex_map'];

                    $question->update([
                        'lex_map' => $lexMap,
                    ]);
                }
            }


            // إرجاع البيانات مع معرف السؤال المطابق

            return [
                'answer' => $answer,
                'source' => 'AI-DB' ,
                'confidence' => $confidence,
                'matched_question_id' => 1,
                'lex_map' => $lexMap,
            ];

        } catch (\Throwable $e) {
            // تسجيل الخطأ إذا فشل الاتصال مع ChatGPT
            Log::error('ChatGPT API error', [
                'error' => $e->getMessage(),
                'userQuestion' => $userQuestion
            ]);

            throw new \Exception('فشل الاتصال بـ ChatGPT: ' . $e->getMessage());
        }
    }

    /**
     * تحسين السؤال باستخدام الكلمات المفتاحية والمرادفات
     * هذه الوظيفة تستخدم في تحديث الأسئلة لتحسين دقة التطابق المستقبلية
     *
     * @param string $question السؤال الأصلي
     * @return array ['keywords' => array, 'lex_map' => array]
     */
    public function enhanceQuestion(string $userQuestion, string $originalQuestion = null): array
    {
        try {
            // إنشاء المسؤولية التوجيهية:

            $systemPrompt = "أنت مساعد متخصص. تقوم بمقارنة نصوص الأسئلة الآتية وتحديد الكلمات التي تظهر فقط في النص الأول وغير موجودة في الثاني.\n\n" .
                "1. أعطِ قاموس الكلمات التي تظهر فقط في النص الأول، مع مكافئات لها من النص الثاني.\n" .
                "2. إذا لم تُوجد مكافئات، تأكد من تسجيل الكلمة فقط.\n" .
                "أعد النتائج بصيغة JSON فقط كالشكل التالي:\n" .
                "{\n" .
                "  \"lex_map\": {\"كلمة1\": \"مكافئ1\", \"كلمة2\": \"مكافئ2\"}\n" .
                "}";

            // إضافة السؤال (الأساسي + المستخدم)
            $messages = [
                [
                    'role' => 'system',
                    'content' => $systemPrompt
                ],
                [
                    'role' => 'user',
                    'content' => "السؤال الوارد من المستخدم: {$userQuestion}\n" .
                        ($originalQuestion ? "السؤال الأصلي المخزن: {$originalQuestion}" : "")
                ]

            ];

            // إرسال الطلب إلى ChatGPT
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'max_tokens' => 500,
                'temperature' => 0.3,
            ])->throw()->json();

            $content = $response['choices'][0]['message']['content'] ?? '';

            // استخراج JSON من النص إذا كان يحتوي على أجزاء إضافية
            if (preg_match('/({.*})/s', $content, $matches)) {
                $jsonStr = $matches[1];
            } else {
                $jsonStr = $content;
            }

            $result = json_decode($jsonStr, true);

            // التحقق من صحة النتيجة
            if (!$result ||  !isset($result['lex_map'])) {
                Log::warning('Failed to parse ChatGPT enhancement response', [
                    'content' => $content
                ]);

                // استخدام الطريقة التقليدية كبديل
                $lexMap = $this->keywordExtractor->generateLexMap($userQuestion);

                return [
                    'original_question' => $originalQuestion,
                    'user_question' => $userQuestion,
                    'lex_map' => $lexMap
                ];
            }

            return [
                'original_question' => $originalQuestion,
                'user_question' => $userQuestion,
                'lex_map' => $result['lex_map']
            ];

        } catch (\Throwable $e) {
            // تسجيل أي استثناء عند الفشل
            Log::error('Error enhancing question with ChatGPT', [
                'error' => $e->getMessage(),
                'userQuestion' => $userQuestion,
                'originalQuestion' => $originalQuestion
            ]);

            // بديل حالة الفشل
            $lexMap = $this->keywordExtractor->generateLexMap($userQuestion);

            return [
                'original_question' => $originalQuestion,
                'user_question' => $userQuestion,
                'lex_map' => $lexMap
            ];
        }
    }

    /**
     * تنسيق البيانات المرتبطة لتقديمها إلى ChatGPT
     *
     * @param array $relatedData
     * @return string
     */
    private function formatRelatedData(array $relatedData): string
    {
        $formatted = '';

        foreach ($relatedData as $index => $item) {
            $num = $item['id'];
            $question = $item['question'] ?? 'سؤال غير معروف';
            $answer = $item['answer'] ?? 'إجابة غير معروفة';

            // إضافة الكلمات المفتاحية إذا كانت موجودة
            $keywordsStr = '';
            if (!empty($item['keywords']) && is_array($item['keywords'])) {
                $keywordsStr = "- الكلمات المفتاحية: " . implode(', ', $item['keywords']) . "\n";
            }

            $formatted .= "المعلومات رقم $num:\n";
            $formatted .= "- السؤال: $question\n";
            $formatted .= "- الإجابة: $answer\n";
            $formatted .= $keywordsStr;
            $formatted .= "\n";
        }

        return $formatted ?: 'لا توجد بيانات متاحة.';
    }
}
