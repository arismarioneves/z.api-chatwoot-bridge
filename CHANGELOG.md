# Changelog
Todas as alterações notáveis neste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](http://keepachangelog.com/)
e este projeto adere ao [Semantic Versioning](http://semver.org/).

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
