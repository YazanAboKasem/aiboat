@extends('layouts.app')

@section('styles')
<style>
    .chat-container {
        height: 70vh;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        padding: 15px;
    }
    .message {
        max-width: 75%;
        padding: 10px 15px;
        margin-bottom: 10px;
        border-radius: 15px;
        position: relative;
    }
    .message-time {
        font-size: 0.7rem;
        color: #888;
        margin-top: 5px;
    }
    .message-incoming {
        align-self: flex-start;
        background-color: #f1f0f0;
        border-bottom-left-radius: 5px;
    }
    .message-outgoing {
        align-self: flex-end;
        background-color: #dcf8c6;
        border-bottom-right-radius: 5px;
    }
    .message-attachment {
        max-width: 100%;
        margin-top: 10px;
    }
    .source-badge {
        position: absolute;
        top: -10px;
        font-size: 0.7rem;
    }
    .facebook-badge {
        background-color: #3b5998;
        color: white;
    }
    .instagram-badge {
        background-color: #833AB4;
        color: white;
    }
</style>
@endsection

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">
                            Conversation with: {{ $conversation->first()->sender_name ?? $senderId }}
                            @if($source === 'facebook')
                                <span class="badge bg-primary ms-2">Facebook</span>
                            @elseif($source === 'instagram')
                                <span class="badge bg-info ms-2">Instagram</span>
                            @endif
                        </h4>
                        <a href="{{ route('messages.index') }}" class="btn btn-light btn-sm">
                            Back to List
                        </a>
                    </div>

                    <div class="chat-container" id="chat-container">
                        @foreach($conversation as $message)
                            <div class="message {{ $message->is_reply ? 'message-outgoing' : 'message-incoming' }}">
                                @if($message->source === 'facebook')
                                    <span class="badge facebook-badge source-badge">Facebook</span>
                                @elseif($message->source === 'instagram')
                                    <span class="badge instagram-badge source-badge">Instagram</span>
                                @endif

                                <div>{{ $message->message }}</div>

                                @if($message->attachment_url)
                                    <div class="message-attachment">
                                        @if(in_array($message->attachment_type, ['image', 'photo']))
                                            <img src="{{ $message->attachment_url }}" alt="Attachment" class="img-fluid">
                                        @elseif($message->attachment_type === 'video')
                                            <video controls class="img-fluid">
                                                <source src="{{ $message->attachment_url }}" type="video/mp4">
                                                Your browser does not support video playback
                                            </video>
                                        @elseif($message->attachment_type === 'audio')
                                            <audio controls>
                                                <source src="{{ $message->attachment_url }}" type="audio/mpeg">
                                                Your browser does not support audio playback
                                            </audio>
                                        @else
                                            <a href="{{ $message->attachment_url }}" target="_blank" class="btn btn-sm btn-outline-primary">
                                                View Attachment
                                            </a>
                                        @endif
                                    </div>
                                @endif

                                <div class="message-time">
                                    {{ $message->created_at->format('Y-m-d H:i:s') }}
                                    @if($message->is_reply === false && $message->read_at)
                                        <span class="text-success">✓ Read</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="card-footer">
                        @if(session('error'))
                            <div class="alert alert-danger">
                                {{ session('error') }}
                            </div>
                        @endif

                        <form action="{{ route('messages.reply', $senderId) }}" method="POST">
                            @csrf
                            <input type="hidden" name="source" value="{{ $source }}">

                            <div class="input-group">
                                <input type="text" name="message" class="form-control" placeholder="Type your message here..." required>
                                <button type="submit" class="btn btn-primary">Send</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll to bottom of chat when page loads
        document.addEventListener('DOMContentLoaded', function() {
            var chatContainer = document.getElementById('chat-container');
            chatContainer.scrollTop = chatContainer.scrollHeight;
        });
    </script>
@endsection
