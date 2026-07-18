<?php

namespace ZapiWoot;

use ZapiWoot\Utils\Formatter;
use ZapiWoot\Utils\HttpClient;

class ZAPIHandler
{
    private string $instanceId;
    private string $token;
    private string $securityToken;
    private string $baseUrl;
    private HttpClient $http;

    public function __construct()
    {
        foreach (['ZAPI_INSTANCE_ID', 'ZAPI_TOKEN', 'ZAPI_SECURITY_TOKEN', 'ZAPI_BASE_URL'] as $const) {
            if (!defined($const) || constant($const) === '') {
                throw new \Exception("Configuração Z-API ausente ou vazia: {$const}");
            }
        }
        $this->instanceId = ZAPI_INSTANCE_ID;
        $this->token = ZAPI_TOKEN;
        $this->securityToken = ZAPI_SECURITY_TOKEN;
        $this->baseUrl = rtrim(ZAPI_BASE_URL, '/');
        $this->http = new HttpClient();
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

        $result = $this->http->request($method, $url, $data, $headers);

        if ($result['error']) {
            Logger::log('error', 'Z-API cURL error', ['error' => $result['error']]);
            throw new \Exception("Z-API cURL request failed: " . $result['error']);
        }

        if ($result['status'] >= 400) {
            Logger::log('error', 'Z-API HTTP error', ['code' => $result['status'], 'response' => $result['body']]);
        }

        return $result['body'];
    }
}
