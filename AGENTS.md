# AGENTS.md

## Visão geral

Microsserviço interno de disparo de e-mail transacional em lote. Recebe um POST
com HTML, assunto e lista de destinatários, persiste, enfileira e envia via
relay SMTP. Volume alvo: **mais de 1 milhão de e-mails/dia** (~12/s na média), projetado
para picos de **10×** (~120/s) com a fila absorvendo bursts.

Multi-cliente desde o início: cada sistema chamador tem API key própria,
remetente padrão, cota e rate limit. Não há templates, tracking de
abertura/clique, anexos nem envio síncrono.

**Estado do repositório: fundação pronta.** Existe `composer.json` (PSR-4
`App\` → `src/`), PHPUnit e PHPStan configurados, `docker-compose.yml` de dev e
`src/Infrastructure/Env.php`. Ainda não existe schema, Dockerfile nem CI. As
seções "Comandos" e "Pastas" mostram o que já existe.

## Persona
- **SEMPRE** use palavras simples e padronizadas.
- **SEMPRE** frases curtas e diretas.
- Evite palavras com vários significados.
- Use uma estrutura consistente nas instruções.

## Stack

| Camada | Escolha |
|---|---|
| Linguagem | PHP 8.5 como alvo; runtime atual é 8.4.15 (ver Ambiente) |
| HTTP | PHP puro, nginx + php-fpm em produção, `php -S` em dev |
| Banco | PostgreSQL 16+ (fonte da verdade) |
| Fila | RabbitMQ 3.13+ (`php-amqplib`) |
| Acesso a dados | PDO puro, sem ORM |
| Testes | PHPUnit |
| Análise estática | PHPStan nível 8, PSR-12 |
| Empacotamento | Docker + Docker Compose, uma imagem e três comandos |
| Envio | Relay SMTP (SES/SendGrid/Mailgun/Postmark) com AUTH + STARTTLS |

Sem framework (Laravel/Symfony), sem ORM (Doctrine/Eloquent), sem Redis.
Qualquer dependência nova precisa de justificativa explícita.

## Arquitetura e fluxo

```
cliente --POST--> api --(tx)--> Postgres: batches + messages + outbox
                                     |
                                  relay (CLI) --publish--> RabbitMQ: emails
                                                              |
                                                           worker --SMTP--> relay SMTP
                                                              |
                                                     Postgres: status/attempts
```

- **API** (`public/index.php`): autentica, valida, grava tudo em uma transação e
  responde `202`. Nunca envia e-mail nem bloqueia no SMTP.
- **Outbox** (`outbox`): garante que nenhuma mensagem seja perdida entre commit
  e publish e que nenhuma seja publicada sem estar persistida. O relay lê com
  `FOR UPDATE SKIP LOCKED`, publica com publisher confirm e marca
  `published_at`. Publicar direto no RabbitMQ fora da outbox é proibido.
- **Worker** (`bin/worker.php`): consome `emails`, envia 1 mensagem por vez
  (prefetch 1–5), grava resultado e faz ack. Falha transitória volta para a fila
  com backoff; após 5 tentativas vai para `emails.dlq` e status `failed`.
- Worker e relay são stateless: sem estado local, sem cron dentro do container,
  sem arquivo temporário persistente. Escala horizontal.

### Máquina de estados da mensagem

`queued` → `sending` → `sent` | `failed`

`attempts` e `last_error` são persistidos a cada tentativa. `failed` é terminal;
reprocessamento é manual, via DLQ.

## Contrato da API

Autenticação: `Authorization: Bearer <api_key>`. A chave é comparada por hash
SHA-256 com `hash_equals`; nunca é logada nem armazenada em claro.

### `POST /messages`

```json
{
  "subject": "Seu pedido saiu",
  "html": "<p>Olá...</p>",
  "from": "loja@cliente.com",
  "recipients": ["a@exemplo.com", "b@exemplo.com"]
}
```

- `subject`, `html`, `recipients` obrigatórios; `text` e `from` opcionais.
- `from` só aceito se o domínio estiver verificado/DKIM no relay para o cliente;
  ausente, usa o `from_email` do cliente.
- Gera **1 mensagem por destinatário**, com `To:` individual. Nunca um `To:` com
  a lista inteira (vazamento de destinatários + bounce derruba o lote).
- `Idempotency-Key` opcional (até 128 chars), único por `(client_id, key)`.
  Repetição devolve `200` com o mesmo `batch_id`, sem reenviar.
- Retorno `202`:

```json
{ "batch_id": "uuid", "message_ids": ["uuid", "..."] }
```

### `GET /messages/{id}`

Status individual: `status`, `attempts`, `last_error`, `created_at`, `sent_at`.

### `GET /stats`

Contadores por status (global e por cliente) e profundidade das filas `emails` e
`emails.dlq`.

### `GET /health`

`200` somente se PDO e AMQP responderem; `503` caso contrário.

Erros: `400` validação, `401` chave inválida/inativa, `404` mensagem de outro
cliente, `409` idempotência conflitante, `429` rate limit (com `Retry-After`).
Respostas de erro nunca ecoam HTML, destinatários ou chave.

### Limites de payload

HTML ≤ 256 KB, assunto ≤ 255 caracteres, 1–1.000 destinatários por POST,
e-mails validados, `from` ≤ 320 caracteres. `text` ausente é gerado com
`strip_tags` do HTML. Corpo maior é `400`, não truncado.

## Dados

- `clients`: `id`, `name`, `api_key_hash`, `from_email`, `from_name`,
  `rate_limit_per_sec`, `active`, `created_at`.
- `batches`: `id`, `client_id`, `idempotency_key`, `subject`, `html`, `text`,
  `created_at`, `html_purged_at`.
- `messages`: `id`, `batch_id`, `client_id`, `recipient`, `status`, `attempts`,
  `last_error`, `created_at`, `sent_at`.
- `outbox`: `id`, `message_id`, `published_at`, `created_at`.

IDs públicos em UUID v4; outbox em `bigserial`. Timestamps em UTC, `timestamptz`.
Índices mínimos: `messages(status)`, `messages(batch_id)`, `messages(created_at)`,
`outbox(published_at)`, `clients(api_key_hash)`, unique
`batches(client_id, idempotency_key)`.

Retenção: HTML/text é zerado quando o lote inteiro chega a estado terminal ou
após 30 dias; metadados (assunto, status, erro) ficam 90 dias; purge diário.
Nunca apagar `messages` de lote ainda não terminal.

Rate limit de ingestão por cliente: janela fixa de 1s contada no Postgres;
excedeu, `429`. Justiça entre clientes no envio não está no MVP (ver Limitações).

## Padrões de código

`[O]` = obrigatório. `[P]` = preferência. Regra `[O]` vence preferência.
Em dúvida, escolha o menor código que resolve. Não crie camada sem uso real.

### Dependências entre camadas

- `[O]` Direção única: `Http`/`Infrastructure` → `Application` → `Domain`.
  `Domain` não importa PDO, SMTP, AMQP, HTTP nem biblioteca externa.
- `[O]` Caso de uso depende de interface (`Application/Port`), nunca de classe
  concreta.
- `[O]` Controller não fala com banco, SMTP nem fila. Ele valida HTTP, chama
  1 caso de uso e monta a resposta.
- `[O]` Controller valida formato e limites (`400`). Regra de negócio fica no
  caso de uso ou no domínio.
- `[O]` SQL só em `Infrastructure/Persistence`. Regra de negócio só em
  `Domain`/`Application`.
- `[O]` Montagem de dependências só em `public/index.php` e `bin/*`. Sem
  container de DI.
- `[O]` Batch + mensagens + outbox gravados na mesma transação, dentro de
  `PdoBatchRepository::save()`.
- `[P]` Repositório devolve objeto do domínio ou array shape de leitura. Não
  devolva array associativo solto como entidade.

### Nomenclatura

- `[O]` Código, tabelas, colunas, JSON e commits em inglês. AGENTS.md em
  português.
- `[O]` Classes em `PascalCase`; métodos em `camelCase`; tabelas, variáveis e
  colunas em `snake_case`; constantes e env em `UPPER_SNAKE`.
- `[O]` Caso de uso = verbo + substantivo, sem sufixo: `CreateBatch`,
  `SendMessage`, `GetStats`. Não use `...UseCase`, `...Service`,
  `...Manager`.
- `[O]` Interface sem prefixo `I`, nomeada pelo papel: `MessageRepository`,
  `Mailer`, `Publisher`. Implementação leva a tecnologia na frente:
  `PdoMessageRepository`, `SmtpMailer`.
- `[O]` `find` devolve `?T`. `get` lança `NotFoundException`.
- `[P]` Booleanos com `is`/`has`. Sem abreviação fora de `id`, `html`, `url`.
- `[P]` DTO de entrada em `readonly class` só com mais de 3 campos. Retorno
  simples pode ser array shape.
- `[P]` Nome do teste descreve o comportamento:
  `test_it_returns_conflict_for_same_key_with_different_payload`.

### Pastas

```
public/index.php              # composition root da API
bin/worker.php                # composition root do worker
bin/relay.php                 # outbox -> RabbitMQ
bin/migrate.php               # aplica migrations/*.sql em ordem
bin/client.php                # cria/rotaciona cliente, imprime a chave 1x

src/
  Domain/
    Client.php
    Batch.php
    Message.php
    MessageStatus.php         # enum
  Application/
    CreateBatch.php           # um caso de uso por arquivo, plano
    SendMessage.php
    GetMessage.php
    GetStats.php
    Port/
      ClientRepository.php
      BatchRepository.php
      MessageRepository.php
      OutboxRepository.php
      Mailer.php
      Publisher.php
  Infrastructure/
    Env.php
    Persistence/Pdo*.php
    Mail/SmtpMailer.php
    Queue/RabbitMqPublisher.php
    Log/JsonLogger.php
  Http/
    Router.php
    ErrorHandler.php
    Auth.php
    Controller/*.php          # 1 por endpoint

migrations/*.sql
tests/Unit/                    # casos de uso e domínio, com fakes
tests/Integration/             # PDO, HTTP, SMTP fake
tests/Fake/                    # fakes em memória
docker/                        # nginx.conf, php.ini, Dockerfile
docker-compose.yml
.env.example
```

### Tratamento de erro

- `[O]` Exceções nomeadas pela causa: `ValidationException`,
  `UnauthorizedException`, `NotFoundException`, `ConflictException`,
  `RateLimitException`, `PermanentDeliveryException`,
  `TransientDeliveryException`.
- `[O]` Um único `Http/ErrorHandler` traduz exceção para status +
  `{"error":{"code":"...","message":"..."}}`. Sem `try/catch` por controller
  só para montar erro.
- `[O]` Não vazar stack trace, SQL, HTML, destinatário ou chave na resposta.
  Erro inesperado = `500 internal_error`.
- `[O]` Nunca capturar `Throwable` e ignorar. Capture só o que sabe tratar.
- `[O]` Worker: `Transient` volta para a fila; `Permanent` = `failed`;
  desconhecido = loga, conta tentativa e trata como transitório até o limite.
- `[O]` Configuração faltando derruba o boot. Nunca default silencioso para
  credencial.
- `[O]` Erros de SMTP: `5xx` permanente, `4xx`/timeout transitório. Não
  retentar permanente.
- `[O]` Sem `@`, `die()` ou `exit()` em `src/`. `exit` só em `bin/`.
- `[P]` Log de `4xx` em `info`; `5xx` em `error`, com ids e sem PII.

### Estilo

- `[O]` `declare(strict_types=1);` em todo arquivo. PSR-12. PSR-4 em `src/`.
- `[O]` Tipos explícitos em parâmetros, retornos e propriedades. Sem `mixed`
  sem necessidade.
- `[O]` Uma classe por arquivo; nome do arquivo = nome da classe. Sem `?>`.
- `[O]` `readonly` e `enum` quando o valor não muda. `MessageStatus` é enum.
- `[O]` Sem estado global, singleton ou static mutável.
- `[O]` SQL sempre parametrizado. Nunca concatenar valor de requisição.
- `[O]` Logs em JSON no stdout: `level`, `message_id`/`batch_id`, `client_id`,
  evento, erro. Nunca corpo, lista de destinatários, chave ou credencial.
- `[O]` Nada de código morto, comentado ou pasta vazia "para depois".
- `[P]` Função curta (cabe numa tela). Se precisa de comentário para entender,
  simplifique.
- `[P]` Comentário só para o "porquê", em inglês, uma linha. Limite conhecido:
  `shortcut: <limite>, <quando corrigir>`.
- `[P]` Argumentos nomeados quando há muitos booleanos ou opcionais.

### Testes

- `[O]` Caso de uso testado com fake em memória, sem Postgres e sem SMTP.
- `[O]` Repositório PDO e endpoint testados em `tests/Integration` com Postgres
  real e SMTP fake (Mailpit). Nunca enviar e-mail real.
- `[O]` Toda lógica não trivial (branch, loop, parser, segurança) sai com teste.
  Não declarar teste verde sem rodar.
- `[P]` Não testar getter/setter óbvio.

### Não criar

- `[O]` `Service`, `Manager`, `BaseRepository`,
  `AbstractController`, `UnitOfWork`, `EventBus`, CQRS ou domain events.
- `[O]` Interface para classe interna. Interface só em fronteira externa:
  banco, SMTP, fila, HTTP.
- `[O]` DTO ou mapeamento por camada quando o formato é o mesmo.
- `[O]` Framework, ORM ou container de DI "para facilitar".
- `[P]` Uma pasta por feature. Mantenha `Application` plano até doer.

## Comandos

**Disponíveis hoje:**

```
composer install
composer test          # PHPUnit
composer analyse       # PHPStan nível 8
docker compose up -d postgres rabbitmq mailpit   # dev, exige .env
```

**Ainda não existem:** `bin/*.php` (migrate, relay, worker, purge, client),
`public/index.php` e os serviços `api`, `worker` e `relay` no compose.

## Ambiente (fatos reais deste workspace)

- PHP CLI é 8.5.11; o alvo do código é 8.4. Não use sintaxe exclusiva de 8.5.
  As extensões `sockets` e `pdo_pgsql` são instaladas pelo `postCreateCommand`.
- Toda chamada PHP/Composer imprime `Xdebug: [Step Debug] Could not connect ...`
  no stderr. Inofensivo; use `XDEBUG_MODE=off` para saída limpa.
- Porta 8080 encaminhada; 8025 (Mailpit) e 15672 (RabbitMQ) também.
- O diretório é um repositório git.
- Existem `composer.json`, `phpunit.xml`, `phpstan.neon`, `.env.example` e
  `docker-compose.yml`. Não existe Dockerfile, CI nem app em `/var/www/html`.
- Sem docker até o rebuild que aplica a feature `docker-in-docker`.
- `.github/dependabot.yml` é o único workflow (features do devcontainer).

## Skills

`.agents/skills/` é conteúdo vendorizado (mattpocock/skills), fixado por hash em
`skills-lock.json`. **Não editar.** `grill-me` é alias manual que delega a
`grilling`. Orientação específica do projeto vai neste arquivo.

## NUNCA

1. Enviar um e-mail com múltiplos destinatários em `To`/`Cc`/`Bcc`. Fan-out
   sempre: uma mensagem por destinatário.
2. Logar HTML, corpo, lista de destinatários, API key ou credenciais — nem em
   debug, nem em exception handler, nem em teste.
3. Hardcodar credenciais; commitar `.env`; imprimir a API key mais de uma vez
   (só na criação/rotação).
4. Publicar no RabbitMQ fora da outbox, ou dar ack antes de persistir o
   resultado do envio.
5. Enviar e-mail de forma síncrona dentro da requisição HTTP, usar `mail()`/
   sendmail local, ou dormir/retentar no request.
6. Adicionar framework, ORM, Redis ou outra dependência pesada sem aprovação
   explícita.
7. Rodar migration, purge, `queue purge`, `DROP`/`TRUNCATE` ou qualquer
   operação destrutiva em produção — ou com `--force` — sem confirmação humana e
   backup.
8. Apontar dev/teste para SMTP ou banco de produção. Teste usa SMTP fake.
9. Retentar erro SMTP `5xx` ou descartar silenciosamente mensagem que falhou.
10. Editar `.agents/skills/` ou adicionar skills sem pedido.
11. Inventar comandos de build/lint/test ou afirmar que algo passou sem executar.
12. Usar sintaxe PHP 8.5 enquanto o runtime for 8.4.

## Limitações conhecidas (deferidas, não implementadas)

- Bounce/complaint por webhook e unsubscribe: supressão delegada ao ESP; só
  `5xx` no envio é registrado.
- Sem templates, tracking, anexos, agendamento, prioridade por cliente ou
  fairness entre clientes na fila.
- Sem métricas Prometheus/tracing; só logs JSON, `/stats` e `/health`.
- Sem CI. Se entrar, rodar `composer test` e `composer analyse` em PR e proibir
  push direto na `main`.