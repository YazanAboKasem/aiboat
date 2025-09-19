<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Services\ChatGptService;
use App\Services\EmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function handle(Request $request,EmbeddingService $svc, ChatGptService $chatGpt)
    {

        $raw = $request->getContent();
        Log::info('Webhook RAW', ['raw' => $raw]);

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


        $payload = json_decode($raw, true) ?: $request->all();
        Log::info('Webhook DECODED', ['payload' => $payload]);

        if (($payload['object'] ?? '') === 'page') {
            foreach ($payload['entry'] ?? [] as $entry) {
                foreach (($entry['messaging'] ?? []) as $ev) {
                    $senderId = data_get($ev, 'sender.id');
//
                    // تجاهل رسائل الإيكو الصادرة من الصفحة نفسها
                    if (data_get($ev, 'message.is_echo')) {
                        Log::info('Skip echo event');
                        continue;
                    }

                    // نص

                    if ($text = data_get($ev, 'message.text')) {
                        Log::info('MSG texttt', compact('senderId','text'));

                        // حفظ الرسالة في قاعدة البيانات
                        \App\Models\Message::create([
                            'sender_id' => $senderId,
                            'message' => $text,
                            'source' => 'facebook',
                            'is_reply' => false,
                        ]);

                        // ابحث عن إجابة من قاعدة المعرفة
                        $apiResponse = $this->ask($text,$svc,$chatGpt);
                        // Log::info('$apiResponse text', compact('apiResponse',$apiResponse));

                        $data = $apiResponse->getData(true); // تحويل JSON إلى مصفوفة

                        if (isset($data['answer'])) {
                            $this->sendMessage($senderId, $data['answer']);

                            // حفظ الرد في قاعدة البيانات
                            \App\Models\Message::create([
                                'sender_id' => $senderId,
                                'message' => $data['answer'],
                                'source' => 'facebook',
                                'is_reply' => true,
                            ]);
                        }

//                        $answer = $this->answerForMessage($text);
//                        if ($answer) {
//                            $this->sendMessage($senderId, $answer);
//                        } else {
//                            $this->sendMessage($senderId, "وصلت رسالتك ✅\nأعطني تفاصيل أكثر أو صياغة مختلفة حتى أساعدك بشكل أدق.");
//                        }
                        continue;
                    }

                    // مرفقات
                    if ($atts = data_get($ev, 'message.attachments')) {
                        foreach ((array) $atts as $att) {
                            $type = data_get($att, 'type');
                            $url  = data_get($att, 'payload.url');
                            Log::info('MSG attachment', compact('senderId','type','url'));

                            // حفظ المرفق في قاعدة البيانات
                            \App\Models\Message::create([
                                'sender_id' => $senderId,
                                'message' => "مرفق من نوع: $type",
                                'source' => 'facebook',
                                'is_reply' => false,
                                'attachment_url' => $url,
                                'attachment_type' => $type,
                            ]);
                        }

                        $responseText = "استلمت مرفق نوعه: ".data_get($atts, '0.type', 'غير معروف');
                        $this->sendMessage($senderId, $responseText);

                        // حفظ الرد في قاعدة البيانات
                        \App\Models\Message::create([
                            'sender_id' => $senderId,
                            'message' => $responseText,
                            'source' => 'facebook',
                            'is_reply' => true,
                        ]);

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
        }
        if (($payload['object'] ?? '') === 'instagram') {
            foreach ($payload['entry'] ?? [] as $entry) {
                foreach (($entry['messaging'] ?? []) as $event) {
                    $senderId = data_get($event, 'sender.id');

                    // Ignore echo messages
                    if (data_get($event, 'message.is_echo')) {
                        Log::info('Skip echo event');
                        continue;
                    }

                    // Handle text messages
                    if ($text = data_get($event, 'message.text')) {

                        Log::info('chike message skipped', ['mid' => data_get($event, 'message.mid')]);

                        if (\App\Models\Message::where('ms_id', data_get($event, 'message.mid'))->exists()) {
                            Log::info('Duplicate message skipped', ['mid' => data_get($event, 'message.mid')]);
                            continue;
                        }
                        Log::info('Instagram MSG text', compact('senderId', 'text'));

                        // حفظ الرسالة الواردة من إنستغرام في قاعدة البيانات
                        \App\Models\Message::create([
                            'ms_id' => data_get($event, 'message.mid'),
                            'sender_id' => $senderId,
                            'message' => $text,
                            'source' => 'instagram',
                            'is_reply' => false,
                        ]);

                        try {
                            $apiResponse = $this->ask($text, $svc, $chatGpt);
                            $data = $apiResponse->getData(true); // Convert JSON to array

                            if (isset($data['answer'])) {
                                $answer = $data['answer'];
                                $this->sendInstagramMessage($payload['entry'][0]['messaging'][0]['sender']['id'], $answer);

                                Log::info('Webhook RAW', ['raw' => $raw]);

                              //   حفظ الرد في قاعدة البيانات
                                \App\Models\Message::create([
                                    'ms_id' => data_get($event, 'message.mid'),
                                    'sender_id' => $senderId,
                                    'message' => $answer,
                                    'source' => 'instagram',
                                    'is_reply' => true,
                                ]);
                            }
                        } catch (\Throwable $e) {
                            Log::error('Error processing API', ['error' => $e->getMessage()]);
                        }

                        continue;
                    }

                    // Handle attachments
                    if ($attachments = data_get($event, 'message.attachments')) {
                        foreach ((array) $attachments as $attachment) {
                            $type = data_get($attachment, 'type');
                            $url  = data_get($attachment, 'payload.url');
                            Log::info('Instagram MSG attachment', compact('senderId', 'type', 'url'));
                        }
                        $this->sendMessage($senderId, "استلمت مرفق نوعه: " . data_get($attachments, '0.type', 'غير معروف'));
                        continue;
                    }

                }
            }
        }

        else {
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
    private function callAskApi(string $question): ?array
    {
        $endpoint = url('/api/ask');
        try {
            $response = Http::post($endpoint, [
                'q' => $question, // أو 'question' اعتمادًا على صيغة API
            ]);

            if ($response->ok()) {
                return $response->json();
            }

            Log::error('ASK API Error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ASK API Request Failed', ['error' => $e->getMessage()]);
        }

        return null; // تُعاد null في حال واجهت مشكلة
    }
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
    private function sendInstagramMessage(string $igUserId, string $messageText): void
    {
        // مهم: هذا لازم يكون Page Access Token للصفحة المرتبطة بحساب إنستغرام
        $pageAccessToken = env('META_PAGE_ACCESS_TOKEN');
        if (!$pageAccessToken) {
            Log::error('PAGE ACCESS TOKEN missing: set META_PAGE_ACCESS_TOKEN in .env');
            return;
        }

        $endpoint = "https://graph.facebook.com/v21.0/me/messages";

        $payload = [
            'messaging_product' => 'instagram',        // ضروري لإنستغرام
            'recipient'         => ['id' => $igUserId],// خُذها من sender.id في الويبهوك
            'message'           => ['text' => $messageText],
            // 'messaging_type'  => 'RESPONSE',         // عادة غير ضرورية مع IG؛ اتركها معلّقة
            'access_token'      => $pageAccessToken,
        ];

        $resp = Http::asJson()->post($endpoint, $payload);

        if ($resp->failed()) {
            Log::error('IG Send API failed', [
                'status'  => $resp->status(),
                'body'    => $resp->body(),
                'payload' => array_merge($payload, ['access_token' => '[redacted]']),
            ]);
            return;
        }

        Log::info('IG Send API ok', $resp->json());
    }
    public function ask($q, EmbeddingService $svc, ChatGptService $chatGpt): JsonResponse
    {

        $text = $q;
        $translate_text = $chatGpt->translateTextV3($text,"ar");

        $userText = $translate_text['data']['translations'][0]['translatedText'];
        $userLang = $translate_text['data']['translations'][0]['detectedSourceLanguage'];
        Log::error('translate question', [
            'userQuestion' => $text
        ]);
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
            foreach (array_slice($topK, 0, 4) as $index => $candidate) {
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

//    public function ask( $q, EmbeddingService $svc, ChatGptService $chatGpt): JsonResponse
//    {
//        $userText = $q;
//        if (!is_string($userText) || trim($userText) === '') {
//            return response()->json(['error' => 'الرجاء إرسال الحقل q (أو question) بنص صحيح.'], 422);
//        }
//        // 1) جلب المرشحين والأسئلة مع قاموس الاستبدال الخاص بكل سؤال
//        $candidates = Question::where(function ($q) {
//            $q->whereNotNull('title_embedding')
//                ->orWhereNotNull('content_embedding');
//        })->get(['id', 'title', 'content', 'lex_map']);
//
//        // 2) تطبيق قاموس الاستبدال (lex_map) المتوفر لكل سؤال على النص المستخدم
//        foreach ($candidates as $candidate) {
//            $lexMap = is_array($candidate->lex_map) ? $candidate->lex_map : [];
//            if (!empty($lexMap)) {
//                $userText = $this->applyLexMap($userText, $lexMap);
//            }
//        }
//
//        // 3) استخراج التضمين للسؤال بعد الاستبدال
//        try {
//            $userEmbedding = $svc->embed($userText);
//        } catch (Throwable $e) {
//            Log::error('Embedding API failed for /api/ask', ['error' => $e->getMessage()]);
//            return response()->json([
//                'error'   => 'تعذّر الاتصال بمحرك التضمين حاليًا.',
//                'details' => 'تأكد من إعدادات المفتاح API وجرب مرة أخرى.'
//            ], 502);
//        }
//
//
//        if (!is_array($userEmbedding) || count($userEmbedding) === 0) {
//            return response()->json(['error' => 'لم أتمكن من توليد تمثيل دلالي للسؤال المُرسَل.'], 400);
//        }
//
//        // 2) اجلب المرشحين (مع الحقول الجديدة)
//        $candidates = Question::where(function ($q) {
//            $q->whereNotNull('title_embedding')
//                ->orWhereNotNull('content_embedding');
//        })
//            ->get(['id','title','content','answer','intent',
//                'title_embedding','content_embedding',
//                'keywords','lex_map']);
//
//        if ($candidates->isEmpty()) {
//            return response()->json([
//                'match_question' => null,
//                'answer'         => null,
//                'similarity'     => 0,
//                'message'        => 'لا توجد أسئلة مضمّنة بعد. شغّل: php artisan embeddings:backfill-questions'
//            ], 200);
//        }
//
//        // 3) اكتشاف نية تقريبية لعمل Boost في الترتيب
//        $detectedIntent = $this->detectIntent($userText);
//
//        // أوزان المزج
//        $alpha = 0.72; // دلالي
//        $beta  = 0.13; // لغوي
//        $gamma = 0.10; // Intent
//        $delta = 0.05; // Keywords Boost
//
//        // نحضّر نسخة مطبّعة من نص المستخدم لاستخدامها في القياس اللفظي والكلمات المفتاحية
//        $userNorm = $this->normalizeAr(mb_strtolower($userText));
//
//        $topK = [];
//        foreach ($candidates as $cand) {
//            // 3.1 عرض السؤال
//            $displayQ = trim(implode(' — ', array_filter([
//                is_string($cand->title) ? trim($cand->title) : null,
//                is_string($cand->content) ? trim($cand->content) : null,
//            ]))) ?: ($cand->title ?? '—');
//
//            // 3.2 تشابه دلالي (أعلى من العنوان/المحتوى) + تطبيع إلى [0..1]
//            $cosTitle = (is_array($cand->title_embedding) && $cand->title_embedding)
//                ? EmbeddingService::cosine($userEmbedding, $cand->title_embedding) : 0.0;
//            $cosCont  = (is_array($cand->content_embedding) && $cand->content_embedding)
//                ? EmbeddingService::cosine($userEmbedding, $cand->content_embedding) : 0.0;
//
//            $simTitle = $this->toUnit($cosTitle);
//            $simCont  = $this->toUnit($cosCont);
//            $sim_d    = max($simTitle, $simCont);
//
//            // 3.3 lex_map: استبدال ديناميكي قبل القياس اللفظي (على نسخة مؤقتة فقط)
//            $lexMap = is_array($cand->lex_map) ? $cand->lex_map : [];
//            $candBlobRaw = (($cand->title ?? '') . ' ' . ($cand->content ?? ''));
//            $candNorm    = $this->normalizeAr(mb_strtolower($candBlobRaw));
//
//            if (!empty($lexMap)) {
//                $userLex = $this->applyLexMap($userNorm, $lexMap);
//                $candLex = $this->applyLexMap($candNorm, $lexMap);
//            } else {
//                $userLex = $userNorm;
//                $candLex = $candNorm;
//            }
//
//            // 3.4 تشابه لغوي بسيط (Jaccard) على النص الموحّد
//            $sim_l = $this->lexicalOverlap($userLex, $candLex);
//
//            // 3.5 Intent boost
//            $sim_i = ($detectedIntent && $cand->intent === $detectedIntent) ? 1.0 : 0.0;
//
//            // 3.6 Keywords boost
//            $keywords = is_array($cand->keywords) ? $cand->keywords : [];
//            $kwBoost  = $this->keywordsBoost($userLex, $keywords); // 0..1 صغير
//
//            // 3.7 الدرجة النهائية
//            $score = $alpha*$sim_d + $beta*$sim_l + $gamma*$sim_i + $delta*$kwBoost;
//
//            $topK[] = [
//                'id'         => $cand->id,
//                'question'   => $displayQ,
//                'answer'     => $cand->answer,
//                'similarity' => round($score, 4),
//                'parts'      => [
//                    'semantic' => round($sim_d, 4),
//                    'lexical'  => round($sim_l, 4),
//                    'intent'   => $sim_i,
//                    'keywords' => round($kwBoost, 4),
//                ],
//            ];
//        }
//
//        // 4) ترتيب تنازلي
//        usort($topK, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);
//
//        // 5) عتبة منطقية بعد التطبيع
//        $THRESHOLD = 0.65;
//        $CHATGPT_THRESHOLD = 0.65; // عتبة الإرسال إلى ChatGPT إذا كانت نسبة المطابقة أقل من 65%
//        $best = $topK[0];
//
//        if ($best['similarity'] >= $THRESHOLD) {
//            return response()->json([
//                'match_question' => $best['question'],
//                'answer'         => $best['answer'],
//                'similarity'     => $best['similarity'],
//                'parts'          => $best['parts'],
//                'alternatives'   => array_slice($topK, 1, 3),
//            ], 200);
//        }
//
//        // إذا كانت نسبة المطابقة أقل من 65%، نرسل السؤال والبيانات إلى ChatGPT
//        if ($best['similarity'] < $CHATGPT_THRESHOLD) {
//            try {
//                // تجميع البيانات ذات الصلة (أفضل 5 نتائج) لإرسالها إلى ChatGPT
//                $relatedData = array_slice($topK, 0, 5);
//
//                // الحصول على إجابة من ChatGPT
//
//                $chatGptResult = $chatGpt->getAnswer($userText, $relatedData);
//
//                // إذا كانت الثقة بالإجابة منخفضة أو تم تحديد أن الإجابة "غير موجودة"
//                if ($chatGptResult['confidence'] <= 0.0) {
//                    return response()->json([
//                        'match_question' => null,
//                        'answer'         => null,
//                        'similarity'     => $best['similarity'],
//                        'parts'          => $best['parts'],
//                        'suggestions'    => array_slice($topK, 0, 5),
//                        'message'        => 'لم أجد تطابقًا بثقة كافية، وليست هناك إجابة واضحة في قاعدة البيانات.',
//                    ], 200);
//                }
//
//                // تم العثور على إجابة من ChatGPT
//                return response()->json([
//                    'match_question' => null,
//                    'answer'         => $chatGptResult['answer'],
//                    'similarity'     => $best['similarity'],
//                    'parts'          => $best['parts'],
//                    'source'         => $chatGptResult['source'],
//                    'suggestions'    => array_slice($topK, 0, 5),
//                ], 200);
//
//            } catch (Throwable $e) {
//                Log::error('ChatGPT API failed', ['error' => $e->getMessage()]);
//
//                // في حالة فشل الاتصال بـ ChatGPT، نعود إلى السلوك الافتراضي
//                return response()->json([
//                    'match_question' => null,
//                    'answer'         => null,
//                    'similarity'     => $best['similarity'],
//                    'parts'          => $best['parts'],
//                    'suggestions'    => array_slice($topK, 0, 5),
//                    'message'        => 'لم أجد تطابقًا بثقة كافية، وتعذر الاتصال بمساعد الذكاء الاصطناعي.',
//                ], 200);
//            }
//        }
//
//        // السلوك الافتراضي إذا لم نتمكن من العثور على تطابق بثقة كافية ولكن لم نصل لمرحلة الإرسال إلى ChatGPT
//        return response()->json([
//            'match_question' => null,
//            'answer'         => null,
//            'similarity'     => $best['similarity'],
//            'parts'          => $best['parts'],
//            'suggestions'    => array_slice($topK, 0, 5),
//            'message'        => 'لم أجد تطابقًا بثقة كافية.',
//        ], 200);
//    }

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
