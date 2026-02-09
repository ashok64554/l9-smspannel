<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppChatBotSessionApiRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'whats_app_configuration_id',
        'request_payload',
    ];

    protected $casts = [
        'request_payload' => 'array',    
    ];
}
