<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'invitation_id',
        'user_id',
        'author_name',
        'author_email',
        'selected_text',
        'comment',
        'parent_comment_id',
        'page',
        'annotation_metadata',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'annotation_metadata' => 'array',
            'resolved_at' => 'datetime',
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parentComment()
    {
        return $this->belongsTo(Comment::class, 'parent_comment_id');
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_comment_id');
    }
}
