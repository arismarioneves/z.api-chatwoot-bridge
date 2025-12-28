# Zapiwoot

Esta é uma ponte de conexão entre [Z-API](https://www.z-api.io/) e [Chatwoot](https://github.com/chatwoot/chatwoot).

Zapiwot é uma ponte entre Z-API e Chatwoot. Ele permite que você conecte sua conta Z-API ao Chatwoot e envie mensagens para seus clientes.

🟢 [Versão 1.7.0]

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
│   ├── Repository/
│   │   └── ContactRepository.php
│   ├── Services/
│   │   └── LidService.php
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
- [x] **Suporte a LID** - Mapeamento automático de LID / Phone
- [ ] Suportar o envio de anexos (imagens, vídeos, documentos, áudios)
- [ ] Compatibilidade com conversas em grupo

## Suporte a LID (WhatsApp Identifier)

O WhatsApp está adotando o LID como identificador de contato em substituição ao número de telefone. O Zapiwoot agora suporta essa funcionalidade:

- **Mapeamento automático**: Quando um webhook chega com telefone real + LID, o sistema salva o mapeamento
- **Resolução de LID**: Mensagens enviadas via mobile com LID são resolvidas para o número conhecido
- **Tabela de contatos**: Nova tabela `contatos` para armazenar os mapeamentos

### Como funciona

1. Quando um cliente envia uma mensagem (webhook com phone + LID), o sistema salva o mapeamento
2. Quando você envia uma mensagem pelo WhatsApp mobile, o webhook vem apenas com LID
3. O sistema busca o phone correspondente ao LID e sincroniza com o Chatwoot

> **Nota**: O primeiro contato deve sempre vir do cliente (para registrar o mapeamento LID / Phone)

# Contribuição

Se você quiser contribuir para o projeto, basta abrir um **Pull Request** ou salve o repositório dando uma ⭐ para incentivar o desenvolvimento.
