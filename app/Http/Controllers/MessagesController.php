<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\ChatGptService;
use App\Services\EmbeddingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MessagesController extends Controller
{
    /**
     * عرض قائمة المحادثات
     */
    public function index()
    {
        $conversations = Message::getAllConversations();

        return view('messages.index', compact('conversations'));
    }

    /**
     * عرض محادثة مع مستخدم معين
     */
    public function show($senderId)
    {
        $conversation = Message::getConversation($senderId);
        $firstMessage = $conversation->first();
        $source = $firstMessage ? $firstMessage->source : 'facebook';

        // تحديث حالة القراءة للرسائل
        Message::markAsRead($senderId);

        return view('messages.show', compact('conversation', 'senderId', 'source'));
    }

    /**
     * إرسال رد إلى مستخدم
     */
    public function reply(Request $request, $senderId)
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'source' => 'required|in:facebook,instagram',
        ]);

        try {
            $message = $validated['message'];
            $source = $validated['source'];

            // إرسال الرسالة عبر API
            if ($source === 'instagram') {
                $this->sendInstagramMessage($senderId, $message);
            } else {
                $this->sendFacebookMessage($senderId, $message);
            }

            // حفظ الرد في قاعدة البيانات
            Message::create([
                'sender_id' => $senderId,
                'message' => $message,
                'source' => $source,
                'is_reply' => true,
            ]);

            return redirect()->back()->with('success', 'Reply sent successfully');

                    } catch (\Exception $e) {
            Log::error('Failed to send message reply', [
                'error' => $e->getMessage(),
                'sender_id' => $senderId,
            ]);

            return redirect()->back()->with('error', 'Failed to send reply: ' . $e->getMessage());
        }
    }

    /**
     * إرسال رسالة إلى Facebook
     */
    private function sendFacebookMessage(string $recipientId, string $messageText): void
    {
        $pageAccessToken = env('META_PAGE_ACCESS_TOKEN');
        if (!$pageAccessToken) {
            throw new \Exception('PAGE ACCESS TOKEN missing: set META_PAGE_ACCESS_TOKEN in .env');
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
            throw new \Exception('فشل إرسال الرسالة: ' . $resp->body());
        }
    }

    /**
     * إرسال رسالة إلى Instagram
     */
    private function sendInstagramMessage(string $igUserId, string $messageText): void
    {
        $pageAccessToken = env('META_PAGE_ACCESS_TOKEN');
        if (!$pageAccessToken) {
            throw new \Exception('PAGE ACCESS TOKEN missing: set META_PAGE_ACCESS_TOKEN in .env');
        }

        $endpoint = "https://graph.facebook.com/v21.0/me/messages";

        $payload = [
            'messaging_product' => 'instagram',
            'recipient'         => ['id' => $igUserId],
            'message'           => ['text' => $messageText],
            'access_token'      => $pageAccessToken,
        ];

        $resp = Http::asJson()->post($endpoint, $payload);

        if ($resp->failed()) {
            Log::error('IG Send API failed', [
                'status'  => $resp->status(),
                'body'    => $resp->body(),
            ]);
            throw new \Exception('فشل إرسال الرسالة: ' . $resp->body());
        }
    }
}
