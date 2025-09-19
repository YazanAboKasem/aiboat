<?php

use App\Http\Controllers\MessagesController;
use App\Http\Controllers\QuestionController;
use App\Services\AnswerService;
use App\Services\RerankerService;
use App\Services\RetrievalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MetaWebhookController;
use App\Http\Controllers\AskController;
use App\Http\Controllers\WebhookController;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// طرق API العامة التي لا تتطلب مصادقة
// Webhook routes
Route::get('meta/webhook', [MetaWebhookController::class, 'verify']);
Route::post('meta/webhook', [MetaWebhookController::class, 'receive']);
Route::post('ask', [AskController::class, 'ask']);
Route::match(['get','post'], 'webhook', [WebhookController::class, 'handle']);

// طرق البحث والاستعلام العامة
Route::post('/ask-hq', function (Request $req, RetrievalService $retrieval, AnswerService $ans, RerankerService $rerank) {
    $q = (string)$req->input('q', '');
    if ($q === '') return response()->json(['error'=>'q is required'], 422);

    // 1) بحث هجين + RRF
    // زِد k للمرشحين ثم اختر 3 لاحقاً لضمان تنويع أفضل
    $cands = $retrieval->topKHybrid($q, k: 12, vecN: 80, textN: 80);

    // 2) (اختياري) ريرنك LLM خفيف لفرز أدق
    if (!empty($cands)) {
        // يجب أن يُضيف 'rerank' ويحافظ على العناصر (أو يرجّع مصفوفة مقابلات بنفس الحقول)
        $cands = $rerank->scorePairs($q, $cands);
    }

    // =====  حساب "درجة التطابق" الموحّدة 0..1 ثم تحويلها إلى %  =====
    $clamp01 = fn($x) => max(0.0, min(1.0, (float)$x));
    $minMax = function(array $vals): array {
        $vals = array_values(array_filter($vals, fn($v)=>$v !== null && is_numeric($v)));
        if (empty($vals)) return [0.0, 0.0];
        return [min($vals), max($vals)];
    };

    // اجمع القيم لكل المرشحين
    $l2s     = array_map(fn($c)=> $c['l2']     ?? null, $cands);
    $sims    = array_map(fn($c)=> $c['sim']    ?? null, $cands);
    $reranks = array_map(fn($c)=> $c['rerank'] ?? null, $cands);
    $bm25s   = array_map(fn($c)=> $c['bm25']   ?? null, $cands);   // إن كانت موجودة
    $phrases = array_map(fn($c)=> $c['phrase'] ?? null, $cands);   // إن كانت موجودة

    [$l2min,  $l2max]  = $minMax($l2s);
    [$simmin, $simmax] = $minMax($sims);
    [$rrmin,  $rrmax]  = $minMax($reranks);
    [$bm25min,$bm25max]= $minMax($bm25s);

    // أوزان افتراضية (يمكن تعديلها حسب بياناتك)
    $w_vec = 0.45; $w_txt = 0.35; $w_rr = 0.15; $w_ph = 0.05;

    foreach ($cands as &$c) {
        $eps = 1e-9;

        // 1) تشابه متجه من l2 (أصغر أفضل) => طبع Min-Max معكوس
        $s_vec = null;
        if (isset($c['l2']) && is_numeric($c['l2']) && $l2max > $l2min) {
            $s_vec = ($l2max - (float)$c['l2']) / (($l2max - $l2min) + $eps);
            $s_vec = $clamp01($s_vec);
        }

        // 2) تشابه نصّي من trigram sim مباشرة (0..1)، أو استخدم BM25 إن sim غير موجود
        $s_txt = null;
        if (isset($c['sim']) && is_numeric($c['sim'])) {
            $s_txt = $clamp01((float)$c['sim']);
        } elseif (isset($c['bm25']) && is_numeric($c['bm25']) && $bm25max > $bm25min) {
            $s_txt = (($c['bm25'] - $bm25min) / (($bm25max - $bm25min) + $eps));
            $s_txt = $clamp01($s_txt);
        }

        // 3) ريرنك LLM (Min-Max)
        $s_rr = null;
        if (isset($c['rerank']) && is_numeric($c['rerank']) && $rrmax > $rrmin) {
            $s_rr = (($c['rerank'] - $rrmin) / (($rrmax - $rrmin) + $eps));
            $s_rr = $clamp01($s_rr);
        }

        // 4) تطابق عبارة دقيقة (إن وُجد)
        $s_ph = null;
        if (isset($c['phrase'])) {
            $s_ph = $c['phrase'] ? 1.0 : 0.0;
        }

        // أوزان ديناميكية حسب المتاح
        $parts = [];
        if ($s_vec !== null) $parts[] = ['v'=>$s_vec, 'w'=>$w_vec];
        if ($s_txt !== null) $parts[] = ['v'=>$s_txt, 'w'=>$w_txt];
        if ($s_rr  !== null) $parts[] = ['v'=>$s_rr,  'w'=>$w_rr];
        if ($s_ph  !== null) $parts[] = ['v'=>$s_ph,  'w'=>$w_ph];

        if (empty($parts)) {
            $c['match'] = null;
        } else {
            // أعِد توزيع الأوزان المتاحة بحيث مجموعها = 1
            $wSum = array_sum(array_column($parts, 'w'));
            if ($wSum <= 0) $wSum = 1.0;
            $match = 0.0;
            foreach ($parts as $p) {
                $match += $p['v'] * ($p['w'] / $wSum);
            }
            $c['match'] = $clamp01($match);
        }
    }
    unset($c);

    // 3) تنويع النتائج ومنع دمجها بالاعتماد على المصدر فقط
    //    نستخدم مفتاح مركّب (جذر المصدر + أول سطر من السؤال داخل النص) لمنع سحق نتائج مختلفة من نفس الملف.
    $rootOf = function (?string $src): string {
        $src = (string)$src;
        $root = preg_replace('/#\d+$/', '', $src);
        return $root !== '' ? $root : $src;
    };

    $parseQA = function (string $text) use ($ans): array {
        $question = null;
        if (preg_match('/(?:^|\n)\s*سؤال\s*:\s*(.+?)(?:\n|$)/u', $text, $m)) {
            $question = trim($m[1]);
        }
        $answer = (string) $ans->fromQA($text);
        if ($question === null) $question = trim(strtok($text, "\n"));
        return ['question' => $question, 'answer' => $answer];
    };

    $keyOf = function(array $c) use ($rootOf, $parseQA) {
        $src  = $c['source'] ?? '';
        $root = $rootOf($src);
        if ($root === '') $root = 'id:' . ($c['id'] ?? spl_object_hash((object)$c));
        $qa = $parseQA((string)($c['text'] ?? ''));
        $qk = mb_strtolower(trim(mb_substr($qa['question'] ?? '', 0, 80)));
        return $root . '|' . $qk;
    };

    // رتّب المرشحين تنازليًا حسب match (ثم rerank/sim/rrf كتعويض)
    usort($cands, function($a, $b) {
        $am = $a['match'] ?? null; $bm = $b['match'] ?? null;
        if ($am !== $bm) return ($bm <=> $am);
        // تعويضات ثانوية للحالات المتساوية
        $ar = $a['rerank'] ?? null; $br = $b['rerank'] ?? null;
        if ($ar !== $br) return ($br <=> $ar);
        $as = $a['sim'] ?? null;    $bs = $b['sim'] ?? null;
        if ($as !== $bs) return ($bs <=> $as);
        $arrf = $a['rrf'] ?? null;  $brrf = $b['rrf'] ?? null;
        return ($brrf <=> $arrf);
    });

    // التقط حتى 3 نتائج بمفتاح مركّب (مصدر+سؤال داخلي) لمنع الدمج الجائر
    $seen = [];
    $top3 = [];
    foreach ($cands as $c) {
        $key = $keyOf($c);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $qa = $parseQA((string)($c['text'] ?? ''));

        // حوّل match إلى نسبة مئوية
        $matchPercent = isset($c['match']) ? round($c['match'] * 100) : null;

        $top3[] = [
            'question' => $qa['question'],
            'answer'   => $qa['answer'],
            'score'    => $matchPercent, // للعرض السريع
            'match'    => $c['match'] ?? null,
            'match_percent' => $matchPercent !== null ? ($matchPercent . '%') : null,
            'source'   => $c['source'] ?? null,
            'metrics'  => [
                'match'  => $c['match'] ?? null,
                'rrf'    => $c['rrf']    ?? null,
                'l2'     => $c['l2']     ?? null,
                'sim'    => $c['sim']    ?? null,
                'rerank' => $c['rerank'] ?? null,
                // إن كانت موجودة من مرحلة الاسترجاع:
                'bm25'   => $c['bm25']   ?? null,
                'phrase' => $c['phrase'] ?? null,
            ],
        ];

        if (count($top3) >= 3) break;
    }

    if (empty($top3)) {
        return response()->json([
            'handover' => true,
            'answer'   => 'لا أملك معلومات كافية.',
            'results'  => [],
        ], 200);
    }

    // للتوافق: نعيد answer كأول نتيجة أيضًا + نسبة التطابق
    return response()->json([
        'handover' => false,
        'answer'   => $top3[0]['answer'],
        'match_percent' => $top3[0]['match_percent'] ?? null,
        'results'  => $top3,
    ], 200);
});

// طرق API المحمية (تتطلب مصادقة)
Route::middleware('auth:sanctum')->group(function () {
    // Simple test route
    Route::get('test', function () {
        return response('API routing test successful!', 200);
    });

    // طرق الاختبار والإدارة
    Route::post('/retrieval-test', function (Request $request, RetrievalService $retrieval) {
        $q = (string) $request->input('q', '');
        if ($q === '') return response()->json(['error' => 'q is required'], 422);

        $top = $retrieval->topK($q, 5); // رجّع أقرب 5 مقاطع
        return response()->json(['query' => $q, 'results' => $top], 200);
    });

    // تحديث الإجابات
    Route::post('/update-chatgpt-answer', [AskController::class, 'updateWithChatGptAnswer']);
});
