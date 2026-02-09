<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class WhatsAppFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'configuration_id',
        'file_path',
        'file_type',
        'mime_type',
        'file_size',
        'file_caption',
        'wa_file_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
