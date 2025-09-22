<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MessagesController extends Controller
{
    public function index()
    {
        $conversations = Message::getAllConversations();

        return view('messages.index', compact('conversations'));
    }

    public function show($senderId)
    {
        $conversation = Message::getConversation($senderId);
        $firstMessage = $conversation->first();
        $source = $firstMessage ? $firstMessage->source : 'facebook';

        // تحديث حالة القراءة للرسائل
        Message::markAsRead($senderId);

        return view('messages.show', compact('conversation', 'senderId', 'source'));
    }

    public function reply(Request $request, $senderId)
    {
        $request->validate([
            'message' => 'required|string|min:2'
        ]);

        $userText = $request->input('message');

        try {
            // تحديد نموذج الذكاء الاصطناعي المستخدم
            $modelType = Setting::get('ai_model', 'assistant');

            if ($modelType === 'model_one') {
                // استخدام النموذج الأول (ChatGPT)
                $chatGpt = app(\App\Services\ChatGptService::class);
                $chatGptResult = $chatGpt->getAnswer($userText, [], 'ar');
                $answer = $chatGptResult['answer'] ?? 'لم أستطع الإجابة على هذا السؤال.';
            } else {
                // استخدام النموذج الثاني (المساعد)
                $response = app(AssistantController::class)->ask(new Request(['question' => $userText]));
                $responseData = $response->getData(true);
                $answer = $responseData['answer'] ?? 'لم أستطع الإجابة على هذا السؤال.';
            }

            // تخزين الرد في قاعدة البيانات (يمكن إضافته لاحقاً)

            return response()->json([
                'status' => 'success',
                'message' => $answer,
                'model_used' => $modelType
            ]);

        } catch (\Exception $e) {
            Log::error('خطأ في معالجة الرد على الرسالة:', [
                'error' => $e->getMessage(),
                'sender_id' => $senderId
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'حدث خطأ أثناء معالجة الرد. يرجى المحاولة مرة أخرى.'
            ], 500);
        }
    }
}


