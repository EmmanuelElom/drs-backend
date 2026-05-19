<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Signature extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'invitation_id',
        'document_field_id',
        'user_id',
        'signer_name',
        'signer_email',
        'signature_data',
        'signed_at',
        'ip_address',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function invitation()
    {
        return $this->belongsTo(DocumentInvitation::class);
    }

    public function documentField()
    {
        return $this->belongsTo(DocumentField::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
