@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">Conversations List</h4>
                    </div>
                    <div class="card-body">
                        @if(session('success'))
                            <div class="alert alert-success">
                                {{ session('success') }}
                            </div>
                        @endif

                        @if(session('error'))
                            <div class="alert alert-danger">
                                {{ session('error') }}
                            </div>
                        @endif

                        @if($conversations->isEmpty())
                            <div class="alert alert-info">
                                No conversations available.
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Sender</th>
                                            <th>Platform</th>
                                            <th>Message Count</th>
                                            <th>Last Updated</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($conversations as $conversation)
                                            <tr>
                                                <td>
                                                    {{ $conversation->sender_name }}
                                                    @if(App\Models\Message::where('sender_id', $conversation->sender_id)->where('is_reply', false)->whereNull('read_at')->exists())
                                                        <span class="badge bg-danger">New</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($conversation->source === 'facebook')
                                                        <span class="badge bg-primary">Facebook</span>
                                                    @elseif($conversation->source === 'instagram')
                                                        <span class="badge bg-info">Instagram</span>
                                                    @endif
                                                </td>
                                                <td>{{ $conversation->message_count }}</td>
                                                <td>
                                                    <a href="{{ route('messages.show', $conversation->sender_id) }}" class="btn btn-sm btn-primary">
                                                        View Conversation
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
