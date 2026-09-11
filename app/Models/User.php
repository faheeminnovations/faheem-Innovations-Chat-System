<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
// If you enable the optional token API (see README section 4: `php artisan install:api`),
// uncomment the next line and add `HasApiTokens` to the `use` trait list below.
// use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar',
        'is_online',
        'last_seen_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_online' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    // ---- Chat relationships ----

    public function conversationParticipants()
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function conversations()
    {
        return $this->belongsToMany(
            Conversation::class,
            'conversation_participants'
        )->withPivot(['last_read_message_id', 'joined_at'])->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Consider a user "online" if we've heard a heartbeat in the last 2 minutes.
     * Since we're using polling (no websockets/broadcasting), the frontend
     * should call POST /api/heartbeat every ~30-60 seconds while the app is open.
     */
    public function getIsRecentlyOnlineAttribute(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(2));
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
