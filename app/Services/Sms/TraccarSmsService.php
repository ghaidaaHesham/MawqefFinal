<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class TraccarSmsService
{
    protected string $url;
    protected string $token;

    public function __construct()
    {
        $this->url   = config('services.traccar_sms.url');
        $this->token = config('services.traccar_sms.token');
    }

    /**
     * إرسال رسالة SMS
     */
   public function send(string $phone, string $message): array
{
    $phone = $this->formatPhoneNumber($phone);

    // ✅ DEBUG - اطبع كل التفاصيل
    Log::info('DEBUG Traccar Request', [
        'url' => $this->url,
        'token' => $this->token,
        'token_length' => strlen($this->token),
        'phone_original' => $phone,
        'phone_formatted' => $phone,
        'message' => $message,
        'headers' => [
            'Authorization' => $this->token,
            'Accept' => 'application/json',
        ],
        'body' => [
            'to' => $phone,
            'message' => $message,
        ],
    ]);

    try {
        $response = Http::withHeaders([
            'Authorization' => $this->token,
            'Accept'        => 'application/json',
        ])
            ->timeout(30)
            ->post($this->url, [
                'to'      => $phone,
                'message' => $message,
            ]);

        // ✅ DEBUG - اطبع الاستجابة
        Log::info('DEBUG Traccar Response', [
            'status' => $response->status(),
            'body' => $response->body(),
            'headers' => $response->headers(),
        ]);

        if (!$response->successful()) {
            throw new Exception(
                'SMS sending failed with status: ' . $response->status() .
                ' - Body: ' . $response->body()
            );
        }

        Log::info('SMS sent successfully', ['phone' => $phone]);

        return [
            'success'  => true,
            'response' => $response->json(),
        ];

    } catch (\Exception $e) {
        Log::error('SMS sending error', [
            'phone' => $phone,
            'error' => $e->getMessage(),
        ]);

        throw new Exception('SMS sending failed: ' . $e->getMessage());
    }
}

    /**
     * تنسيق رقم الهاتف
     */
    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone);

        if (preg_match('/^09[0-9]{8}$/', $phone)) {
            return '+963' . substr($phone, 1);
        }

        if (preg_match('/^9[0-9]{8}$/', $phone)) {
            return '+963' . $phone;
        }

        if (preg_match('/^\+963[0-9]{9}$/', $phone)) {
            return $phone;
        }

        return $phone;
    }
}