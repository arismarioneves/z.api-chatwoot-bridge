<?php

namespace ZapiWoot\Repository;

/**
 * Repositório para gerenciamento de contatos com mapeamento LID / Phone
 */
class ContactRepository
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Busca contato pelo número de telefone
     */
    public function findByPhone(string $phone): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contatos WHERE phone = :phone LIMIT 1');
        $stmt->execute(['phone' => $phone]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Busca contato pelo LID
     */
    public function findByLid(string $lid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contatos WHERE lid = :lid LIMIT 1');
        $stmt->execute(['lid' => $lid]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Insere ou atualiza um contato
     */
    public function upsert(string $phone, ?string $lid = null, ?string $nome = null, ?string $fotoUrl = null): bool
    {
        $existing = $this->findByPhone($phone);

        if ($existing) {
            return $this->update($phone, $lid, $nome, $fotoUrl);
        }

        return $this->insert($phone, $lid, $nome, $fotoUrl);
    }

    /**
     * Insere novo contato
     */
    private function insert(string $phone, ?string $lid = null, ?string $nome = null, ?string $fotoUrl = null): bool
    {
        $sql = 'INSERT INTO contatos (phone, lid, nome, foto_url) VALUES (:phone, :lid, :nome, :foto_url)';
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute([
            'phone' => $phone,
            'lid' => $lid,
            'nome' => $nome,
            'foto_url' => $fotoUrl
        ]);
    }

    /**
     * Atualiza contato existente
     */
    private function update(string $phone, ?string $lid = null, ?string $nome = null, ?string $fotoUrl = null): bool
    {
        $fields = [];
        $params = ['phone' => $phone];

        if ($lid !== null) {
            $fields[] = 'lid = :lid';
            $params['lid'] = $lid;
        }

        if ($nome !== null) {
            $fields[] = 'nome = :nome';
            $params['nome'] = $nome;
        }

        if ($fotoUrl !== null) {
            $fields[] = 'foto_url = :foto_url';
            $params['foto_url'] = $fotoUrl;
        }

        if (empty($fields)) {
            return true; // Nada para atualizar
        }

        $sql = 'UPDATE contatos SET ' . implode(', ', $fields) . ' WHERE phone = :phone';
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Atualiza apenas o LID de um contato existente
     */
    public function updateLid(string $phone, string $lid): bool
    {
        // Verificar se o LID já está associado a outro telefone
        $existing = $this->findByLid($lid);

        if ($existing && $existing['phone'] !== $phone) {
            // LID já existe para outro telefone, atualizar para o novo
            $stmt = $this->pdo->prepare('UPDATE contatos SET lid = NULL WHERE lid = :lid');
            $stmt->execute(['lid' => $lid]);
        }

        return $this->upsert($phone, $lid);
    }

    /**
     * Busca telefone pelo LID
     */
    public function getPhoneByLid(string $lid): ?string
    {
        $contact = $this->findByLid($lid);
        return $contact['phone'] ?? null;
    }

    /**
     * Busca LID pelo telefone
     */
    public function getLidByPhone(string $phone): ?string
    {
        $contact = $this->findByPhone($phone);
        return $contact['lid'] ?? null;
    }
}
