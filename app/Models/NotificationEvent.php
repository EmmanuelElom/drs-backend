<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type',
        'action',
        'channel',
        'recipient_name',
        'recipient_email',
        'user_id',
        'document_id',
        'invitation_id',
        'subject',
        'payload',
        'status',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function invitation()
    {
        return $this->belongsTo(DocumentInvitation::class);
    }
}

