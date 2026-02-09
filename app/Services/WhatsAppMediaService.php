<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class WhatsAppMediaService
{
    public static function upload(string $absolutePath): ?array
    {
        if (!file_exists($absolutePath)) {
            Log::error('WhatsApp media not found', ['path' => $absolutePath]);
            return null;
        }

        $mimeType = mime_content_type($absolutePath);
        $size     = filesize($absolutePath);

        // WhatsApp size limits
        $limits = [
            'image'    => 5 * 1024 * 1024,
            'video'    => 16 * 1024 * 1024,
            'audio'    => 16 * 1024 * 1024,
            'document' => 100 * 1024 * 1024,
        ];

        $waType = match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            default => 'document',
        };

        if ($size > $limits[$waType]) {
            Log::error('WhatsApp media size exceeded', [
                'type' => $waType,
                'size' => $size,
            ]);
            return null;
        }

        $response = Http::withToken(config('services.whatsapp.token'))
            ->attach(
                'file',
                fopen($absolutePath, 'r'),
                basename($absolutePath)
            )
            ->post(
                'https://graph.facebook.com/' .
                config('services.whatsapp.version') . '/' .
                config('services.whatsapp.phone_number_id') .
                '/media',
                [
                    'messaging_product' => 'whatsapp',
                    'type' => $mimeType,
                ]
            );

        if (!$response->successful()) {
            Log::error('WhatsApp media upload failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        return [
            'id'   => $response->json('id'),
            'type' => $waType,
            'mime' => $mimeType,
            'size' => $size,
        ];
    }
}
