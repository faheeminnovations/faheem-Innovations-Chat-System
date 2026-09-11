<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    /**
     * List messages for a conversation.
     * GET /api/conversations/{conversation}/messages
     *
     * Query params:
     *  - after_id  : only return messages newer than this id (use for POLLING new messages)
     *  - before_id : only return messages older than this id (use for loading history / infinite scroll)
     *  - limit     : page size, default 30
     */
    public function index(Request $request, Conversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);

        $limit = min((int) $request->query('limit', 30), 100);

        $query = $conversation->messages()->with(['sender:id,name,avatar', 'attachments']);

        if ($afterId = $request->query('after_id')) {
            // Polling mode: get everything newer, oldest first.
            $messages = $query->where('id', '>', $afterId)->orderBy('id')->get();

            return response()->json([
                'messages' => $messages,
                'mode' => 'after',
            ]);
        }

        if ($beforeId = $request->query('before_id')) {
            $messages = $query->where('id', '<', $beforeId)
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->sortBy('id')
                ->values();

            return response()->json([
                'messages' => $messages,
                'mode' => 'before',
            ]);
        }

        // Initial load: latest page, oldest first.
        $messages = $query->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values();

        return response()->json([
            'messages' => $messages,
            'mode' => 'initial',
        ]);
    }

    /**
     * Send a message (text and/or a single file attachment).
     * POST /api/conversations/{conversation}/messages
     * multipart/form-data: body=Hello, file=<binary>  (either or both)
     */
    public function store(Request $request, Conversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'file' => ['nullable', 'file', 'max:20480'], // 20MB
        ]);

        if (empty($data['body']) && ! $request->hasFile('file')) {
            return response()->json(['message' => 'Message must have text or a file.'], 422);
        }

        $message = DB::transaction(function () use ($request, $conversation, $data) {
            $type = 'text';

            if ($request->hasFile('file')) {
                $type = Str::startsWith($request->file('file')->getMimeType(), 'image/') ? 'image' : 'file';
            }

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $request->user()->id,
                'body' => $data['body'] ?? null,
                'type' => $type,
            ]);

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $path = $file->store("chat-attachments/{$conversation->id}", 'public');

                $message->attachments()->create([
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'file_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }

            // Sending a message also marks it as read for the sender.
            ConversationParticipant::where('conversation_id', $conversation->id)
                ->where('user_id', $request->user()->id)
                ->update(['last_read_message_id' => $message->id]);

            // Touch the conversation so it bubbles to the top of the list.
            $conversation->touch();

            return $message;
        });

        $message->load(['sender:id,name,avatar', 'attachments']);

        return response()->json($message, 201);
    }

    /**
     * Mark all messages in a conversation as read (up to the latest one).
     * POST /api/conversations/{conversation}/messages/read
     */
    public function markRead(Request $request, Conversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);

        $latestId = $conversation->messages()->max('id');

        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $request->user()->id)
            ->update(['last_read_message_id' => $latestId ?? 0]);

        return response()->json(['message' => 'ok', 'last_read_message_id' => $latestId]);
    }

    /**
     * Soft-delete your own message.
     * DELETE /api/messages/{message}
     */
    public function destroy(Request $request, Message $message)
    {
        $this->authorizeParticipant($request, $message->conversation);

        if ($message->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only delete your own messages.'], 403);
        }

        $message->delete();

        return response()->json(['message' => 'Message deleted.']);
    }

    /**
     * Lightweight "is typing" signal, stored in cache (no websockets needed).
     * Frontend calls this on keypress (throttled), then polls the GET
     * endpoint every couple seconds to show "X is typing...".
     * POST /api/conversations/{conversation}/typing
     */
    public function typing(Request $request, Conversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);

        Cache::put(
            "typing.{$conversation->id}.{$request->user()->id}",
            $request->user()->name,
            now()->addSeconds(5)
        );

        return response()->json(['message' => 'ok']);
    }

    /**
     * GET /api/conversations/{conversation}/typing
     */
    public function typingUsers(Request $request, Conversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);

        $userIds = $conversation->users()->pluck('users.id');
        $typing = [];

        foreach ($userIds as $userId) {
            if ($userId === $request->user()->id) {
                continue;
            }

            $name = Cache::get("typing.{$conversation->id}.{$userId}");
            if ($name) {
                $typing[] = ['user_id' => $userId, 'name' => $name];
            }
        }

        return response()->json($typing);
    }

    protected function authorizeParticipant(Request $request, Conversation $conversation): void
    {
        $isParticipant = $conversation->users()
            ->where('users.id', $request->user()->id)
            ->exists();

        abort_unless($isParticipant, 403, 'You are not part of this conversation.');
    }
}
