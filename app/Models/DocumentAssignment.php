<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'user_id',
        'assigned_by',
        'assigned_at',
        'expires_at',
        'days_allowed',
        'review_acknowledged',
        'acknowledged_at',
        'signature_invited',
        'signature_invited_at',
        'signature_completed',
        'signature_completed_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'expires_at' => 'datetime',
            'days_allowed' => 'integer',
            'review_acknowledged' => 'boolean',
            'acknowledged_at' => 'datetime',
            'signature_invited' => 'boolean',
            'signature_invited_at' => 'datetime',
            'signature_completed' => 'boolean',
            'signature_completed_at' => 'datetime',
        ];
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignedByUser()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}

