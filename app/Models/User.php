<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'email',
        'password',
        'role',
        'jwt_token_version',
        'api_token_hash',
        'api_token_last_used_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'jwt_token_version' => 'integer',
            'api_token_last_used_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function ownedDocuments()
    {
        return $this->hasMany(Document::class, 'owner_id');
    }

    public function createdDocuments()
    {
        return $this->hasMany(Document::class, 'created_by_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function authoredComments()
    {
        return $this->hasMany(Comment::class, 'user_id');
    }

    public function signatures()
    {
        return $this->hasMany(Signature::class);
    }

    public function authoredSignatures()
    {
        return $this->hasMany(Signature::class, 'user_id');
    }

    public function performedAuditLogs()
    {
        return $this->hasMany(AuditLog::class, 'performed_by_id');
    }

    public function targetAuditLogs()
    {
        return $this->hasMany(AuditLog::class, 'target_user_id');
    }

    public function assignedDocuments()
    {
        return $this->hasMany(Document::class, 'user_id');
    }
}
