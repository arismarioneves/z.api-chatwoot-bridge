# Zapiwoot

Esta é uma ponte de conexão entre [Z-API](https://www.z-api.io/) e [Chatwoot](https://github.com/chatwoot/chatwoot).

Zapiwot é uma ponte entre Z-API e Chatwoot. Ele permite que você conecte sua conta Z-API ao Chatwoot e envie mensagens para seus clientes.

🟢 [Versão 1.6.0]

## Instalação

### 1. Clonar o repositório e instalar dependências

```bash
git clone https://github.com/arismarioneves/z.api-chatwoot-bridge.git zapiwoot
cd zapiwoot
composer install
```

### 2. Configurar banco de dados

```bash
mysql -u root -p < banco.sql
```

Edite as credenciais do banco em `config.exemplo.php`

### 3. Criar arquivo de configuração

**IMPORTANTE:** Copie o arquivo de exemplo para criar sua configuração:

```bash
cp config.exemplo.php config.php
```

Edite `config.php` com suas credenciais do Z-API e Chatwoot.

## Configuração

### Configuração do webhook

```
# Z-API
ZAPI_INSTANCE_ID="instance-id"
ZAPI_TOKEN="token"
ZAPI_SECURITY_TOKEN="security-token"
ZAPI_BASE_URL="https://api.z-api.io/"

# Chatwoot
CHATWOOT_BASE_URL="https://***/"
CHATWOOT_API_TOKEN="token"
CHATWOOT_ACCOUNT_ID="account-id"
CHATWOOT_INBOX_ID="inbox-id"
```

```diff
+ CHATWOOT_BASE_URL:
# Link da plataforma ex: https://chatwoot.com/
+ CHATWOOT_API_TOKEN:
# Configurações do Perfil / Token de acesso
+ CHATWOOT_ACCOUNT_ID:
# https://chatwoot.com/app/accounts/[2]/inbox/1
+ CHATWOOT_INBOX_ID:
# https://chatwoot.com/app/accounts/2/inbox/[1]
```

### Configurações Z-API

Instâncias Web / Webhooks e configurações gerais
- Configure o webhook adicionando a URL do Zapiwoot no campo **Ao receber**
- Marque a opção **Notificar as mensagens enviadas por mim também**

### Configuração no Chatwoot

Configurações / Caixas de Entrada / Adicionar Caixa de Entrada
 - Adicionar o nome do canal
 - Adicione a URL do Zapiwoot no campo **URL do webhook**

> Nota: Não precisa configurar o webhook no **Integrações** do Chatwoot, apenas na caixa de entrada.

## Estrutura do projeto

```
zapiwoot/
├── logs/
│   ├── index.php
│   └── app.log
├── src/
│   ├── Utils/
│   │   └── Formatter.php
│   ├── WebhookHandler.php
│   ├── ZAPIHandler.php
│   ├── ChatwootHandler.php
│   └── Logger.php
├── index.php
├── webhook.php
├── config.exemplo.php
├── composer.json
├── .gitignore
└── README.md
```

# Roadmap

## Funcionalidades Implementadas

- [x] Criar conexão com Z-API
- [x] Criar conexão com Chatwoot
- [x] Enviar mensagens de texto da Z-API para o Chatwoot
- [x] Enviar mensagens de texto do Chatwoot para o Z-API
- [x] Exibir informações do contato (nome e foto)
- [ ] Suportar o envio de anexos (imagens, vídeos, documentos, áudios)
- [ ] Compatibilidade com conversas em grupo
- [ ] Sincronizar mensagens enviadas via WhatsApp mobile

## Limitações Conhecidas

### Mensagens enviadas via WhatsApp mobile não aparecem no Chatwoot

Quando o atendente envia uma mensagem diretamente pelo WhatsApp no celular (não pelo Chatwoot), essa mensagem **não é sincronizada** com o Chatwoot.

**Motivo técnico:** A Z-API envia o `chatLid` (ID interno do WhatsApp) no campo `phone` ao invés do número de telefone real do contato. Sem o telefone real, não é possível identificar a conversa correta no Chatwoot.

# Contribuição

Se você quiser contribuir para o projeto, basta abrir um **Pull Request** ou salve o repositório dando uma ⭐ para incentivar o desenvolvimento.
