<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_uuid',
        'owner_id',
        'created_by_id',
        'user_id',
        'assigned_by_id',
        'title',
        'content',
        'file_name',
        'file_size',
        'file_type',
        'file_data',
        'file_disk',
        'file_path',
        'signed_file_disk',
        'signed_file_path',
        'signed_file_generated_at',
        'storage_mode',
        'days_allowed',
        'assigned_at',
        'sent_at',
        'expires_at',
        'status',
        'review_acknowledged',
        'acknowledged_at',
        'signature_invited',
        'signature_invited_at',
        'signature_completed',
        'signature_completed_at',
        'show_signatures_to_signers',
        'completed_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'days_allowed' => 'integer',
            'assigned_at' => 'datetime',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'signed_file_generated_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'signature_invited_at' => 'datetime',
            'signature_completed_at' => 'datetime',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
            'review_acknowledged' => 'boolean',
            'signature_invited' => 'boolean',
            'signature_completed' => 'boolean',
            'show_signatures_to_signers' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'document_uuid';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    public function assignments()
    {
        return $this->hasMany(DocumentAssignment::class);
    }

    public function invitations()
    {
        return $this->hasMany(DocumentInvitation::class);
    }

    public function fields()
    {
        return $this->hasMany(DocumentField::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}
