<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'recipient_email',
        'recipient_name',
        'invited_by',
        'invited_at',
        'expires_at',
        'access_token_hash',
        'invitation_type',
        'status',
        'viewed_at',
        'completed_at',
        'revoked_at',
        'recipient_order',
        'can_review',
        'can_comment',
        'can_sign',
        'signature_data',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'expires_at' => 'datetime',
            'viewed_at' => 'datetime',
            'completed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'recipient_order' => 'integer',
            'can_review' => 'boolean',
            'can_comment' => 'boolean',
            'can_sign' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function invitedByUser()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    public function fields()
    {
        return $this->hasMany(DocumentField::class);
    }
}

