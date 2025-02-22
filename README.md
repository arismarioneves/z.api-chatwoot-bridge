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

## Estrutura do projeto

```
z.api-chatwoot-bridge/
├── src/
│   ├── ZAPIHandler.php
│   ├── ChatwootHandler.php
│   └── Logger.php
├── public/
│   └── webhook.php
├── logs/
│   └── app.log
└── composer.json
```