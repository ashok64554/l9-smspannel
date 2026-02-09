<?php

namespace App\Jobs;

use App\Models\WhatsAppConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WhatsAppReadReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $responseToken;
    protected int|null $configurationId;

    public function __construct(string $responseToken, ?int $configurationId)
    {
        $this->responseToken   = $responseToken;
        $this->configurationId = $configurationId;
    }

    public function handle(): void
    {
        if (!$this->configurationId) {
            return;
        }

        $config = WhatsAppConfiguration::find($this->configurationId);

        if (!$config || empty($config->access_token)) {
            return;
        }

        try {
            wAReplyMessageRead(
                base64_decode($config->access_token),
                $config->sender_number,
                $config->app_version,
                $this->responseToken
            );
        } catch (\Throwable $e) {
            \Log::error('WA Read Receipt Failed', [
                'error' => $e->getMessage(),
                'config_id' => $this->configurationId
            ]);
        }
    }
}
