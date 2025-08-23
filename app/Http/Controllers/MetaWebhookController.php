<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MetaWebhookController extends Controller
{
    // GET: للتحقق من الـ Webhook (hub.challenge)
    public function verify(Request $request)
    {
        $mode      = $request->query('hub_mode') ?? $request->query('hub.mode');
        $token     = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        if ($mode === 'subscribe' && $token === env('META_VERIFY_TOKEN')) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    // POST: استقبال أحداث الرسائل
    public function receive(Request $request)
    {
        Log::info('META_EVENT', ['payload' => $request->all()]);
        return response('EVENT_RECEIVED', 200);
    }
    public function ask(Request $request)
    {
        return "fdfccc";
        $q = trim((string) $request->input('message', ''));
        if ($q === '') {
            return response()->json(['error' => 'message is required'], 422);
        }

        // 1) نجمع نصوص المعرفة من الملفات
        $kbTexts = $this->loadKnowledgeTexts();
        // 2) نجري مطابقة بسيطة للملاءمة (keyword match)
        $context = $this->findRelevantContext($q, $kbTexts);

        if ($context === null) {
            // لا يوجد سياق مناسب ضمن القاعدة المسموح بها
            return response()->json([
                'handover' => true,
                'answer'   => 'سأحوّلك للمسؤول لأن السؤال خارج نطاق المعرفة المتاحة.'
            ], 200);
        }

        // 3) نطلب إجابة من OpenAI مع تقييد واضح: "أجب فقط مما في السياق"
        try {
            $resp = $this->callOpenAI($q, $context);
            $answer = $resp['choices'][0]['message']['content'] ?? '';

            // حارس أمان: لو النموذج خرج عن النطاق نمنعه
            if (!$this->answerWithinContext($answer, $context)) {
                return response()->json([
                    'handover' => true,
                    'answer'   => 'سأحوّلك للمسؤول لأن الإجابة غير مؤكدة من المصادر المتاحة.'
                ], 200);
            }

            return response()->json([
                'handover' => false,
                'answer'   => $answer,
                'used_files' => $context['files'],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('OPENAI_FAIL', ['e' => $e->getMessage()]);
            return response()->json([
                'handover' => true,
                'answer'   => 'تعذّر توليد الإجابة الآن، سيتم تحويلك للمسؤول.'
            ], 200);
        }
    }

}
