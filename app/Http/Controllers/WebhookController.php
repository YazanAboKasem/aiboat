<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        // GET: verification
        if ($request->isMethod('get')) {
            $mode  = $request->query('hub.mode') ?? $request->query('hub_mode');
            $token = $request->query('hub.verify_token') ?? $request->query('hub_verify_token');
            $chal  = $request->query('hub.challenge') ?? $request->query('hub_challenge');

            return ($mode === 'subscribe' && $token === env('META_VERIFY_TOKEN'))
                ? response($chal, 200)
                : response('Verification failed', Response::HTTP_FORBIDDEN);
        }

        // POST: events
        $raw = $request->getContent();
        Log::info('Webhook RAW', ['raw' => $raw]);

        $payload = json_decode($raw, true) ?: $request->all();
        Log::info('Webhook DECODED', ['payload' => $payload]);

        if (($payload['object'] ?? '') === 'page') {
            foreach ($payload['entry'] ?? [] as $entry) {
                foreach (($entry['messaging'] ?? []) as $ev) {
                    $senderId = data_get($ev, 'sender.id');

                    // تجاهل رسائل الإيكو الصادرة من الصفحة نفسها
                    if (data_get($ev, 'message.is_echo')) {
                        Log::info('Skip echo event');
                        continue;
                    }

                    // نص
                    if ($text = data_get($ev, 'message.text')) {
                        Log::info('MSG text', compact('senderId','text'));

                        // ابحث عن إجابة من قاعدة المعرفة
                        $answer = $this->answerForMessage($text);

                        if ($answer) {
                            $this->sendMessage($senderId, $answer);
                        } else {
                            $this->sendMessage($senderId, "وصلت رسالتك ✅\nأعطني تفاصيل أكثر أو صياغة مختلفة حتى أساعدك بشكل أدق.");
                        }
                        continue;
                    }

                    // مرفقات
                    if ($atts = data_get($ev, 'message.attachments')) {
                        foreach ((array) $atts as $att) {
                            $type = data_get($att, 'type');
                            $url  = data_get($att, 'payload.url');
                            Log::info('MSG attachment', compact('senderId','type','url'));
                        }
                        $this->sendMessage($senderId, "استلمت مرفق نوعه: ".data_get($atts, '0.type', 'غير معروف'));
                        continue;
                    }

                    // Postback (أزرار)
                    if ($pb = data_get($ev, 'postback.payload')) {
                        Log::info('POSTBACK', ['senderId' => $senderId, 'payload' => $pb]);
                        $this->sendMessage($senderId, "تم الضغط على الزر: {$pb}");
                        continue;
                    }
                }
            }
        } else {
            Log::warning('Unknown webhook object', ['object' => $payload['object'] ?? null]);
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * يبني الرد اعتمادًا على أقرب تطابق دلالي في kb_chunks مع ربطه بـ questions.answer.
     */
    /**
     * يعيد أفضل 3 أسئلة + أجوبة متطابقة دلاليًا.
     */
    private function answerForMessage(string $userText): ?string
    {
        $apiKey = env('OPENAI_API_KEY');
        $model  = env('OPENAI_EMBED_MODEL', 'text-embedding-3-small');

        if (!$apiKey) {
            Log::error('OPENAI_API_KEY missing');
            return null;
        }

        // 1) embedding لنص المستخدم
        $vec = $this->embedText($userText, $apiKey, $model);
        if (!$vec || !is_array($vec)) {
            Log::error('embedText failed');
            return null;
        }

        $lit = '['.implode(',', array_map(
                static fn($n) => (string) (is_float($n) || is_int($n) ? $n : (float)$n),
                $vec
            )).']';

        /**
         * 2) نأتي بأفضل مقطع لكل سؤال ثم ننتقي أعلى 3 أسئلة تشابهًا:
         *  - DISTINCT ON (q.id) يضمن أخذ أقرب chunk لكل سؤال
         *  - ثم نرتب حسب المسافة النهائية (dist ASC) ونحدّ بـ 3
         */
        $rows = DB::connection('pgsql')->select("
        WITH per_q AS (
            SELECT DISTINCT ON (q.id)
                q.id      AS qid,
                q.content AS question_text,
                q.answer  AS answer_text,
                c.source  AS chunk_source,
                (c.embedding <=> ?::vector) AS dist
            FROM kb_chunks c
            JOIN questions q ON q.id = c.kb_item_id
            WHERE q.answer IS NOT NULL AND q.answer <> ''
            ORDER BY q.id, dist ASC
        )
        SELECT qid, question_text, answer_text, chunk_source, (1 - dist) AS score, dist
        FROM per_q
        ORDER BY dist ASC
        LIMIT 3
    ", [$lit]);

        if (!$rows) {
            return null;
        }

        // حد جوده بسيط (اختياري): استبعد النتائج الضعيفة
        $top = array_values(array_filter($rows, fn($r) => (float)($r->score ?? 0.0) >= 0.55));
        if (!$top) {
            return null;
        }

        // 3) صياغة رد واحد يحتوي أفضل 3 بأسلوب مرتب
        $out = "أفضل التطابقات:\n\n";
        foreach ($top as $i => $r) {
            $rank = $i + 1;
            $q    = $this->shorten(trim((string)$r->question_text), 300);
            $a    = $this->shorten(trim((string)$r->answer_text),   800);
            $sc   = number_format((float)$r->score, 2);
            $out .= "{$rank}) السؤال: {$q}\n   الجواب: {$a}\n";
            // لو تحب تظهر السكور:
            // $out .= "   (score: {$sc})\n";
            if ($i < count($top) - 1) $out .= "\n";
        }

        return $out;
    }
    private function shorten(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) return $text;
        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
    /**
     * استدعاء واجهة OpenAI Embeddings.
     */
    private function embedText(string $text, string $apiKey, string $model): ?array
    {
        $resp = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type'  => 'application/json',
        ])->post('https://api.openai.com/v1/embeddings', [
            'model' => $model,
            'input' => $this->normalizeArabic($text),
        ]);

        if (!$resp->ok()) {
            Log::error('EMBED_FAIL', ['status' => $resp->status(), 'body' => $resp->body()]);
            return null;
        }
        return $resp->json('data.0.embedding') ?? null;
    }

    /**
     * تبسيط للنص العربي لتحسين الاسترجاع (لا نعرض النسخة المنقحة للمستخدم).
     */
    private function normalizeArabic(string $s): string
    {
        $s = preg_replace('/[ًٌٍَُِّْـ]/u', '', $s); // حذف التشكيل والتطويل
        $s = str_replace(['إ','أ','آ'], 'ا', $s);
        $s = str_replace(['ى'], 'ي', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    private function sendMessage(string $recipientId, string $messageText): void
    {
        $pageAccessToken = env('META_PAGE_ACCESS_TOKEN'); // <-- تأكد من الاسم في .env
        if (!$pageAccessToken) {
            Log::error('PAGE ACCESS TOKEN missing: set META_PAGE_ACCESS_TOKEN in .env');
            return;
        }

        $endpoint = "https://graph.facebook.com/v21.0/me/messages";

        $resp = Http::asJson()->post($endpoint, [
            'recipient'      => ['id' => $recipientId],
            'message'        => ['text' => $messageText],
            'messaging_type' => 'RESPONSE',
            'access_token'   => $pageAccessToken,
        ]);

        if ($resp->failed()) {
            Log::error('Send API failed', [
                'status' => $resp->status(),
                'body'   => $resp->body(),
            ]);
        } else {
            Log::info('Send API ok', $resp->json());
        }
    }
}
