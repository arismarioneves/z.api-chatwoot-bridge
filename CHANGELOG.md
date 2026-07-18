# Changelog
Todas as alterações notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](http://keepachangelog.com/)
e este projeto adere ao [Semantic Versioning](http://semver.org/).

## [1.9.0] - 2026-07-18

### Adicionado
- Cache dos IDs de contato e conversa do Chatwoot na tabela `contatos`, reduzindo as chamadas à API do Chatwoot a cada mensagem.

### Modificado
- `ChatwootHandler` consulta o cache antes de buscar contato/conversa na API; a conversa em cache é reaberta e validada via `toggle_status`.

### Notas de atualização
- Instalações existentes: adicione as colunas `chatwoot_contact_id` e `chatwoot_conversation_id` à tabela `contatos` antes do deploy (ver `banco.sql`).

## [1.8.0] - 2026-07-18

### Adicionado
- Encaminhamento de anexos recebidos do WhatsApp (imagens, vídeos, áudios e documentos) para o Chatwoot.
- `HttpClient` compartilhado para as requisições às APIs Z-API e Chatwoot.

### Modificado
- `ChatwootHandler` passa a receber o `ZAPIHandler` por injeção de dependência.
- Handlers validam se as credenciais estão definidas e não vazias, falhando com mensagem clara quando ausentes.

### Corrigido
- Anexos recebidos do WhatsApp não eram enviados ao Chatwoot (apareciam como "[Mídia]").

### Segurança
- Download de foto de perfil e de mídia com limite de tamanho, timeout e validação de conteúdo.

### Removido
- Código sem uso em `LidService` (`resolvePhoneFromPayload` e propriedade redundante).

---

## [1.7.0] - 2025-12-27

### Adicionado
- **Suporte a LID (WhatsApp Identifier)** - Mapeamento automático de LID / Phone.
- Nova tabela `contatos` para armazenar mapeamentos LID / Phone.
- `LidService` para detecção e resolução de identificadores LID.
- `ContactRepository` para operações CRUD na tabela de contatos.
- Método `isLid()` no `Formatter` para detectar LIDs.
- Documentação sobre LID em `docs/Z-API_LID.md`.

### Modificado
- `WebhookHandler` agora usa `LidService` para processar mensagens com LID.
- Mensagens enviadas via WhatsApp mobile agora são sincronizadas com Chatwoot (após primeiro contato do cliente).
- Atualização da estrutura do projeto com novos diretórios `Repository` e `Services`.

### Corrigido
- Mensagens mobile que antes eram ignoradas por conterem LID agora são processadas corretamente.

---

## [1.6.0] - 2025-12-23

### Adicionado
- Funcionalidade para salvar a foto do perfil do contato recebida via webhook da Z-API.
- Atualização automática do contato no Chatwoot (Nome e Foto).
- Nova pasta `arquivos/logs` para armazenamento de logs.

### Modificado
- Localização dos logs alterada para `arquivos/logs`.
- Melhoria na estrutura do `WebhookHandler` e `ChatwootHandler`.

### Corrigido
- Correção na lógica de processamento de mensagens para garantir que apenas fotos de contatos sejam salvas.
