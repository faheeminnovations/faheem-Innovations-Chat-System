<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    /**
     * List the authenticated user's conversations, ordered by most recent
     * activity, with last message + unread count preloaded.
     * GET /api/conversations
     *
     * This is the endpoint the frontend should POLL (e.g. every 3-5s)
     * to refresh the sidebar.
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $conversations = Conversation::query()
            ->whereHas('users', fn ($q) => $q->where('users.id', $userId))
            ->with([
                'users:id,name,email,avatar,is_online,last_seen_at',
                'lastMessage.sender:id,name,avatar',
            ])
            ->withCount(['messages as messages_count'])
            ->get()
            ->map(function (Conversation $conversation) use ($userId) {
                return $this->transformConversation($conversation, $userId);
            })
            ->sortByDesc('last_activity_at')
            ->values();

        return response()->json($conversations);
    }

    /**
     * Start a new private (1-to-1) or group conversation.
     * POST /api/conversations
     * { "type": "private", "user_id": 5 }
     * { "type": "group", "name": "Design Team", "user_ids": [2,3,4] }
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:private,group'],
            'user_id' => ['required_if:type,private', 'exists:users,id'],
            'user_ids' => ['required_if:type,group', 'array', 'min:1'],
            'user_ids.*' => ['exists:users,id'],
            'name' => ['required_if:type,group', 'string', 'max:255'],
        ]);

        $authUserId = $request->user()->id;

        if ($data['type'] === 'private') {
            $otherUserId = (int) $data['user_id'];

            // Self-chat must contain only the authenticated user; normal private
            // chats are matched by both participant IDs.
            $existingQuery = Conversation::query()
                ->where('type', 'private')
                ->whereHas('users', fn ($q) => $q->where('users.id', $authUserId));

            if ($otherUserId === $authUserId) {
                $existingQuery->whereDoesntHave('users', fn ($q) => $q->where('users.id', '!=', $authUserId));
            } else {
                $existingQuery->whereHas('users', fn ($q) => $q->where('users.id', $otherUserId));
            }

            $existing = $existingQuery->first();

            if ($existing) {
                return response()->json(
                    $this->transformConversation($existing->load('users', 'lastMessage.sender'), $authUserId)
                );
            }

            $conversation = DB::transaction(function () use ($authUserId, $otherUserId) {
                $conversation = Conversation::create([
                    'type' => 'private',
                    'created_by' => $authUserId,
                ]);

                $participants = [
                    $authUserId => ['joined_at' => now()],
                ];

                if ($otherUserId !== $authUserId) {
                    $participants[$otherUserId] = ['joined_at' => now()];
                }

                $conversation->users()->attach($participants);

                return $conversation;
            });
        } else {
            $userIds = array_unique(array_merge($data['user_ids'], [$authUserId]));

            $conversation = DB::transaction(function () use ($authUserId, $userIds, $data) {
                $conversation = Conversation::create([
                    'type' => 'group',
                    'name' => $data['name'],
                    'created_by' => $authUserId,
                ]);

                $attach = [];
                foreach ($userIds as $id) {
                    $attach[$id] = ['joined_at' => now()];
                }
                $conversation->users()->attach($attach);

                return $conversation;
            });
        }

        $conversation->load('users', 'lastMessage.sender');

        return response()->json($this->transformConversation($conversation, $authUserId), 201);
    }

    /**
     * GET /api/conversations/{conversation}
     */
    public function show(Request $request, Conversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);

        $conversation->load('users:id,name,email,avatar,is_online,last_seen_at', 'lastMessage.sender');

        return response()->json($this->transformConversation($conversation, $request->user()->id));
    }

    /**
     * Add participants to an existing group conversation.
     * POST /api/conversations/{conversation}/participants
     * { "user_ids": [7,8] }
     */
    public function addParticipants(Request $request, Conversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);

        if (! $conversation->isGroup()) {
            return response()->json(['message' => 'Only group conversations support adding participants.'], 422);
        }

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['exists:users,id'],
        ]);

        $existingIds = $conversation->users()->pluck('users.id')->all();
        $newIds = array_diff($data['user_ids'], $existingIds);

        $attach = [];
        foreach ($newIds as $id) {
            $attach[$id] = ['joined_at' => now()];
        }
        $conversation->users()->attach($attach);

        return response()->json($this->transformConversation(
            $conversation->fresh()->load('users', 'lastMessage.sender'),
            $request->user()->id
        ));
    }

    /**
     * Leave a group conversation.
     * DELETE /api/conversations/{conversation}/leave
     */
    public function leave(Request $request, Conversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);

        if (! $conversation->isGroup()) {
            return response()->json(['message' => 'You cannot leave a private conversation.'], 422);
        }

        $conversation->users()->detach($request->user()->id);

        return response()->json(['message' => 'Left conversation.']);
    }

    /**
     * Delete a group conversation (creator only).
     * DELETE /api/conversations/{conversation}
     */
    public function destroy(Request $request, Conversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);

        if ($conversation->created_by !== $request->user()->id) {
            return response()->json(['message' => 'Only the creator can delete this conversation.'], 403);
        }

        $conversation->delete();

        return response()->json(['message' => 'Conversation deleted.']);
    }

    /**
     * Abort with 403 unless the authenticated user belongs to the conversation.
     */
    protected function authorizeParticipant(Request $request, Conversation $conversation): void
    {
        $isParticipant = $conversation->users()
            ->where('users.id', $request->user()->id)
            ->exists();

        abort_unless($isParticipant, 403, 'You are not part of this conversation.');
    }

    /**
     * Shape a conversation for API output: title/avatar resolved for
     * private chats, unread count, and last message preview.
     */
    protected function transformConversation(Conversation $conversation, int $userId): array
    {
        $participant = $conversation->users->firstWhere('id', $userId);
        $lastReadId = $participant?->pivot?->last_read_message_id ?? 0;

        $unreadCount = $conversation->messages()
            ->where('id', '>', $lastReadId)
            ->where('user_id', '!=', $userId)
            ->count();

        if ($conversation->isGroup()) {
            $title = $conversation->name;
            $avatar = $conversation->avatar;
        } else {
            $other = $conversation->users->firstWhere('id', '!=', $userId)
                ?? $conversation->users->firstWhere('id', $userId);
            $title = $other?->id === $userId ? 'Saved messages' : ($other?->name ?? 'Unknown user');
            $avatar = $other?->avatar;
        }

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'title' => $title,
            'avatar' => $avatar,
            'is_self' => ! $conversation->isGroup() && isset($other) && $other?->id === $userId,
            'other_user' => $conversation->isGroup() ? null : $conversation->users->firstWhere('id', '!=', $userId),
            'participants' => $conversation->users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->avatar,
                'is_online' => (bool) $u->is_online,
                'last_seen_at' => $u->last_seen_at,
            ]),
            'last_message' => $conversation->lastMessage ? [
                'id' => $conversation->lastMessage->id,
                'body' => $conversation->lastMessage->body,
                'type' => $conversation->lastMessage->type,
                'sender_id' => $conversation->lastMessage->user_id,
                'sender_name' => $conversation->lastMessage->sender?->name,
                'created_at' => $conversation->lastMessage->created_at,
            ] : null,
            'unread_count' => $unreadCount,
            'last_activity_at' => $conversation->lastMessage?->created_at ?? $conversation->created_at,
            'created_at' => $conversation->created_at,
        ];
    }
}
