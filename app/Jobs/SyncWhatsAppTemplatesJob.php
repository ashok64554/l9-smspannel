<?php

namespace App\Jobs;

use App\Models\WhatsAppTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncWhatsAppTemplatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $waTemplateId;

    public $tries = 3;
    public $timeout = 120;

    public function __construct($waTemplateId)
    {
        $this->waTemplateId   = $waTemplateId;
    }

    public function handle()
    {
        try {

            $tempInfo = WhatsAppTemplate::where('wa_template_id', $this->waTemplateId)->first();
            $config = $tempInfo->whatsAppConfiguration;
            if (!$config) {
                Log::error("Configuration not found on this wa template ID: {$this->waTemplateId}");
                return;
            }

            $accessToken = base64_decode($config->access_token);
            $apiVersion  = $config->app_version ?? env('FB_APP_VERSION');

            $endpoint = "https://graph.facebook.com/{$apiVersion}/{$this->waTemplateId}";

            $response = Http::timeout(60)->get($endpoint, [
                'access_token' => $accessToken,
            ]);

            if (!$response->successful()) {
                Log::error("Meta API failed for template: {$this->waTemplateId}");
                return;
            }

            $responseData = $response->json();

            if (!empty($responseData)) {

                $status = ($responseData['status'] ?? '') === 'APPROVED' ? 1 : 0;
                $tempInfo->category = ucfirst(strtolower($responseData['category']));
                $tempInfo->status = $status;
                $tempInfo->updated_at = now();
                $tempInfo->save();

                Log::info("Template synced successfully: {$this->waTemplateId}");
            }

        } catch (\Exception $e) {
            Log::error("Template sync exception: " . $e->getMessage());
        }
    }
}
