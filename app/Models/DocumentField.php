<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentField extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'invitation_id',
        'assigned_recipient_email',
        'field_type',
        'page',
        'x',
        'y',
        'width',
        'height',
        'required',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'x' => 'decimal:6',
            'y' => 'decimal:6',
            'width' => 'decimal:6',
            'height' => 'decimal:6',
            'required' => 'boolean',
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

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }
}

