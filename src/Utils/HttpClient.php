<?php

namespace ZapiWoot\Utils;

/**
 * Cliente HTTP baseado em cURL para as APIs Z-API e Chatwoot.
 * Retorna o resultado bruto; o tratamento de erro/log fica com quem chama.
 */
class HttpClient
{
    private int $connectTimeout;
    private int $timeout;

    public function __construct(int $connectTimeout = 10, int $timeout = 30)
    {
        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
    }

    /**
     * Executa uma requisição JSON.
     *
     * @param array<string,mixed>|null $data Corpo JSON (POST/PUT/PATCH) ou query (GET)
     * @param string[] $headers
     * @return array{status:int, body:mixed, error:?string}
     */
    public function request(string $method, string $url, ?array $data = null, array $headers = []): array
    {
        $method = strtoupper($method);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($method === 'GET') {
            if (!empty($data)) {
                $url .= '?' . http_build_query($data);
            }
        } elseif ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        return [
            'status' => $status,
            'body' => $error ? null : json_decode((string) $response, true),
            'error' => $error,
        ];
    }
}
