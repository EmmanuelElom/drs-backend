<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'timestamp',
        'action',
        'performed_by',
        'performed_by_id',
        'target_user',
        'target_user_id',
        'document_title',
        'document_id',
        'details',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
        ];
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
