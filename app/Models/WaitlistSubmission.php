<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaitlistSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'source_path',
        'ip_address',
        'user_agent',
    ];
}
