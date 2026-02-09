<?php

namespace App\Helpers;

use App\Models\WhatsAppChatBot;
use App\Models\WhatsAppChatBotSession;
use App\Models\WhatsAppReplyThread;
use App\Models\WhatsAppChatBotSessionApiRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatbotEngine
{
    protected int $defaultTimeoutMinutes = 30;
    protected $configuration;

    public function __construct($configuration)
    {
        $this->configuration = $configuration;
    }

    public function handleIncomingMessage(string $from, string $profileName, string $message, int $configurationId, array $payload = [])
    {
        $message = $this->extractUserMessage($message, $payload);

        // Check for existing active session
        $session = WhatsAppChatBotSession::where('customer_number', $from)
            ->where('whats_app_configuration_id', $configurationId)
            ->first();

        // Get all bot flows
        $bots = WhatsAppChatBot::where('whats_app_configuration_id', $configurationId)->get();

        // Step 1: If session exists and expired -> remove
        if ($session && $this->isSessionExpired($session)) {
            $session->delete();
            $session = null;
        }

        // Step 2: Detect initiation keyword
        $hasActiveSession = $session !== null;

        // Detect initiation bot based on session state
        $initiationBot = $this->detectInitiationBot($bots, $message, $hasActiveSession);

        // Step 2.5: Check if user is answering a pending switch
        $pending = Cache::get("switch_pending:{$configurationId}:{$from}");
        if ($pending) {
            return $this->handleSwitchResponse($from, $profileName, $message, $configurationId);
        }

        // Step 3: Session exists + user tries to start a new flow
        if ($session && $initiationBot) {
            $text = "You already have an active session. Do you want to cancel it and start '{$initiationBot->chatbot_name}'?";

            Cache::put("switch_pending:{$configurationId}:{$from}", [
                'old_session_id' => $session->id,
                'new_bot_id' => $initiationBot->id,
            ], now()->addMinutes(5));

            $this->sendReply($from, $text, 'buttons', [
                ['id' => 'yes', 'title' => 'Yes'],
                ['id' => 'no', 'title' => 'No']
            ]);

            return [
                'type' => 'message',
                'text' => $text,
                'options' => ['Yes', 'No']
            ];
        }

        // Step 4: No session + user starts new flow
        if (!$session && $initiationBot) {
            return $this->startSession($from, $profileName, $initiationBot);
        }

        // Step 5: Continue existing session
        if ($session) {
            return $this->continueSession($session, $message);
        }

        // Step 6: No session + no initiation → start default bot
        $defaultBot = $bots->firstWhere('is_default', true);
        if (!$session && $defaultBot) {
            return $this->startSession($from, $profileName, $defaultBot);
        }

        // Step 7: Absolute fallback
        return [
            'type' => 'message',
            'text' => "Sorry, I didn’t understand that."
        ];
    }

    protected function handleSwitchResponse(string $from, string $profileName, string $message, int $configurationId)
    {
        $pending = Cache::get("switch_pending:{$configurationId}:{$from}");
        if (!$pending) {
            return null;
        }

        if (strtolower($message) === 'yes') {
            WhatsAppChatBotSession::where('id', $pending['old_session_id'])->delete();
            $newBot = WhatsAppChatBot::find($pending['new_bot_id']);
            Cache::forget("switch_pending:{$configurationId}:{$from}");
            return $this->startSession($from, $profileName, $newBot);
        }

        if (strtolower($message) === 'no') {
            Cache::forget("switch_pending:{$configurationId}:{$from}");
            $this->sendReply($from, "Okay, continuing your current session.", 'message');
            return ['type' => 'message', 'text' => "Okay, continuing your current session."];
        }

        return null;
    }

    protected function isSessionExpired($session): bool
    {
        return $session->updated_at->addMinutes($this->defaultTimeoutMinutes)->isPast();
    }

    protected function startSession(string $from, string $profileName, $bot)
    {
        $flow = $bot->request_payload;

        $session = WhatsAppChatBotSession::create([
            'wa_chat_bot_id' => $bot->id,
            'whats_app_configuration_id' => $this->configuration->id,
            'customer_number' => $from,
            'profile_name' => $profileName,
            'current_step' => $flow['steps'][0]['id'] ?? null,
            'meta' => json_encode(['profile_name' => $profileName]),
        ]);

        return $this->goToStep($session, $flow, $session->current_step);
    }

    protected function continueSession($session, $message)
    {
        $flow = $session->bot->request_payload;
        $currentStep = $this->findStepById($flow['steps'], $session->current_step);

        if (!$currentStep) {
            $session->delete();
            return ['type' => 'message', 'text' => 'Session ended.'];
        }

        $messageNormalized = strtolower(trim($message));

        switch ($currentStep['type']) {
            case 'buttons':
                $userInput = trim($message);
                $userInputNormalized = strtolower($userInput);

                $selected = collect($currentStep['options'] ?? [])->first(function ($opt) use ($userInput, $userInputNormalized) {
                    return
                        trim($opt['id']) === $userInput ||
                        trim($opt['title']) === $userInput ||
                        strtolower(trim($opt['id'])) === $userInputNormalized ||
                        strtolower(trim($opt['title'])) === $userInputNormalized;
                });

                if ($selected) {
                    if (!empty($currentStep['field'])) {
                        $meta = json_decode($session->meta, true) ?? [];
                        $meta[$currentStep['field']] = $selected['title'] ?? $selected['id'];
                        $session->meta = json_encode($meta);
                        $session->save();
                    }

                    return $this->goToStep($session, $flow, $selected['next_step']);
                }

                return $this->processStep($session, '', $currentStep, $flow);


            case 'list':
                $selected = collect($currentStep['items'] ?? [])->first(function ($opt) use ($messageNormalized) {
                    return (isset($opt['id']) && strtolower($opt['id']) === $messageNormalized) || strtolower($opt['title']) === $messageNormalized;
                });
                if ($selected) {
                    if (!isset($selected['next_step']) || !$selected['next_step']) {
                        $session->delete();
                        return ['type' => 'message', 'text' => 'Session ended.'];
                    }
                    return $this->goToStep($session, $flow, $selected['next_step']);
                }
                return $this->processStep($session, '', $currentStep, $flow);

            case 'input':
                $meta = is_string($session->meta) ? json_decode($session->meta, true) : ($session->meta ?? []);
                $fieldKey = $currentStep['field'] ?? ($currentStep['save_as'] ?? 'input');
                $meta[$fieldKey] = $message;
                $session->meta = json_encode($meta);
                $session->save();

                if (!isset($currentStep['next_step']) || !$currentStep['next_step']) {
                    $session->delete();
                    return ['type' => 'message', 'text' => 'Session ended.'];
                }
                return $this->goToStep($session, $flow, $currentStep['next_step']);

            case 'condition':
                $case = collect($currentStep['cases'] ?? [])->first(function ($c) use ($messageNormalized) {
                    return strtolower($c['when']) === $messageNormalized;
                });
                if ($case) {
                    if (!isset($case['next_step']) || !$case['next_step']) {
                        $session->delete();
                        return ['type' => 'message', 'text' => 'Session ended.'];
                    }
                    return $this->goToStep($session, $flow, $case['next_step']);
                }
                return $this->processStep($session, '', $currentStep, $flow);

            default:
                return $this->processStep($session, '', $currentStep, $flow);
        }
    }

    protected function findStepById(array $steps, $id)
    {
        foreach ($steps as $s) {
            if ($s['id'] == $id)
                return $s;
        }
        return null;
    }

    // processStep(), goToStep(), replaceVars(), sendReply(), callExternalApi() remain the same from your last version
    /**
     * Process step execution
     */
    protected function processStep($session, $message, $step, $flow)
    {
        $meta = is_string($session->meta) ? json_decode($session->meta, true) : ($session->meta ?? []);

        switch ($step['type']) {
            case 'message':
                $text = $this->replaceVars($step['text'] ?? '', $meta);

                if (!empty($step['media'])) {
                    $media = $step['media'];
                    $this->sendReply($session->customer_number, $text, 'media', [
                        'media_type' => $media['file_extension'] === 'mp4' ? 'video' : 'image',
                        'url' => $media['file_name'],
                        'caption' => $text,
                    ]);
                } else {
                    $this->sendReply($session->customer_number, $text, 'text');
                }

                if (!empty($step['next_step'])) {
                    return $this->goToStep($session, $flow, $step['next_step']);
                }
                $session->delete();
                return null;

            case 'input':
                $prompt = $this->replaceVars($step['text'] ?? '', $meta);
                if (!empty($step['options'])) {
                    $this->sendReply($session->customer_number, $prompt, 'buttons', $step['options']);
                }
                elseif (!empty($step['media'])) {
                    $media = $step['media'];
                    $this->sendReply($session->customer_number, $prompt, 'media', [
                        'media_type' => $media['file_extension'] === 'mp4' ? 'video' : 'image',
                        'url' => $media['file_name'],
                        'caption' => $prompt,
                    ]);
                } else {
                    $this->sendReply($session->customer_number, $prompt, 'input');
                }

                // WAIT for user response
                return null;

            case 'buttons':
                $this->sendReply($session->customer_number, $step['text'], 'buttons', $step['options'] ?? []);
                // WAIT for user response
                return null;

            case 'list':
                $this->sendReply($session->customer_number, $step['text'], 'list', $step['items'] ?? []);
                // WAIT for user response
                return null;

            case 'api_call':
                $customer_number = $session->customer_number;
                $profile_name = $session->profile_name;

                $response = $this->callExternalApi($step, $meta, $customer_number, $profile_name);

                if (isset($step['save_response_as'])) {
                    $meta[$step['save_response_as']] = $response;
                    $session->meta = json_encode($meta);
                    $session->save();
                }

                if (!empty($step['next_step'])) {
                    return $this->goToStep($session, $flow, $step['next_step']);
                }
                $session->delete();
                return null;

            case 'media':
                $url = $this->replaceVars($step['url'], $meta);
                $caption = $this->replaceVars($step['caption'] ?? '', $meta);
                $mediaType = $step['media_type'] ?? 'image';

                $this->sendReply($session->customer_number, $caption, 'media', [
                    'media_type' => $mediaType,
                    'url' => $url,
                    'caption' => $caption
                ]);

                if (!empty($step['next_step'])) {
                    return $this->goToStep($session, $flow, $step['next_step']);
                }
                $session->delete();
                return null;

            case 'condition':
                $variable = $step['variable'] ?? null;
                $operator = $step['operator'] ?? 'equals';
                $value = $this->getNestedValue($meta, $variable);

                $matchedCase = null;

                foreach ($step['cases'] as $c) {
                    if ($c['when'] === 'else') {
                        $matchedCase = $c;
                    } elseif ($operator === 'equals' && (string) $value === (string) $c['when']) {
                        $matchedCase = $c;
                        break;
                    }
                }

                if ($matchedCase && !empty($matchedCase['next_step'])) {
                    return $this->goToStep($session, $flow, $matchedCase['next_step']);
                }

                $session->delete();
                $this->sendReply($session->customer_number, "Session ended.", 'end');
                return null;

            case 'end':
                $this->sendReply($session->customer_number, $step['text'] ?? "Session ended.", 'end');
                $session->delete();
                return null;
        }
    }

    /**
     * Go to next step in flow
     */
    protected function goToStep($session, $flow, $stepId)
    {
        $nextStep = collect($flow['steps'])->firstWhere('id', $stepId);

        if ($nextStep) {
            $session->current_step = $nextStep['id'];
            $session->save();
            return $this->processStep($session, '', $nextStep, $flow);
        }

        $session->delete();
        $this->sendReply($session->customer_number, "Session ended.", 'end');
        return null;
    }

    /**
     * Replace variables in text with meta values
     */
    protected function replaceVars($text, $vars)
    {
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function ($matches) use ($vars) {
            $key = $matches[1];
            $default = null;

            if (strpos($key, '|') !== false) {
                [$key, $default] = explode('|', $key, 2);
            }

            $value = $this->getNestedValue($vars, $key);
            return $value ?? $default ?? '';
        }, $text);
    }

    protected function getNestedValue($array, $key)
    {
        $keys = explode('.', $key);
        foreach ($keys as $k) {
            if (!is_array($array) || !array_key_exists($k, $array)) {
                return null;
            }
            $array = $array[$k];
        }
        return $array;
    }

    /**
     * Send WhatsApp reply
     */
    protected function sendReply($to, $text, $step_type, $options = null)
    {
        $conf = $this->configuration;
        $sender_number = $conf->sender_number;
        $appVersion = $conf->app_version;
        $access_token = base64_decode($conf->access_token);
        $mediaType = null;
        if ($step_type === 'media' && is_array($options)) {
            $mediaType = $options['media_type'] ?? 'image';
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => $mediaType,
                $mediaType => [
                    'link' => $options['url'],
                    'caption' => $options['caption'] ?? ''
                ]
            ];
            $wa_message = $options['url'];
        } else {
            $payload = createWhatsappPayloadBot($to, $text, $step_type, $options);
            $wa_message = $text;
        }

        $url = "https://graph.facebook.com/$appVersion/$sender_number/messages";
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json'
        ])->post($url, $payload);

        $wa_response = json_decode($response, true);
        $response_token = @$wa_response['messages'][0]['id'];
        
        // Reply thread
        $this->chatBotThread($to, $wa_message, $response_token, $mediaType);

        //Log::channel('whatsapp_bot')->info("Sent to {$to}", $payload);
        //Log::channel('whatsapp_bot')->info($response->body());

        return $text;
    }

    /**
     * Call external API
     */
    protected function callExternalApi($step, $meta, $customer_number, $profile_name = null)
    {
        $method = strtoupper($step['method'] ?? 'GET');
        $url = $this->replaceVars($step['url'], $meta);
        $headers = $step['headers'] ?? [];
        $payload = [];

        if (!empty($step['payload'])) {
            foreach ($step['payload'] as $k => $v) {
                $payload[$k] = $this->replaceVars($v, $meta);
            }
        }

        // https://ok-go.in/wa-webhook-session-api-record 
        // if record then direct save
        if ($url == env('APP_URL', 'https://ok-go.in') . '/wa-webhook-session-api-record') {
            $payload['wa_user_customer_number'] = $customer_number;
            $payload['wa_user_profile_name'] = $profile_name;
            $sessionResponse = WhatsAppChatBotSessionApiRecord::create([
                'whats_app_configuration_id' => $this->configuration->id,
                'request_payload' => $payload,
            ]);
            return json_encode(['status' => true]);
        }

        try {
            $response = Http::withHeaders($headers)->$method($url, $payload);
            return $response->json();
        } catch (\Exception $e) {
            Log::error("API call failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Detect chatbot initiation based on DB-driven rules
     *
     * matching_criteria source  : DB column
     * priority source           : DB column
     * start_with source         : DB column
     * default bot source        : is_default column
     */
    protected function detectInitiationBot($bots, string $message, bool $hasActiveSession = false)
    {
        $normalizedMessage = strtolower(trim($message));

        // 1️Sort bots by DB priority (high → low)
        $sortedBots = $bots->sortByDesc('priority');

        $defaultBot = null;

        // First pass: exact / contain / regex
        foreach ($sortedBots as $bot) {

            $criteria = strtolower($bot->matching_criteria ?? 'exact');

            // Collect default bot but don't execute yet
            if ($criteria === 'default' || $bot->is_default) {
                if (!$defaultBot) {
                    $defaultBot = $bot;
                }
                continue;
            }

            // During active session, ignore non-exact matches to prevent accidental switch
            if ($hasActiveSession && $criteria !== 'exact') {
                continue;
            }

            $startWith = $bot->start_with ?? '';

            if (!$startWith) {
                continue;
            }

            $startWith = strtolower(trim($startWith));

            // EXACT
            if ($criteria === 'exact') {
                if ($normalizedMessage === $startWith) {
                    return $bot;
                }
            }

            // CONTAIN (any word match)
            elseif ($criteria === 'contain') {
                $keywords = preg_split('/\s+/', $startWith);

                foreach ($keywords as $word) {
                    if ($word !== '' && str_contains($normalizedMessage, $word)) {
                        return $bot;
                    }
                }
            }

            // REGEX
            elseif ($criteria === 'regex') {
                try {
                    if (preg_match('/' . $startWith . '/i', $normalizedMessage)) {
                        return $bot;
                    }
                } catch (\Throwable $e) {
                    \Log::error('Invalid chatbot regex', [
                        'bot_id' => $bot->id,
                        'regex' => $startWith,
                    ]);
                }
            }
        }

        // Second pass: DEFAULT (catch-all)
        // ONLY valid if NO active session
        if (!$hasActiveSession && $defaultBot) {
            return $defaultBot;
        }

        return null;
    }

    protected function chatBotThread($to, $message, $response_token, $mediaType)
    {
        $wa_reply_threads[] = [
            'user_id' => $this->configuration->user_id,
            'profile_name' => $this->configuration->name,
            'phone_number_id' => $this->configuration->display_phone_number_req,
            'display_phone_number' => $to,
            'user_mobile' => $to,
            'message' => $message,
            'message_type' => $mediaType,
            'context_ref_wa_id' => null,
            'response_token' => $response_token,
            'error_info' => null,
            'received_date' => date('Y-m-d H:i:s'),
            'use_credit' => null,
            'is_vendor_reply' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        \DB::table('whats_app_reply_threads')->insert($wa_reply_threads);
    }


    protected function extractUserMessage(string $message, array $payload = []): string
    {
        // WhatsApp interactive button
        if (isset($payload['interactive']['button_reply']['id'])) {
            return trim($payload['interactive']['button_reply']['id']);
        }

        // Older button payload
        if (isset($payload['button']['payload'])) {
            return trim($payload['button']['payload']);
        }

        return trim($message);
    }

}
