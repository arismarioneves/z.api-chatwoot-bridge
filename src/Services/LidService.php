<?php

namespace ZapiWoot\Services;

use ZapiWoot\Logger;
use ZapiWoot\Repository\ContactRepository;
use ZapiWoot\Utils\Formatter;

/**
 * Serviço para normalização e resolução de identificadores LID do WhatsApp
 */
class LidService
{
    private ?ContactRepository $repository = null;
    private ?\PDO $pdo = null;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo;

        if ($pdo) {
            $this->repository = new ContactRepository($pdo);
        }
    }

    /**
     * Verifica se o identificador é um LID
     */
    public function isLid(?string $identifier): bool
    {
        if (empty($identifier)) {
            return false;
        }
        return str_contains($identifier, '@lid');
    }

    /**
     * Extrai o LID do payload do webhook Z-API
     * 
     * O LID pode vir em diferentes lugares:
     * - chatLid: identificador do chat
     * - contact.lid: LID do contato
     * - phone: quando contém @lid
     */
    public function extractLidFromPayload(array $payload): ?string
    {
        // Prioridade 1: contact.lid
        $contactLid = $payload['contact']['lid'] ?? null;
        if ($contactLid && $this->isLid($contactLid)) {
            return $contactLid;
        }

        // Prioridade 2: chatLid
        $chatLid = $payload['chatLid'] ?? null;
        if ($chatLid && $this->isLid($chatLid)) {
            return $chatLid;
        }

        // Prioridade 3: phone quando é um LID
        $phone = $payload['phone'] ?? null;
        if ($phone && $this->isLid($phone)) {
            return $phone;
        }

        return null;
    }

    /**
     * Extrai o telefone real do payload do webhook Z-API
     */
    public function extractPhoneFromPayload(array $payload): ?string
    {
        // Prioridade 1: contact.phone
        $contactPhone = $payload['contact']['phone'] ?? null;
        if ($contactPhone && !$this->isLid($contactPhone)) {
            return Formatter::formatPhoneNumber($contactPhone);
        }

        // Prioridade 2: phone (se não for LID)
        $phone = $payload['phone'] ?? null;
        if ($phone && !$this->isLid($phone)) {
            return Formatter::formatPhoneNumber($phone);
        }

        // Prioridade 3: chatId (formato: 5511999999999@c.us)
        $chatId = $payload['chatId'] ?? null;
        if ($chatId && str_contains($chatId, '@c.us')) {
            $phoneFromChatId = str_replace('@c.us', '', $chatId);
            if (!$this->isLid($phoneFromChatId)) {
                return Formatter::formatPhoneNumber($phoneFromChatId);
            }
        }

        return null;
    }

    /**
     * Resolve um LID para o número de telefone correspondente
     */
    public function resolvePhone(string $identifier): ?string
    {
        // Se não for LID, apenas formatar o número
        if (!$this->isLid($identifier)) {
            return Formatter::formatPhoneNumber($identifier);
        }

        // Sem repositório, não é possível resolver
        if (!$this->repository) {
            Logger::log('warning', 'LidService: No repository available to resolve LID');
            return null;
        }

        $phone = $this->repository->getPhoneByLid($identifier);

        if ($phone) {
            Logger::log('info', 'LidService: Resolved LID to phone', ['lid' => $identifier, 'phone' => $phone]);
        } else {
            Logger::log('warning', 'LidService: Could not resolve LID', ['lid' => $identifier]);
        }

        return $phone;
    }

    /**
     * Registra o mapeamento entre telefone e LID
     */
    public function registerMapping(string $phone, string $lid): bool
    {
        if (!$this->repository) {
            Logger::log('warning', 'LidService: No repository available to register mapping');
            return false;
        }

        if (empty($phone) || empty($lid)) {
            return false;
        }

        // Formatar o telefone
        $formattedPhone = Formatter::formatPhoneNumber($phone);
        if (!$formattedPhone) {
            return false;
        }

        try {
            $result = $this->repository->updateLid($formattedPhone, $lid);

            if ($result) {
                Logger::log('info', 'LidService: Registered LID mapping', [
                    'phone' => $formattedPhone,
                    'lid' => $lid
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Logger::log('error', 'LidService: Failed to register mapping', [
                'error' => $e->getMessage(),
                'phone' => $formattedPhone,
                'lid' => $lid
            ]);
            return false;
        }
    }

    /**
     * Registra um contato completo
     */
    public function registerContact(string $phone, ?string $lid = null, ?string $nome = null, ?string $fotoUrl = null): bool
    {
        if (!$this->repository) {
            return false;
        }

        $formattedPhone = Formatter::formatPhoneNumber($phone);
        if (!$formattedPhone) {
            return false;
        }

        try {
            return $this->repository->upsert($formattedPhone, $lid, $nome, $fotoUrl);
        } catch (\Exception $e) {
            Logger::log('error', 'LidService: Failed to register contact', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Tenta resolver o telefone de um payload de webhook
     * Primeiro tenta extrair diretamente, depois tenta resolver via LID
     */
    public function resolvePhoneFromPayload(array $payload): ?string
    {
        // Primeiro, tentar extrair o telefone diretamente
        $phone = $this->extractPhoneFromPayload($payload);

        if ($phone) {
            return $phone;
        }

        // Se não encontrou telefone, tentar resolver via LID
        $lid = $this->extractLidFromPayload($payload);

        if ($lid) {
            return $this->resolvePhone($lid);
        }

        return null;
    }
}
