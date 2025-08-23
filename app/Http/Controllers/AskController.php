<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Question;
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
    public function ask(Request $request, EmbeddingService $svc): JsonResponse
    {
        $userText = $request->input('q') ?? $request->input('question');

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
            ];
        }

        // 4) ترتيب تنازلي
        usort($topK, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);

        // 5) عتبة منطقية بعد التطبيع (جرّب 0.80–0.86)
        $THRESHOLD = 0.52;
        $best = $topK[0];

        if ($best['similarity'] >= $THRESHOLD) {
            return response()->json([
                'match_question' => $best['question'],
                'answer'         => $best['answer'],
                'similarity'     => $best['similarity'],
                'parts'          => $best['parts'],
                'alternatives'   => array_slice($topK, 1, 3),
            ], 200);
        }

        return response()->json([
            'match_question' => null,
            'answer'         => null,
            'similarity'     => $best['similarity'],
            'parts'          => $best['parts'],
            'suggestions'    => array_slice($topK, 0, 5),
            'message'        => 'لم أجد تطابقًا بثقة كافية.',
        ], 200);
    }

    /** تحويل Cosine [-1..1] إلى [0..1] */
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

    /** كشف نية تقريبية بالكلمات الدالة (boost بسيط) */
    private function detectIntent(string $text): ?string
    {
        $lex = [
            'location' => ['اين','وين','موقع','مكان','خريطه','خارطه','map','لوكيشن','location','pin'],
            'access'   => ['الوصول','اوصل','كيف اصل','كيف اروح','كيف اوصل','طريق','الطريق','اتجاه','اتجاهات','directions','route','مسار','الدخول','الطرق','اقرب'],
            'time'     => ['متى','اوقات','وقت','ساعات','مواعيد','يفتح','يبدأ','يغلق'],
            'price'    => ['كم','سعر','اسعار','التذاكر','ثمن','رسوم'],
        ];
        $t = $this->normalizeAr(mb_strtolower($text));
        $best = null; $hits = 0;
        foreach ($lex as $intent => $words) {
            $c = 0; foreach ($words as $w) if (str_contains($t, $w)) $c++;
            if ($c > $hits) { $hits = $c; $best = $intent; }
        }
        return $hits > 0 ? $best : null;
    }

    /** Boost بسيط للكلمات المفتاحية: 0..1 صغيرة */
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
}
