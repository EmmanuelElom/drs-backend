<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_uuid',
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
        'storage_mode',
        'days_allowed',
        'assigned_at',
        'expires_at',
        'status',
        'review_acknowledged',
        'acknowledged_at',
        'signature_invited',
        'signature_invited_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'expires_at' => 'datetime',
            'days_allowed' => 'integer',
            'acknowledged_at' => 'datetime',
            'signature_invited_at' => 'datetime',
            'review_acknowledged' => 'boolean',
            'signature_invited' => 'boolean',
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

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
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
