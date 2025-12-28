# LID no WhatsApp

LID vai mudar como você identifica contatos no WhatsApp. Neste artigo você vai entender de forma prática o que é, por que foi criado por causa da privacidade, como ele é um identificador único e privado que pode substituir o número, quando o campo phone retorna número ou retorna, quando o chatLid aparece ou vem como null, como a Z-API trata esses retornos e webhooks, exemplos reais, como enviar mensagens usando, quando preferir LID vs phone, boas práticas para sua software house, regras de conversão entre e número e o impacto nas suas integrações futuras.

## Principais conclusões

- Você deve usar o @lid em vez do número para identificar usuários.
- Ajuste seu sistema para salvar o @lid recebido nos webhooks.
- Se chatLid vier como null, trate como chat não disponível e use o @lid do remetente.
- A Z-API já suporta @lid; você pode processar e buscar contatos normalmente.
- Atualize buscas, validações e logs para procurar por @lid, não por número.

## O que é o LID e por que o WhatsApp está adotando esse identificador?

Você já percebeu que, às vezes, o WhatsApp não retorna mais o número de telefone nos webhooks? Isso acontece porque a plataforma começou a usar o @lid como identificador de contato.

O LID é uma etiqueta que representa um usuário sem expor o número real um apelido interno que o WhatsApp entrega ao seu sistema para falar sobre aquele contato.

A mudança veio com atualizações focadas em privacidade; o objetivo é reduzir a exposição direta do número em chamadas de API e logs.

A vantagem prática é clara: se o sistema só trabalha com identificadores que não revelam o número, fica mais difícil que alguém copie uma base com milhões de telefones. Para você, desenvolvedor ou dono de software, isso significa repensar como tratar e mapear identificadores.

Em alguns cenários o campo phone será o número; em outros, virá com um valor que é, na prática, um LID mascarado. Se o seu sistema continua usando apenas o telefone como chave primária, é hora de repensar a modelagem: trate o LID como a nova chave estável para integrações com WhatsApp.

## Diferença entre LID, phone e chatLid o que cada campo realmente significa

Entenda o papel de cada campo para evitar confusões:

- phone: costuma ser o número do usuário quando o WhatsApp ainda o expõe. Em versões mais recentes pode trazer um LID em vez do número.

- lid / @lid: identificador privado do usuário token que representa o contato sem revelar o telefone.

- chatLid: identificador da conversa ou do chat (não do usuário). Em grupos, chats de empresa ou threads específicas, aponta para o bate-papo. Pode vir como null quando não há um chat associado ao evento.

Quando o phone retorna número? Normalmente em contas antigas ou webhooks que ainda expõem o telefone. Quando o phone retorna LID? Em versões que preferem identificadores, por exemplo phone: “g1ff3a2d@lid”. Seu parser precisa tratar ambos os formatos validar apenas dígitos irá falhar ao receber LID.

Implemente checagens simples: verifique se o valor tem apenas dígitos ou se contém o sufixo @lid. Salve chatLid quando existir; trate chatLid: null como cenário normal.

## Como a Z-API trata o LID nos webhooks e nas integrações

A Z-API normaliza os retornos do WhatsApp e apresenta campos estáveis. Quando o webhook traz variações às vezes phone com número, às vezes phone com LID, outras com chatLid nulo a Z-API mapeia isso para uma estrutura consistente.

Você pode sempre checar contact.lid e contact.phone sem se preocupar com formatos inesperados.

Exemplos reais de retorno:

- from: “5511999999999”, messageId: “ABCD1234” (sem lid).
- from: “g1ff3a2d@lid”, messageId: “EFGH5678”, chatLid: null.

A Z-API entrega um objeto contact com contact.id, contact.lid e contact.phone, garantindo onde buscar o identificador oficial mesmo se o WhatsApp variar o formato.

A Z-API também armazena histórico e mapeamentos automáticos: quando um contato aparece pela primeira vez, registra e, se disponível, o número. Se depois o WhatsApp passar a retornar só LID, seu histórico e vínculo com o cliente permanecem intactos.

## Posso enviar mensagens usando o LID? Como funciona isso na prática?

Sim, o endpoint de envio aceita o identificador no campo to mesmo quando é um LID. Em vez de 5511999999999, você pode usar g1ff3a2d@lid. O WhatsApp entrega a mensagem ao usuário correspondente.

Quando usar LID vs phone?

