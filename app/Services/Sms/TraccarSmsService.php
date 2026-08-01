<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class TraccarSmsService
{
    protected $token;
    protected $baseIp;
    protected $port;

    public function __construct()
    {
        $this->baseIp = '192.168.1.35';
        $this->port = 8082;
        $this->token = config('services.traccar_sms.token');
    }

    /**
     * إرسال رسالة SMS
     */
    public function send(string $phone, string $message): array
    {
        $url = "http://{$this->baseIp}:{$this->port}/sms";
        
        Log::info('📱 Sending SMS via Traccar', [
            'phone' => $phone,
            'url' => $url
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(30)->post($url, [
                'message' => $message,
                'to' => $phone,
            ]);

            if (!$response->successful()) {
                Log::error('❌ Traccar SMS failed', [
                    'phone' => $phone,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                throw new Exception("SMS sending failed: " . $response->body());
            }

            Log::info('✅ SMS sent successfully', [
                'phone' => $phone,
                'response' => $response->json()
            ]);

            return $response->json();

        } catch (\Exception $e) {
            Log::error('❌ Traccar SMS error', [
                'error' => $e->getMessage(),
                'phone' => $phone
            ]);
            throw new Exception("SMS sending failed: " . $e->getMessage());
        }
    }

    /**
     * اختبار الاتصال
     */
    public function testConnection(): array
    {
        try {
            $url = "http://{$this->baseIp}:{$this->port}/sms";
            
            $response = Http::withHeaders([
                'Authorization' => $this->token,
                'Content-Type' => 'application/json',
            ])->timeout(5)->get($url);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'url' => $url,
                'message' => $response->successful() ? '✅ Connected!' : '❌ Connection failed'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'url' => "http://{$this->baseIp}:{$this->port}/sms",
                'message' => '❌ Connection failed: ' . $e->getMessage()
            ];
        }
    }

    private function formatPhoneNumber($phone): string
    {
        $phone = preg_replace('/\s+/', '', $phone);
        if (preg_match('/^09[0-9]{8}$/', $phone)) {
            $phone = '+963' . substr($phone, 1);
        }
        if (preg_match('/^9[0-9]{8}$/', $phone)) {
            $phone = '+963' . $phone;
        }
        return preg_replace('/[^0-9+]/', '', $phone);
    }
}