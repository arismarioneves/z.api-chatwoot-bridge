# z.api-chatwoot-bridge

This is a bridge connection [Z-API](https://www.z-api.io/) \ [Chatwoot](https://github.com/chatwoot/chatwoot).

Zapiwot is a bridge connection between Z-API and Chatwoot. It allows you to connect your Z-API account with Chatwoot and send messages to your customers.

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
z.api-chatwoot-bridge/
├── src/
│   ├── WebhookHandler.php
│   ├── ZAPIHandler.php
│   ├── ChatwootHandler.php
│   └── Logger.php
├── logs/
│   ├── index.php
│   └── app.log
├── index.php
├── webhook.php
├── config.php
├── README.md
├── .gitignore
├── .htaccess
└── composer.json
```

# Roadmap

- [x] Criar conexão com Z-API
- [x] Criar conexão com Chatwoot
- [x] Criar conexão com Webhook
- [x] Enviar mensagens da Z-API (contato) para o Chatwoot
- [x] Enviar mensagens do Chatwoot para o Z-API
- [x] Enviar mensagens do Z-API (minhas) para o Chatwoot

- [ ] Enviar anexos do Chatwoot para o Z-API
- [ ] Enviar anexos do Z-API para o Chatwoot

- [ ] Obter informações do contato no Z-API (nome, foto, etc)

- [ ] Copatibilidade com conversas em grupo
