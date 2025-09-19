<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Services\ChatGptService;
use App\Services\EmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AskController extends Controller
{
    /**
     * POST /api/ask
     * Body: { "q": "..." } أو { "question": "..." }
     */
    public function ask(Request $request, EmbeddingService $svc, ChatGptService $chatGpt): JsonResponse
    {

        $text = $request->input('q') ?? $request->input('question');
        $translate_text = $chatGpt->translateTextV3($text,"ar");

        $userText = $translate_text['data']['translations'][0]['translatedText'];
        $userLang = $translate_text['data']['translations'][0]['detectedSourceLanguage'];


        if (!is_string($userText) || trim($userText) === '') {
            return response()->json(['error' => 'الرجاء إرسال الحقل q (أو question) بنص صحيح.'], 422);
        }

        // 1) Embedding لسؤال المستخدم
        try {
            $userEmbedding = $svc->embed($userText);
        } catch (Throwable $e) {
            Log::error('Embedding API failed for /api/ask', ['error' => $e->getMessage()]);
            return response()->json([
                'error'   => 'تعذّر الاتصال بمحرك الـ Embeddings حالياً.',
                'details' => 'تأكد من OPENAI_API_KEY و OPENAI_EMBED_MODEL ثم أعد المحاولة.'
            ], 502);
        }

        if (!is_array($userEmbedding) || count($userEmbedding) === 0) {
            return response()->json(['error' => 'لم أتمكن من توليد تمثيل دلالي للسؤال المُرسَل.'], 400);
        }

        // 2) اجلب المرشحين (مع الحقول الجديدة)
        $candidates = Question::where(function ($q) {
            $q->whereNotNull('title_embedding')
                ->orWhereNotNull('content_embedding');
        })
            ->get(['id','title','content','answer','intent',
                'title_embedding','content_embedding',
                'keywords','lex_map']);

        if ($candidates->isEmpty()) {
            return response()->json([
                'match_question' => null,
                'answer'         => null,
                'similarity'     => 0,
                'message'        => 'لا توجد أسئلة مضمّنة بعد. شغّل: php artisan embeddings:backfill-questions'
            ], 200);
        }

        // 3) اكتشاف نية تقريبية لعمل Boost في الترتيب
        $detectedIntent = $this->detectIntent($userText);

        // أوزان المزج
        $alpha = 0.72; // دلالي
        $beta  = 0.13; // لغوي
        $gamma = 0.10; // Intent
        $delta = 0.05; // Keywords Boost

        // نحضّر نسخة مطبّعة من نص المستخدم لاستخدامها في القياس اللفظي والكلمات المفتاحية
        $userNorm = $this->normalizeAr(mb_strtolower($userText));

        $topK = [];
        foreach ($candidates as $cand) {
            // 3.1 عرض السؤال
            $displayQ = trim(implode(' — ', array_filter([
                is_string($cand->title) ? trim($cand->title) : null,
                is_string($cand->content) ? trim($cand->content) : null,
            ]))) ?: ($cand->title ?? '—');

            // 3.2 تشابه دلالي (أعلى من العنوان/المحتوى) + تطبيع إلى [0..1]
            $cosTitle = (is_array($cand->title_embedding) && $cand->title_embedding)
                ? EmbeddingService::cosine($userEmbedding, $cand->title_embedding) : 0.0;
            $cosCont  = (is_array($cand->content_embedding) && $cand->content_embedding)
                ? EmbeddingService::cosine($userEmbedding, $cand->content_embedding) : 0.0;

            $simTitle = $this->toUnit($cosTitle);
            $simCont  = $this->toUnit($cosCont);
            $sim_d    = max($simTitle, $simCont);

            // 3.3 lex_map: استبدال ديناميكي قبل القياس اللفظي (على نسخة مؤقتة فقط)
            $lexMap = is_array($cand->lex_map) ? $cand->lex_map : [];
            $candBlobRaw = (($cand->title ?? '') . ' ' . ($cand->content ?? ''));
            $candNorm    = $this->normalizeAr(mb_strtolower($candBlobRaw));

            if (!empty($lexMap)) {
                $userLex = $this->applyLexMap($userNorm, $lexMap);
                $candLex = $this->applyLexMap($candNorm, $lexMap);
            } else {
                $userLex = $userNorm;
                $candLex = $candNorm;
            }

            // 3.4 تشابه لغوي بسيط (Jaccard) على النص الموحّد
            $sim_l = $this->lexicalOverlap($userLex, $candLex);

            // 3.5 Intent boost
            $sim_i = ($detectedIntent && $cand->intent === $detectedIntent) ? 1.0 : 0.0;

            // 3.6 Keywords boost
            $keywords = is_array($cand->keywords) ? $cand->keywords : [];
            $kwBoost  = $this->keywordsBoost($userLex, $keywords); // 0..1 صغير

            // 3.7 الدرجة النهائية
            $score = $alpha*$sim_d + $beta*$sim_l + $gamma*$sim_i + $delta*$kwBoost;

            $topK[] = [
                'id'         => $cand->id,
                'question'   => $displayQ,
                'answer'     => $cand->answer,
                'similarity' => round($score, 4),
                'parts'      => [
                    'semantic' => round($sim_d, 4),
                    'lexical'  => round($sim_l, 4),
                    'intent'   => $sim_i,
                    'keywords' => round($kwBoost, 4),
                ],
                // إضافة المعلومات التي سنحتاجها لإرسالها إلى ChatGPT
                'content'    => $cand->content,
                'keywords'   => $keywords,
            ];
        }

        // 4) ترتيب تنازلي
        usort($topK, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);

        // 5) عتبة منطقية بعد التطبيع (جرّب 0.80–0.86)
        $THRESHOLD = 0.70;
        $best = $topK[0];

        if ($best['similarity'] >= $THRESHOLD) {


            $t_match_question = $chatGpt->translateTextV3($best['question'],$userLang);
            $t_answer = $chatGpt->translateTextV3($best['answer'],$userLang);
            $match_question = $t_match_question['data']['translations'][0]['translatedText'];
            $answer=  $t_answer['data']['translations'][0]['translatedText'];


            Log::error('ChatGPT vvvvvvvvAPI failed', [
                'userLang' => $userLang,
                'match_question' => $answer,
            ]);
            return response()->json([
                'match_question' => $match_question,
                'answer'         => $answer,
                'similarity'     => $best['similarity'],
                'parts'          => $best['parts'],
                'alternatives'   => array_slice($topK, 1, 3),
            ], 200);
        }

        // إذا لم يتم العثور على تطابق كافٍ، سنرسل البيانات إلى ChatGPT
        try {
            // تحضير البيانات ذات الصلة لإرسالها إلى ChatGPT
            // سنستخدم أفضل 5 مرشحين حسب درجة التشابه
            $relatedData = [];
            foreach (array_slice($topK, 0, 12) as $index => $candidate) {
                $relatedData[] = [
                    'id'       => $candidate['id'],
                    'question' => $candidate['question'],
                    'answer'   => $candidate['answer'],
                    'content'  => $candidate['content'] ?? null,
                    'keywords' => $candidate['keywords'] ?? [],
                ];
            }

            // استدعاء خدمة ChatGPT مع السؤال والبيانات ذات الصلة

            $chatGptResult = $chatGpt->getAnswer($userText, $relatedData,$userLang);

            // تحقق من وجود إجابة من ChatGPT
            if (isset($chatGptResult['answer']) && !empty($chatGptResult['answer'])) {
                // إرجاع الإجابة من ChatGPT
                return response()->json([
                    'match_question' => null,
                    'answer'         => $chatGptResult['answer'],
                    'similarity'     => $chatGptResult['confidence'] ?? 0,
                    'source'         => $chatGptResult['source'] ?? 'AI-DB',
                    'ai_generated'   => true,
                    'suggestions'    => array_slice($topK, 0, 5),
                    'message'        => 'تم توليد الإجابة باستخدام الذكاء الاصطناعي.',
                ], 200);
            }
        } catch (Throwable $e) {
            Log::error('ChatGPT API failed', [
                'error' => $e->getMessage(),
                'userQuestion' => $userText
            ]);
        }

        // إذا فشلت عملية ChatGPT أو لم تُرجع إجابة، سنعود إلى الإجابة الأصلية
        return response()->json([
            'match_question' => null,
            'answer'         => null,
            'similarity'     => $best['similarity'],
            'parts'          => $best['parts'],
            'suggestions'    => array_slice($topK, 0, 5),
            'message'        => 'لم أجد تطابقًا بثقة كافية.',
        ], 200);
    }    /** تحويل Cosine [-1..1] إلى [0..1] */
    private function toUnit(float $cos): float
    {
        $x = ($cos + 1.0) / 2.0;
        return max(0.0, min(1.0, $x));
    }

    /** تطبيع عربي خفيف */
    private function normalizeAr(string $t): string
    {
        $t = preg_replace('/[ًٌٍَُِّْـ]/u', '', $t); // إزالة التشكيل
        $t = str_replace(['أ','إ','آ'], 'ا', $t);     // توحيد الألف
        $t = str_replace(['ى'], 'ي', $t);            // توحيد الألف المقصورة
        $t = str_replace(['ة'], 'ه', $t);            // تاء مربوطة
        return preg_replace('/\s+/u', ' ', trim($t));
    }

    /** تطبيق قاموس الاستبدال (من => إلى) على النص */
    private function applyLexMap(string $text, array $lexMap): string
    {
        // نستبدل المفاتيح الأطول أولاً لتجنب تداخل الجزئيات (مثلاً "كيف اوصل" قبل "اوصل")
        uksort($lexMap, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($lexMap as $from => $to) {
            $from = $this->normalizeAr(mb_strtolower($from));
            $to   = $this->normalizeAr(mb_strtolower($to));
            if ($from === '') continue;
            $text = str_replace($from, $to, $text);
        }
        return $text;
    }

    /** تشابه لغوي بسيط (Jaccard) بعد التطبيع */
    private function lexicalOverlap(string $a, string $b): float
    {
        $A = array_values(array_unique(preg_split('/\s+/u', $a)));
        $B = array_values(array_unique(preg_split('/\s+/u', $b)));
        if (!$A || !$B) return 0.0;
        $setB = array_flip($B);
        $hits = 0;
        foreach ($A as $w) if (isset($setB[$w])) $hits++;
        return $hits / (count($A) + count($B) - $hits); // Jaccard
    }

    private function keywordsBoost(string $userTextNorm, array $keywords): float
    {
        if (empty($keywords)) return 0.0;

        // نطبع الكلمات المفتاحية ثم نحسب تطابقها مع نص المستخدم (وجود substring بسيط)
        $hits = 0; $seen = [];
        foreach ($keywords as $kw) {
            if (!is_string($kw)) continue;
            $k = $this->normalizeAr(mb_strtolower($kw));
            if ($k === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            if (str_contains($userTextNorm, $k)) {
                $hits++;
            }
        }

        if ($hits === 0) return 0.0;
        // سقف بسيط: كلمة واحدة = 0.5، كلمتان فأكثر = 1.0
        return $hits === 1 ? 0.5 : 1.0;
    }

    /**
     * تحديث سؤال موجود أو إنشاء سؤال جديد بإجابة من ChatGPT
     * وإضافة الكلمات المفتاحية وقاموس المرادفات
     */
    public function updateWithChatGptAnswer(Request $request)
    {
        $validated = $request->validate([
            'original_question' => 'required|string|max:255',
            'chatgpt_answer'    => 'required|string',
            'keywords'          => 'nullable|string',
            'lex_map'           => 'nullable|string',
            'intent'            => 'nullable|string',
        ]);

        // استخراج الكلمات المفتاحية من السؤال الأصلي إذا لم يتم توفيرها
        $providedKeywords = !empty($validated['keywords'])
            ? $this->parseKeywordString($validated['keywords'])
            : [];

        // استخراج كلمات مفتاحية تلقائيًا إذا لم يتم توفير أي كلمات
        $extractedKeywords = empty($providedKeywords)
            ? $this->keywordExtractor->extract($validated['original_question'])
            : [];

        // دمج الكلمات المفتاحية
        $keywords = array_unique(array_merge($providedKeywords, $extractedKeywords));

        // استخراج قاموس المرادفات من السؤال إذا لم يتم توفيره
        $providedLexMap = !empty($validated['lex_map'])
            ? $this->parseLexMapString($validated['lex_map'])
            : [];

        // استخراج قاموس مرادفات تلقائيًا إذا لم يتم توفير أي قاموس
        $extractedLexMap = empty($providedLexMap)
            ? $this->keywordExtractor->generateLexMap($validated['original_question'])
            : [];

        // دمج قواميس المرادفات
        $lexMap = array_merge($providedLexMap, $extractedLexMap);

        // نوع السؤال (intent)
        $intent = $validated['intent'] ?? $this->detectIntent($validated['original_question']);

        // البحث عن سؤال موجود بنفس العنوان
        $existingQuestion = Question::where('title', $validated['original_question'])->first();

        try {
            if ($existingQuestion) {
                // دمج الكلمات المفتاحية والمرادفات مع البيانات الموجودة
                $currentKeywords = $existingQuestion->keywords ?? [];
                if (!is_array($currentKeywords)) {
                    $currentKeywords = [];
                }

                $currentLexMap = $existingQuestion->lex_map ?? [];
                if (!is_array($currentLexMap)) {
                    $currentLexMap = [];
                }

                $mergedKeywords = array_unique(array_merge($currentKeywords, $keywords));
                $mergedLexMap = array_merge($currentLexMap, $lexMap);

                // تحديث السؤال الموجود
                $existingQuestion->update([
                    'answer' => $validated['chatgpt_answer'],
                    'keywords' => $mergedKeywords,
                    'lex_map' => $mergedLexMap,
                    'intent' => $intent ?? $existingQuestion->intent,
                ]);

                // تحديث الـ embeddings
                $this->updateEmbeddings($existingQuestion);

                $updatedQuestion = $existingQuestion;

            } else {
                // إنشاء سؤال جديد
                $newQuestion = Question::create([
                    'title' => $validated['original_question'],
                    'content' => $validated['original_question'],
                    'answer' => $validated['chatgpt_answer'],
                    'keywords' => $keywords,
                    'lex_map' => $lexMap,
                    'intent' => $intent,
                ]);

                // إنشاء الـ embeddings
                $this->updateEmbeddings($newQuestion);

                $updatedQuestion = $newQuestion;
            }

            // تسجيل للمراقبة والتأكد من حفظ البيانات
            \Illuminate\Support\Facades\Log::info('Question updated with ChatGPT answer', [
                'id' => $updatedQuestion->id,
                'title' => $updatedQuestion->title,
                'keywords_count' => count($updatedQuestion->keywords ?? []),
                'lex_map_count' => count($updatedQuestion->lex_map ?? []),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'تم تحديث السؤال أو إضافته بنجاح',
                'question_id' => $updatedQuestion->id,
                'keywords' => $updatedQuestion->keywords,
                'lex_map' => $updatedQuestion->lex_map,
                'intent' => $updatedQuestion->intent,
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error updating question with ChatGPT answer', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'original_question' => $validated['original_question']
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'حدث خطأ أثناء تحديث السؤال: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * محاولة كشف نية السؤال (intent) تلقائيًا
     */
    private function detectIntent(string $question): ?string
    {
        $question = mb_strtolower($question);

        // كلمات مفتاحية لكل نية
        $intentKeywords = [
            'location' => ['وين', 'أين', 'فين', 'مكان', 'موقع', 'عنوان', 'المدينة', 'الحي', 'شارع'],
            'time' => ['متى', 'وقت', 'ساعة', 'توقيت', 'مواعيد', 'فتح', 'إغلاق', 'يفتح', 'يغلق', 'دوام'],
            'price' => ['كم', 'سعر', 'تكلفة', 'تكلف', 'ثمن', 'ريال', 'جنيه', 'دولار', 'يكلف'],
            'access' => ['كيف', 'أصل', 'وصول', 'طريق', 'باص', 'سيارة', 'مترو', 'محطة', 'اتجاه', 'نصل', 'يوصل'],
        ];

        // حساب عدد الكلمات المطابقة لكل نية
        $matches = [];
        foreach ($intentKeywords as $intent => $keywords) {
            $count = 0;
            foreach ($keywords as $keyword) {
                if (mb_strpos($question, $keyword) !== false) {
                    $count++;
                }
            }
            $matches[$intent] = $count;
        }

        // ترتيب النوايا حسب عدد المطابقات
        arsort($matches);

        // إذا وجدت مطابقة واحدة على الأقل
        foreach ($matches as $intent => $count) {
            if ($count > 0) {
                return $intent;
            }
        }

        return null; // لا توجد نية واضحة
    }

    /**
     * تحويل نص الكلمات المفتاحية إلى مصفوفة
     */
    private function parseKeywordString(?string $keywordsStr): array
    {
        if (empty($keywordsStr)) return [];

        // تقبل أسطر أو فواصل
        $keywordsStr = str_replace(["\r\n", "\r"], "\n", $keywordsStr);
        $parts = preg_split('/[\n,]+/u', $keywordsStr);

        $keywords = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (!empty($part)) {
                $keywords[] = $part;
            }
        }

        return array_unique($keywords);
    }

    /**
     * تحويل نص قاموس المرادفات إلى مصفوفة ترابطية
     */
    private function parseLexMapString(?string $lexMapStr): array
    {
        if (empty($lexMapStr)) return [];

        $lexMapStr = str_replace(["\r\n", "\r"], "\n", $lexMapStr);
        $lines = explode("\n", $lexMapStr);

        $lexMap = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || !str_contains($line, '=')) continue;

            [$from, $to] = array_map('trim', explode('=', $line, 2));
            if (!empty($from) && !empty($to)) {
                $lexMap[$from] = $to;
            }
        }

        return $lexMap;
    }
}