- Use LID sempre que estiver disponível e cadastrado no seu sistema: é mais estável e reduz erros por mudanças de formato.
- Use phone quando precisar exibir ou validar o número para o usuário final (relatórios, envio de SMS, etc.).

Na Z-API, basta passar o lid recebido no webhook ao endpoint de envio; a plataforma traduz para o backend correto do WhatsApp.

Se o LID não resolver, a Z-API tenta fallback para o número se estiver disponível reduzindo falhas.

Fluxo prático recomendado:

- Ao receber webhook, armazene contact.lid.
- Ao enviar, recupere esse lid e poste para o endpoint.
- Se houver erro, logue e tente com contact.phone se existir.

Esse fluxo cobre a maioria dos casos e mantém operação estável enquanto o ecossistema evolui.

## Boas práticas para adaptar sua Software House

- Trate o LID como identificação preferencial. Tenha campo dedicado para lid, indexado e consultável.

- Não use só o phone como chave primária. Modele cliente com identificador interno, phone, lid e chatLid.

- Armazene metadados: data de coleta, origem do identificador (webhook/evento) e se o phone foi fornecido junto.

- Evite deduplicação apenas por telefone; use quando disponível e phone como apoio.

- Automatize sincronização entre LID e telefone; mantenha rotinas de reconciliação para evitar perfis duplicados.

- Considere logs e auditoria para consultas de mapeamento (número → LID) e para rastrear origem das alterações. Para proteger identificadores e evitar registros indevidos de dados sensíveis, siga recomendações como as da Boas práticas para não logar dados sensíveis.

Essas práticas reduzem retrabalho e tornam sua base mais resistente a mudanças futuras do WhatsApp. Se quiser escalar operações sem depender de números expostos, conheça também as soluções Phoneless e como elas ajudam a operar em larga escala.

## Conversão: posso transformar LID em número ou o contrário?

- LID → número: não é possível por meios diretos via API pública. A operação é proibida/indisponível o propósito do LID é proteger o telefone.

- Número → LID: pode existir via endpoints autorizados; isso normalmente exige permissões e validações. Fornecendo o telefone, o sistema pode retornar o associado quando permitido.

Projete seu banco com mapeamento phone / lid mas sem assumir que o mapeamento será sempre completo. Atualize registros quando receber um webhook com lid.

## O que muda no futuro das integrações de WhatsApp e como a Z-API já está preparada

A adoção sinaliza uma direção clara: mais privacidade nas integrações. Sistemas que assumem que o telefone sempre estará visível terão trabalho para se adaptar. Revise integrações, relatórios e importações; adapte telas administrativas para mostrar lid e explique quando o telefone não está disponível.

A Z-API já implementou suporte completo a chatLid e lid: normaliza webhooks, guarda mapeamentos e aceita no envio de mensagens. Assim, sua migração pode ser gradual e segura.

Planeje:

- Inventarie onde o telefone é usado como chave.

- Mapeie pontos que podem migrar e pontos que precisam do número.

- Teste fluxos em produção de forma controlada.

- Treine suporte e comunicação interna para reduzir chamados.
Com isso, seu sistema fica mais resiliente e sua base de usuários mais protegida.

## Checklist rápido de adoção

- Adicionar campo lid indexado na tabela de contatos.
- Atualizar webhooks para salvar contact.lid.
- Adaptar buscas, validações e logs para procurar por lid antes do phone.
- Tratar chatLid como opcional (aceitar null).
- Implementar fallback: em erro com lid, tentar phone se disponível.
- Registrar metadados (origem e data de coleta do lid).
- Automatizar reconciliação entre lid e phone.

## Conclusão

A mudança para não é só um detalhe técnico é uma virada de página na forma como você identifica e conversa com contatos no WhatsApp. Pense no @lid como um apelido seguro: ele dá identificação estável sem expor o número. Ajuste seu modelo de dados: salve e indexe o lid; não dependa só do phone.

Quando chatLid vier como null, trate isso como normal e use o lid do remetente. Lembre-se: você não pode converter LID → número por meios diretos; o inverso (número → LID) pode existir via rotas autorizadas.

A Z-API já normaliza webhooks, mantém mapeamentos e aceita LID nos envios aproveite isso: ao receber webhook, armazene contact.lid; ao enviar, use esse lid; em erro, tente fallback com phone. Simples, robusto e resiliente.

Adote boas práticas: campo dedicado para lid, metadados de coleta, logs de origem e rotinas de reconciliação. Priorize LID para integrações, mantenha phone como dado complementar e prepare seu sistema para mais privacidade no futuro.
