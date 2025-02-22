<?php

namespace WhatsappBridge;

class ZAPIHandler
{
    private $instanceId;
    private $token;
    private $baseUrl;

    public function __construct()
    {
        $this->instanceId = ZAPI_INSTANCE_ID;
        $this->token = ZAPI_TOKEN;
        $this->baseUrl = ZAPI_BASE_URL;
    }

    public function sendMessage($phone, $message, $attachment = null)
    {
        Logger::log('info', 'Sending message through Z-API', [
            'phone' => $phone,
            'message' => $message,
            'has_attachment' => !empty($attachment)
        ]);

        // Se tiver anexo, use o endpoint apropriado
        if ($attachment) {
            return $this->sendMessageWithAttachment($phone, $message, $attachment);
        }

        $endpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/send-text";

        $data = [
            'phone' => $phone,
            'message' => $message
        ];

        return $this->makeRequest('POST', $endpoint, $data);
    }

    private function sendMessageWithAttachment($phone, $message, $attachment)
    {
        // Implemente a lógica para enviar mensagens com anexos
        // Use o endpoint correto da Z-API para cada tipo de mídia
        $endpoint = "{$this->baseUrl}/instances/{$this->instanceId}/token/{$this->token}/send-media";

        $data = [
            'phone' => $phone,
            'message' => $message,
            'media' => $attachment
        ];

        return $this->makeRequest('POST', $endpoint, $data);
    }

    private function makeRequest($method, $endpoint, $data)
    {
        $headers = [
            'Content-Type: application/json',
            'client-token: ' . $this->token
        ];

        $ch = curl_init($endpoint);

        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, true);

        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);

        Logger::log('info', 'Z-API Response', [
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'response' => $response,
            'verbose_log' => $verboseLog
        ]);

        curl_close($ch);

        $responseData = json_decode($response, true);

        if ($httpCode !== 200 || !isset($responseData['zaapId'])) {
            Logger::log('error', 'Z-API request failed', [
                'http_code' => $httpCode,
                'response' => $responseData
            ]);
            return false;
        }

        return true;
    }
}
