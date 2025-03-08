# z.api-chatwoot-bridge

This is a bridge connection Z-API Chatwoot.

[Z-API](https://www.z-api.io/)
[Chatwoot](https://github.com/chatwoot/chatwoot)

## Configuração

```
# Z-API
ZAPI_INSTANCE_ID="instance-id"
ZAPI_TOKEN="token"
ZAPI_BASE_URL="https://api.z-api.io"

# Chatwoot
CHATWOOT_BASE_URL="https://chatwoot.com"
CHATWOOT_API_TOKEN="token"
CHATWOOT_ACCOUNT_ID="account-id"
CHATWOOT_INBOX_ID="inbox-id"
```

```diff
+ CHATWOOT_BASE_URL:
# Link da plataforma ex: https://chatwoot.aiu4.com/
+ CHATWOOT_API_TOKEN:
# Configurações do Perfil / Token de acesso
+ CHATWOOT_ACCOUNT_ID:
# https://chatwoot.aiu4.com/app/accounts/[2]/inbox/2
+ CHATWOOT_INBOX_ID:
# https://chatwoot.aiu4.com/app/accounts/2/inbox/[2]
```

## Estrutura do projeto

```
z.api-chatwoot-bridge/
├── src/
│   ├── WebhookHandler.php
│   ├── ZAPIHandler.php
│   ├── ChatwootHandler.php
│   └── Logger.php
├── webhook.php
├── logs/
│   └── app.log
└── composer.json
```

# Roadmap

- [x] Criar conexão com Z-API
- [x] Criar conexão com Chatwoot
- [x] Criar conexão com Webhook
- [x] Enviar mensagens da Z-API (contato) para o Chatwoot
- [x] Enviar mensagens do Chatwoot para o Z-API

- [ ] Enviar mensagens do Z-API (minhas) para o Chatwoot
- [ ] Enviar anexos do Chatwoot para o Z-API
- [ ] Enviar anexos do Z-API para o Chatwoot