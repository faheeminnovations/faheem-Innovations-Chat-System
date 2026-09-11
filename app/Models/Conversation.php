<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'type',
        'name',
        'avatar',
        'created_by',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants()
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'conversation_participants'
        )->withPivot(['last_read_message_id', 'joined_at'])->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    public function lastMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function isGroup(): bool
    {
        return $this->type === 'group';
    }

    /**
     * For a private (1-to-1) chat, return the "other" participant relative to $userId.
     */
    public function otherParticipant(int $userId)
    {
        return $this->users->firstWhere('id', '!=', $userId);
    }
}
