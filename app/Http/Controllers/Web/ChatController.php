<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    /**
     * Render the chat app shell. The conversation list, messages, etc. are
     * all loaded client-side via JS polling the JSON endpoints under /app/*.
     */
    public function index(Request $request, $conversation = null)
    {
        return view('chat.index', [
            'authUser' => $request->user(),
            'initialConversationId' => $conversation,
        ]);
    }
}
