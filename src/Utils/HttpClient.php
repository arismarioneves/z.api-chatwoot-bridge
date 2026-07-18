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

    /**
     * Envia uma requisição multipart/form-data (para upload de arquivos).
     * Os arquivos devem vir como \CURLFile dentro de $fields.
     *
     * @param array<string,mixed> $fields
     * @param string[] $headers
     * @return array{status:int, body:mixed, error:?string}
     */
    public function requestMultipart(string $url, array $fields, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);

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

    /**
     * Baixa uma URL diretamente para um arquivo, abortando se exceder $maxBytes.
     *
     * @return array{ok:bool, status:int, mime:?string, error:?string}
     */
    public function download(string $url, string $destPath, int $maxBytes): array
    {
        $fp = fopen($destPath, 'wb');
        if ($fp === false) {
            return ['ok' => false, 'status' => 0, 'mime' => null, 'error' => 'cannot open destination'];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXFILESIZE, $maxBytes);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($ch, $dlTotal, $dlNow) use ($maxBytes) {
            return $dlNow > $maxBytes ? 1 : 0;
        });

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null;
        $error = curl_error($ch) ?: null;
        curl_close($ch);
        fclose($fp);

        if (!$ok || $error || $status < 200 || $status >= 300) {
            @unlink($destPath);
            return ['ok' => false, 'status' => $status, 'mime' => null, 'error' => $error];
        }

        return ['ok' => true, 'status' => $status, 'mime' => $mime, 'error' => null];
    }
}
