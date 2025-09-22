<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

set_time_limit(120);

class AssistantController extends Controller
{
    private string $base = 'https://api.openai.com/v1';
    private array $betaHeader = ['OpenAI-Beta' => 'assistants=v2'];

    private function authHeaders(): array
    {
        return array_merge($this->betaHeader, [
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            'Content-Type'  => 'application/json',
        ]);
    }

    /**
     * ينشئ Assistant جديد (مرة واحدة) ويربطه بالـ Vector Store.
     * خزّن الناتج ASSISTANT_ID في .env لاستخدامه لاحقًا.
     */
    public function createAssistant(string $vectorStoreId)

    {
        $model = env('OPENAI_ASSISTANT_MODEL', 'gpt-4.1-mini');

        if (!$vectorStoreId) {
            return response()->json(['error' => 'VECTOR_STORE_ID is missing in .env'], 422);
        }

        $payload = [
            'name'        => 'ZF Knowledge Assistant',
            'model'       => $model,
            'instructions'=> "أجب فقط من معلومات مهرجان الشيخ زايد المرفوعة في قاعدة المعرفة. "
                ."إذا لم تجد معلومة كافية، قل: انا مساعد الي لم استطع العثور على اجابة مفيدة سوف اقوم بتوجيه المراسلة لاحد موظفيننا.",
            'tools'       => [
                ['type' => 'file_search']
            ],
            'tool_resources' => [
                'file_search' => [
                    'vector_store_ids' => [$vectorStoreId]
                ]
            ],
            // اختيارياً: ضبط أسلوب الإجابة بالعربية دائمًا
            'metadata'    => ['lang' => 'ar']
        ];

        $resp = Http::withHeaders($this->authHeaders())
            ->post($this->base . '/assistants', $payload);

        if (!$resp->successful()) {
            return response()->json(['error' => 'Failed to create assistant', 'details' => $resp->json()], 500);
        }

        $assistantId = $resp->json('id');
        Assistant::truncate();
        Assistant::create([
            'name' => 'no name yet',
            'assistant_id' => $assistantId,
            'vector_store_id' => $vectorStoreId,
        ]);
       $g = Assistant::safeFirst();
        \Log::info($g);

        return response()->json([
            'status'        => 'created',
            'assistant_id'  => $assistantId,
            'hint'          => 'انسخ هذا المعرف وضعه في .env كـ ASSISTANT_ID'
        ]);
    }

    /**
     * طرح سؤال على المساعد:
     * - ينشئ Thread
     * - يضيف رسالة المستخدم
     * - يشغّل Run
     * - ينتظر اكتماله
     * - يرجع آخر رسالة من المساعد (الإجابة)
     */
    public function ask(Request $request)
    {
        $request->validate(['question' => 'required|string']);


        // Fetch the first assistant record from the database
        $assistant = Assistant::safeFirst();
        // Handle the case where no assistant ID is found in the database
        if (!$assistant || !$assistant->assistant_id) {
            return response()->json(['error' => 'No Assistant ID found in the database. Ensure the assistant is properly set up.'], 422);
        }

        $assistantId = $assistant->assistant_id;

        // 1) إنشاء Thread
        $threadResp = Http::withHeaders($this->authHeaders())
            ->post($this->base . '/threads', []);

        if (!$threadResp->successful()) {
            return response()->json(['error' => 'Failed to create thread', 'details' => $threadResp->json()], 500);
        }

        $threadId = $threadResp->json('id');

        // 2) إضافة رسالة المستخدم
        $msgResp = Http::withHeaders($this->authHeaders())
            ->post($this->base . "/threads/{$threadId}/messages", [
                'role'    => 'user',
                'content' => $request->input('question'),
            ]);

        if (!$msgResp->successful()) {
            return response()->json(['error' => 'Failed to add message', 'details' => $msgResp->json()], 500);
        }

        // 3) تشغيل Run
        $runResp = Http::withHeaders($this->authHeaders())
            ->post($this->base . "/threads/{$threadId}/runs", [
                'assistant_id' => $assistantId,
            ]);

        if (!$runResp->successful()) {
            return response()->json(['error' => 'Failed to create run', 'details' => $runResp->json()], 500);
        }

        $runId = $runResp->json('id');

        // 4) Polling حتى يكتمل
        $maxTries = 40; // ~40 ثانية
        $tries = 0;
        $status = 'queued';

        do {

            $getRun = Http::withHeaders($this->authHeaders())
                ->get($this->base . "/threads/{$threadId}/runs/{$runId}");

            if (!$getRun->successful()) {
                return response()->json(['error' => 'Failed to get run status', 'details' => $getRun->json()], 500);
            }

            $status = $getRun->json('status');

            if (in_array($status, ['completed', 'failed', 'expired', 'cancelled'])) {
                break;
            }

            $tries++;
        } while ($tries < $maxTries);

        if ($status !== 'completed') {
            return response()->json(['status' => $status, 'message' => 'Run not completed yet']);
        }

        // 5) جلب آخر رسالة (إجابة المساعد)
        $msgs = Http::withHeaders($this->authHeaders())
            ->get($this->base . "/threads/{$threadId}/messages", [
                'limit' => 10,
                'order' => 'desc'
            ]);

        if (!$msgs->successful()) {
            return response()->json(['error' => 'Failed to list messages', 'details' => $msgs->json()], 500);
        }


        $items = $msgs->json('data') ?? [];
        $assistantReply = null;

        foreach ($items as $m) {
            if (($m['role'] ?? '') === 'assistant') {
                // استخراج النص من message.content
                $parts = $m['content'] ?? [];
                $text = '';

                foreach ($parts as $p) {
                    if (($p['type'] ?? '') === 'output_text') {
                        $text .= $p['text'] ?? '';
                    } elseif (($p['type'] ?? '') === 'text') {
                        // (بعض الإصدارات قد تُرجع type=text)
                        $text .= $p['text']['value'] ?? '';
                    }
                }

                // إزالة المراجع باستخدام تعبير منتظم
                $text = preg_replace('/【\d+:\d+†.*?】/', '', $text);

                $assistantReply = $text ?: null;
                break;
            }
        }


        return response()->json([
            'status'        => 'ok',
            'thread_id'     => $threadId,
            'run_id'        => $runId,
            'answer'        => $assistantReply ?? 'لم تُستخلص إجابة.',
        ]);
    }}
