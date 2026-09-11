<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\UserInviteMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class UserController extends Controller
{
    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only an admin can invite users.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'user',
        ]);

        try {
            Mail::to($user->email)->send(new UserInviteMail(
                $user->name,
                $user->email,
                $data['password'],
            ));
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'User was created, but the invitation email could not be sent. Check the SMTP settings and try again.',
            ], 502);
        }

        return response()->json([
            'message' => 'User invited and email sent successfully.',
            'user' => $user->only(['id', 'name', 'email', 'role']),
        ], 201);
    }

    /**
     * Search users for private or self conversations.
     * GET /api/users?q=ali@example.com
     */
    public function index(Request $request)
    {
        $query = User::query();

        if ($search = trim((string) $request->query('q', ''))) {
            $search = mb_strtolower($search);
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
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
