<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        return self::selectRaw('sender_id, sender_name, source, MAX(created_at) as last_message_at, COUNT(*) as message_count')
            ->groupBy('sender_id', 'sender_name', 'source')
            ->orderByDesc('last_message_at')
            ->get();
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
