<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'sender_name',
        'message',
        'source',
        'is_reply',
        'attachment_url',
        'attachment_type',
        'read_at',
        'ms_id',

    ];

    protected $casts = [
        'is_reply' => 'boolean',
        'read_at' => 'datetime',
    ];

    /**
     * Get all messages for a specific sender
     */
    public static function getConversation(string $senderId)
    {
        return self::where('sender_id', $senderId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get all unique conversations grouped by sender
     */
    public static function getAllConversations()
    {
        $conversations = self::selectRaw('sender_id, sender_name, source, MAX(created_at) as last_message_at, COUNT(*) as message_count')
            ->groupBy('sender_id', 'sender_name', 'source')
            ->orderByDesc('last_message_at')
            ->get();

        // التحقق من وجود اسم المرسل
        foreach ($conversations as $conversation) {
            // إذا كان اسم المرسل غير موجود، اجلبه من API
            if (empty($conversation->sender_name)) {
                $senderName = (new self)->getSenderName($conversation->sender_id);
                $conversation->sender_name = $senderName;

                // يتم تحديث الاسم في قاعدة البيانات لعدم إعادة طلبه لاحقًا من API
                if ($senderName) {
                    self::where('sender_id', $conversation->sender_id)->update(['sender_name' => $senderName]);
                }
            }
        }

        return $conversations;
    }
    public function getSenderName($senderId)
    {
        $accessToken = env('META_PAGE_ACCESS_TOKEN'); // ضع رمز الوصول هنا أو في .env
        $url = "https://graph.facebook.com/v15.0/{$senderId}?fields=name&access_token={$accessToken}";

        $response = Http::get($url);

        if ($response->ok()) {
            return $response->json('name'); // استخراج اسم المرسل
        }

        // معالجة الخطأ
        \Log::error('Error fetching sender name', ['response' => $response->body()]);
        return null;
    }


    /**
     * Check if there are unread messages from this sender
     */
    public function hasUnreadMessages(string $senderId): bool
    {
        return self::where('sender_id', $senderId)
            ->where('is_reply', false)
            ->whereNull('read_at')
            ->exists();
    }

    /**
     * Mark all messages from sender as read
     */
    public static function markAsRead(string $senderId): void
    {
        self::where('sender_id', $senderId)
            ->where('is_reply', false)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
