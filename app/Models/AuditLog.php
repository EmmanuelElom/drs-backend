<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'timestamp',
        'event_type',
        'action',
        'performed_by',
        'performed_by_id',
        'actor_id',
        'actor_name',
        'target_user',
        'target_user_id',
        'document_title',
        'document_id',
        'invitation_id',
        'details',
        'metadata',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
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
