CREATE DATABASE IF NOT EXISTS zapiwoot;
USE zapiwoot;

-- Tabela para mapeamento LID / Phone (normalização WhatsApp)
CREATE TABLE IF NOT EXISTS contatos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL COMMENT 'Número de telefone formatado (ex: 5511999999999)',
    lid VARCHAR(50) DEFAULT NULL COMMENT 'LID do WhatsApp (ex: g1ff3a2d@lid)',
    nome VARCHAR(255) DEFAULT NULL COMMENT 'Nome do contato',
    foto_url TEXT DEFAULT NULL COMMENT 'URL da foto de perfil',
    chatwoot_contact_id INT DEFAULT NULL COMMENT 'Cache do ID do contato no Chatwoot',
    chatwoot_conversation_id INT DEFAULT NULL COMMENT 'Cache do ID da conversa no Chatwoot',
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ultima_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_phone (phone),
    UNIQUE KEY idx_lid (lid)
);
