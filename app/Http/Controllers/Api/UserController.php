<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Search / list users you can start a chat with (everyone except yourself).
     * GET /api/users?q=ali
     */
    public function index(Request $request)
    {
        $query = User::query()->where('id', '!=', $request->user()->id);

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->paginate(20);

        return response()->json($users);
    }

    /**
     * Client should call this every 30-60 seconds while the app is open,
     * and again on page unload with is_online=false, to simulate presence
     * without needing websockets.
     * POST /api/heartbeat  { "is_online": true }
     */
    public function heartbeat(Request $request)
    {
        $data = $request->validate([
            'is_online' => ['sometimes', 'boolean'],
        ]);

        $request->user()->forceFill([
            'is_online' => $data['is_online'] ?? true,
            'last_seen_at' => now(),
        ])->save();

        return response()->json(['message' => 'ok']);
    }
}
