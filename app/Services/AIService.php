<?php

namespace App\Services;

use App\Http\Controllers\AssistantController;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService
{
    /**
     * المحادثة مع نماذج الذكاء الاصطناعي
     *
     * @param string $text السؤال المراد طرحه
     * @param array $relatedData البيانات ذات الصلة (للنموذج الأول فقط)
     * @param string $userLang لغة المستخدم (للنموذج الأول فقط)
     * @return array استجابة الذكاء الاصطناعي
     */
    public function chat(string $text, array $relatedData = [], string $userLang = 'ar')
    {
        // تحديد النموذج المستخدم من الإعدادات
        $modelType = Setting::get('ai_model', 'assistant');

        Log::info('استخدام نموذج الذكاء الاصطناعي', ['model' => $modelType, 'text' => $text]);

        if ($modelType === 'model_one') {
            // استخدام النموذج الأول (ChatGPT)
            $result = $this->useChatGpt($text, $relatedData, $userLang);
        } else {
            // استخدام النموذج الثاني (المساعد/Assistant)
            $result = $this->useAssistant($text);
        }

        return $result;
    }

    /**
     * الحصول على إجابة باستخدام ChatGPT (النموذج الأول)
     */
    private function useChatGpt(string $text, array $relatedData, string $userLang)
    {
        // إنشاء طلب إلى OpenAI مباشرة
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            'Content-Type'  => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => env('OPENAI_CHAT_MODEL', 'gpt-3.5-turbo'),
            'messages' => [
                ['role' => 'system', 'content' => 'أنت مساعد مفيد ولطيف. أجب على أسئلة المستخدم بدقة وإيجاز.'],
                ['role' => 'user', 'content' => $text]
            ],
            'temperature' => 0.7,
            'max_tokens' => 500
        ])->json();

        Log::info('استجابة ChatGPT', ['response' => $response]);

        return [
            'status' => 'ok',
            'answer' => $response['choices'][0]['message']['content'] ?? 'لم أستطع الإجابة على هذا السؤال.',
            'model_type' => 'model_one'
        ];
    }

    /**
     * الحصول على إجابة باستخدام المساعد (النموذج الثاني)
     */
    private function useAssistant(string $text)
    {
        $controller = app(AssistantController::class);
        $request = new Request(['question' => $text]);

        $response = $controller->ask($request);
        $data = $response->getData(true);

        return [
            'status' => $data['status'] ?? 'error',
            'answer' => $data['answer'] ?? 'لم أستطع الإجابة على هذا السؤال.',
            'thread_id' => $data['thread_id'] ?? null,
            'run_id' => $data['run_id'] ?? null,
            'model_type' => 'assistant'
        ];
    }
}
