<?php

namespace WhatsappBridge;

use WhatsappBridge\Utils\Formatter;

class ZAPIHandler
{
    private string $instanceId;
    private string $token;
    private string $securityToken;
    private string $baseUrl;

    public function __construct()
    {
        if (!defined('ZAPI_INSTANCE_ID') || !defined('ZAPI_TOKEN') || !defined('ZAPI_SECURITY_TOKEN') || !defined('ZAPI_BASE_URL')) {
            throw new \Exception("Z-API configuration constants are not defined.");
        }
        $this->instanceId = ZAPI_INSTANCE_ID;
        $this->token = ZAPI_TOKEN;
        $this->securityToken = ZAPI_SECURITY_TOKEN;
        $this->baseUrl = rtrim(ZAPI_BASE_URL, '/');
    }

    public function sendMessage(string $phone, string $message): ?array
    {
        $formatedPhone = Formatter::formatPhoneNumber($phone);
        Logger::log('info', 'Sending text via Z-API', ['phone' => $formatedPhone]);

        $endpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/send-text";
        $data = ['phone' => $formatedPhone, 'message' => $message];

        return $this->makeRequest('POST', $endpoint, $data);
    }

    public function sendMediaMessage(string $phone, string $mediaUrl, ?string $caption = null, string $mediaType = 'file', ?string $filename = null): ?array
    {
        $formatedPhone = Formatter::formatPhoneNumber($phone);
        Logger::log('info', 'Sending media via Z-API', ['phone' => $formatedPhone, 'type' => $mediaType]);

        $endpoint = null;
        $data = ['phone' => $formatedPhone];

        switch (strtolower($mediaType)) {
            case 'image':
                $endpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/send-image";
                $data['image'] = $mediaUrl;
                if (!empty($caption))
                    $data['caption'] = $caption;
                break;
            case 'video':
                $endpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/send-video";
                $data['video'] = $mediaUrl;
                if (!empty($caption))
                    $data['caption'] = $caption;
                break;
            case 'audio':
                $endpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/send-audio";
                $data['audio'] = $mediaUrl;
                break;
            case 'document':
            case 'file':
            default:
                $ext = pathinfo($mediaUrl, PATHINFO_EXTENSION) ?: 'pdf';
                $endpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/send-document/{$ext}";
                $data['document'] = $mediaUrl;
                $data['fileName'] = $filename ?? basename($mediaUrl);
                if (!empty($caption))
                    $data['caption'] = $caption;
                break;
        }

        if (!$endpoint) {
            Logger::log('error', 'Invalid media type', ['type' => $mediaType]);
            return null;
        }

        return $this->makeRequest('POST', $endpoint, $data);
    }

    public function getProfileInfo(string $phone): array
    {
        $formatedPhone = Formatter::formatPhoneNumber($phone);
        $defaultProfile = ['name' => $formatedPhone, 'phone' => $formatedPhone, 'avatar_url' => null];

        try {
            $profileEndpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/get-contact-info/{$formatedPhone}";
            $profileResponse = $this->makeRequest('GET', $profileEndpoint);

            if ($profileResponse && !isset($profileResponse['error'])) {
                return [
                    'name' => $profileResponse['name'] ?? $profileResponse['pushName'] ?? $formatedPhone,
                    'avatar_url' => $profileResponse['profilePictureUrl'] ?? $profileResponse['profileImage'] ?? null,
                    'phone' => $formatedPhone
                ];
            }

            return $defaultProfile;
        } catch (\Exception $e) {
            Logger::log('error', 'Failed to get profile', ['error' => $e->getMessage()]);
            return $defaultProfile;
        }
    }

    private function makeRequest(string $method, string $url, ?array $data = null): ?array
    {
        $headers = [
            'Content-Type: application/json',
            'Client-Token: ' . $this->securityToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Logger::log('error', 'Z-API cURL error', ['error' => $curlError]);
            throw new \Exception("Z-API cURL request failed: " . $curlError);
        }

        $decodedResponse = json_decode($response, true);

        if ($httpCode >= 400) {
            Logger::log('error', 'Z-API HTTP error', ['code' => $httpCode, 'response' => $decodedResponse]);
        }

        return $decodedResponse;
    }
}
