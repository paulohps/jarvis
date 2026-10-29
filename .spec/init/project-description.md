# Jarvis — Project Description

## Overview

**Jarvis** é uma plataforma **pessoal e familiar** de automação do dia a dia. É usada somente pelo Paulo (super admin) e pelos familiares que ele convidar. O sistema é organizado em **módulos** que crescem com o tempo. Cada módulo resolve uma rotina concreta: emitir a invoice mensal do salário, agendar mensagens no WhatsApp, manter a lista de compras e os lembretes da casa. Não há cadastro público nem cobrança, e o sistema não é multi-tenant. Existe **um único household**: todos os usuários enxergam os mesmos dados dos módulos a que têm acesso. A exceção são as **contas de WhatsApp**, que pertencem a cada usuário.

O sistema tem **três clientes sobre o mesmo núcleo**:

- a **versão web** neste projeto Laravel, com Livewire 4, TailwindCSS 4 e TallStackUI;
- um **app iOS nativo** em Swift;
- um **app Android nativo** em Kotlin.

Toda regra de negócio fica em **Actions** (`app/Actions/<Module>/...`). A mesma Action é chamada pelo componente Livewire e pelo controller da **API REST** que os apps consomem, com autenticação por **Sanctum** e um token por dispositivo. O visual de web e apps segue o **padrão iOS 27**: o Liquid Glass refinado da WWDC 2026, com menos transparência padrão e vidro mais legível.

O **controle de acesso é binário por módulo**. O super admin liga ou desliga cada módulo para cada usuário. Quem tem o módulo vê e faz tudo dentro dele, e não existem papéis nem permissões finas. O login é **por convite** (o super admin convida por email), com **Google (Socialite)** ou senha. O idioma padrão é **inglês**, com tradução para **pt_BR**. O perfil do usuário é copiado do `../my-finance`: idioma, fuso horário e preferências de notificação.

**MVP:** a fundação, a central de notificações e os quatro módulos iniciais, todos na web, na API e nos dois apps nativos ao mesmo tempo.

- **Fundação:** auth, convites, módulos por usuário, perfil, motor de templates, Reverb e Horizon.
- **Central de notificações:** canais in-app, email, push e WhatsApp.
- **Módulos:** Invoices, WhatsApp, Lista de compras e Lembretes.
- **Comandos de voz** nos apps.

Novos módulos entram depois, um a um, sobre essa mesma fundação.

### Key Concepts

- **Household:** o conjunto único de dados compartilhados do sistema. Não existe tabela de tenants nem escopo por workspace. Um registro de qualquer módulo é visível a todo usuário com aquele módulo ligado. Exceção: contas de WhatsApp e dispositivos de push são **por usuário**.
- **Super admin:** o Paulo. Convida usuários, liga e desliga módulos por usuário, edita os templates dos tipos de notificação do sistema e liga ou desliga canais e tipos de notificação. Tem acesso a todos os módulos.
- **User (familiar):** a pessoa convidada. Entra com Google ou senha e acessa apenas os módulos ligados para ela.
- **Module:** a unidade funcional, identificada por slug (`invoices`, `whatsapp`, `shopping`, `reminders`). Na web vira rota e menu, na API vira um grupo de endpoints, e o acesso é verificado por middleware/gate em ambos. Módulo desligado para o usuário: as rotas web dão 403, a API dá 403 e o menu e as telas do app somem.
- **Module access:** o vínculo usuário ↔ módulo, ligado ou desligado pelo super admin. Ter acesso significa ver e operar tudo dentro do módulo.
- **Invitation:** um convite por email com token e validade. Ao aceitar, a pessoa cria a conta com senha ou com Google, usando o email convidado.
- **Action:** a classe única de regra de negócio, reutilizada por Livewire e API. Validação e autorização acontecem dentro dela ou na borda, mas nunca são duplicadas entre os dois clientes.
- **Template (motor `{% %}`):** o motor copiado do my-finance (`TemplateLexer`/`Parser`/`Renderer`/`Validator`).
  - Variáveis: `{% current_month_name %}`. O acesso a caminho funciona: `{% invoice.number %}`.
  - Condições: `{% if x %}…{% else %}…{% endif %}`, `{% if a.b == "v" %}`, `!=`.
  - Loops: `{% for item in items %}…{% endfor %}`, até 500 iterações.
  - `{{ }}` não é sintaxe e sai como texto.
  - Saída HTML escapada e sanitizada.
  - Usado em **três lugares**: templates de notificação (editados pelo super admin), mensagens de WhatsApp e assunto/corpo do email de invoice (estes dois escritos pelo próprio usuário). Cada contexto declara seu **schema de variáveis**, com validação ao salvar e preview.
- **Recurrence:** a regra de repetição compartilhada por invoices, mensagens de WhatsApp e lembretes. Presets: diário, semanal (dias da semana), mensal (dia N do mês ou último dia) e expressão cron customizada. Também guarda o horário e o fuso (o do dono da regra), além de início e fim opcionais. Um único scheduler (`schedule:run` a cada minuto) despacha jobs no Horizon para as recorrências vencidas.
- **Payer (quem é cobrado / Bill to):** nome/razão social e endereço livre, multilinha. Ex.: `Initech Corp — PO Box 1234, Springfield, IL 62701`.
- **Issuer (cobrador / Receiver information):** nome/razão social, email, CEP, rua e número, bairro, cidade/UF. Pode haver mais de um. Ex.: `Jane Doe Consulting Ltda`, São Paulo, SP.
- **Invoice:** o documento gerado em PDF, no layout da invoice de exemplo (fornecida pelo desenvolvedor, fora do repositório). Contém título "Invoice", Receiver information (issuer), Bill to (payer), número, data, vencimento, descrição do serviço (multilinha), moeda por extenso (ex.: "American dollar") e valor (ex.: `2,000.00`). Status: `draft` → `pending_review` → `sent` / `failed`.
- **Invoice number:** sequência **global** no formato `YYYYMM-NNN`, com o contador zerado a cada mês. Ex.: `202610-001`, `202610-002`. É gerada de forma atômica (lock) para não duplicar em execuções concorrentes.
- **Invoice recurrence:** a configuração que gera e envia invoices sozinha. Reúne:
  - payer e issuer;
  - destinatários (lista de emails);
  - assunto e corpo do email (templates `{% %}`);
  - recurrence;
  - descrição do serviço;
  - regra de data de emissão e regra de vencimento;
  - moeda e valor;
  - flag **envio automático** ou **requer revisão**.
- **Dynamic date rule:** a regra que calcula a data de emissão e a de vencimento a cada execução.
  - Emissão: "dia da execução" ou "dia X do mês corrente".
  - Vencimento: "emissão + N dias", "dia X do mês seguinte" ou "último dia do mês da emissão".
  - A invoice de exemplo, emitida em 01/09 e vencendo em 30/09, corresponde a "dia da execução" + "último dia do mês".
- **WhatsApp account:** um número conectado via **Evolution API** self-hosted, por QR code. É **por usuário**: cada um conecta 1 ou mais números e só envia pelos seus. O status (`connecting`, `open`, `closed`) chega por webhook da Evolution API e é propagado em tempo real via Reverb.
- **WhatsApp target:** o destino de um envio, que pode ser um contato (número) ou um grupo (JID). Contatos e grupos são sincronizados da Evolution API para seleção.
- **WhatsApp service (genérico):** o serviço/Action reutilizável por qualquer módulo (`SendWhatsAppMessage(account, target, body)`). É usado pelo módulo WhatsApp, pelo canal de notificação WhatsApp e pelos comandos de voz.
- **Scheduled message:** uma mensagem do módulo WhatsApp que pode ser **única** (data e hora) ou **recorrente** (recurrence), com 1 ou mais targets e corpo em template `{% %}`.
- **Shopping list:** uma lista de compras nomeada (ex.: "Compras da semana") com itens. Itens têm nome, quantidade/observação opcional e checkbox de "peguei". A lista é compartilhada pelo household e atualizada **em tempo real** (Reverb) entre os celulares de quem está no mercado.
- **Reminder / Task:** um item com título, nota opcional, data/hora opcional e recurrence opcional. Pode ser marcado como concluído. Na data/hora, dispara notificação por **push e WhatsApp**, conforme as preferências do usuário. Se for recorrente, a próxima ocorrência é gerada ao concluir ou ao vencer.
- **Notification center:** cópia do my-finance. É formado por:
  - **tipos** (classes registradas);
  - **canais**: `database` (in-app, em tempo real via Reverb), `mail`, `push` (FCM, que entrega via APNs no iOS) e `whatsapp`;
  - **templates** por tipo × canal × idioma, editados pelo super admin;
  - **preferências** por usuário × tipo × canal, no perfil.

  Cada canal é um job próprio na fila `notifications`, com retry.
- **Device:** o dispositivo móvel registrado para push, com token FCM, plataforma e usuário, vinculado ao token Sanctum do app.
- **Voice command:** o texto de um comando falado, mapeado para uma Action. Fluxo: um gatilho (Siri/App Intents no iOS, App Actions no Android, ou botão de microfone no app) leva ao speech-to-text nativo; o texto vai para o intérprete, que produz um tool call e executa a Action.
  - **Intérprete:** a **IA local do celular** (Apple Foundation Models / Gemini Nano) quando disponível, ou a **IA remota via `laravel/ai`** no backend. O usuário escolhe nas preferências do app.
  - **Comandos do MVP:** adicionar item a uma lista, marcar item, criar lembrete, concluir lembrete e enviar mensagem de WhatsApp para contato/grupo.
  - **Fato:** o iOS não permite registrar palavra de ativação customizada ("Hey Jarvis"), e na WWDC 2026 o SiriKit foi depreciado em favor do App Intents. O gatilho por voz no iOS é "Siri, …" + App Intent do Jarvis.

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.5 (runtime; `composer.json` declara `^8.3`) |
| Backend framework | Laravel 13 (`laravel/framework ^13.17`) |
| Web UI | Livewire 4 (`livewire/livewire ^4.1`, `livewire/blaze ^1.0`), Alpine.js, **TallStackUI 4** (a adicionar, como no my-finance) |
| CSS / build | TailwindCSS 4 (`tailwindcss ^4.0.7`, `@tailwindcss/vite`), Vite 8 via `vite-plus 0.3.0`, `laravel-vite-plugin ^3.1` |
| Visual language | Padrão iOS 27 (Liquid Glass refinado) na web e no iOS; Material 3 (Material You, cores dinâmicas) no Android |
| Database | **PostgreSQL** em produção (hoje: SQLite do starter kit) |
| Queues | **Laravel Horizon** + Redis (fila dedicada `notifications`, filas para `invoices` e `whatsapp`) |
| Realtime | **Laravel Reverb** (status de conexão do WhatsApp, listas de compras, central in-app) |
| Scheduler | `php artisan schedule:run` em container próprio; despacha recorrências vencidas |
| Auth web | Sessão + **Laravel Socialite (Google)** + senha; cadastro só por convite |
| Auth API | **Laravel Sanctum** (token por dispositivo) |
| API | REST versionada (`/api/v1`) com Eloquent API Resources, chamando as mesmas Actions do Livewire |
| PDF | `spatie/laravel-pdf` (como no my-finance), com layout da invoice de exemplo |
| AI | `laravel/ai` (provedor remoto, ver Open Questions); IA on-device nos apps (Apple Foundation Models / Gemini Nano) |
| WhatsApp | **Evolution API** self-hosted (instâncias por conta, webhooks de status e mensagens) |
| Push | **Firebase Cloud Messaging (FCM)** para iOS e Android, como canal `push` da central de notificações |
| i18n | `en` (padrão) + `pt_BR`, em JSON e PHP, como no my-finance |
| iOS app | Swift / SwiftUI, deployment target **iOS 27**, App Intents (Siri), Speech framework, Firebase Messaging; no **monorepo** (`apps/ios`); instalação direta |
| Android app | Kotlin / Jetpack Compose, **minSdk 34 (Android 14)**, App Actions, SpeechRecognizer, Firebase Messaging; no **monorepo** (`apps/android`); APK por instalação direta |
| Testing | Pest 5 (`pestphp/pest ^5.3`, `pest-plugin-laravel ^5.0`), Mockery |
| Quality | Laravel Pint, Larastan 3 (`phpstan analyse`), Laravel Boost (MCP) |
| Dev env | Laravel Sail (`laravel/sail ^1.53`) / `composer run dev` |
| Deploy | **Dokploy** via Docker, adaptado de um `.deploy` de projeto anterior (`Dockerfile`, `docker-compose.prod.yml` com serviços `web`, `horizon`, `reverb`, `scheduler`; `entrypoint.sh`; `php/prod.ini`) + Evolution API como serviço separado |

Dependências a adicionar (todas pedidas pelo desenvolvedor): `laravel/horizon`, `laravel/reverb`, `laravel/socialite`, `laravel/sanctum`, `laravel/ai`, `spatie/laravel-pdf`, `tallstackui/tallstackui`, além de um driver de push FCM (pacote a confirmar na Phase 21).

## Core Workflows

### 1. Convite e login

1. O super admin cadastra o email do familiar e escolhe os módulos iniciais. O sistema envia o convite por email (token com validade).
2. O convidado abre o link e cria a conta com senha **ou** entra com **Google**. O email do Google precisa ser igual ao convidado; se for diferente, o acesso é recusado.
3. O login posterior é feito com Google ou senha na web. Nos apps, o login (Google nativo ou senha) devolve um token Sanctum ligado ao dispositivo.
4. Um email sem convite que tenta entrar com Google é recusado e nenhuma conta é criada.

### 2. Gestão de módulos por usuário

1. Na tela de administração, o super admin vê a matriz usuário × módulo e liga ou desliga cada célula.
2. A mudança vale imediatamente. O middleware `module:<slug>` bloqueia as rotas web e API com 403, e o menu (web) e as abas (apps) refletem os módulos do usuário via `GET /api/v1/me`.
3. O super admin sempre tem todos os módulos.

### 3. Perfil e preferências

Copiado do my-finance:

1. O usuário edita nome, avatar e senha, e vê as contas sociais conectadas (Google).
2. Preferências: idioma (`en` / `pt_BR`) e fuso horário (identificador PHP, ex.: `America/Sao_Paulo`). O banco guarda em UTC e o fuso só afeta a exibição e o cálculo de recorrências.
3. Notificações: matriz tipo × canal (in-app, email, push, WhatsApp) com opt-in/opt-out. Para o canal WhatsApp, o usuário escolhe a conta de origem e o número de destino.
4. Dispositivos: lista dos devices com push registrado, com opção de revogar.

### 4. Motor de templates com variáveis e condições

1. Cada contexto (tipo de notificação, mensagem de WhatsApp, email de invoice) declara seu schema de variáveis. Exemplos para invoice: `current_month_name`, `current_year`, `invoice.number`, `invoice.date`, `invoice.due_date`, `invoice.amount`, `invoice.currency`, `payer.name`, `issuer.name`.
2. O editor mostra as variáveis disponíveis e valida a sintaxe `{% %}` ao salvar: tags não fechadas e variáveis desconhecidas dão erro de validação.
3. O preview renderiza o template com dados de exemplo no idioma e fuso do usuário.
4. No envio, o contexto é montado já formatado (datas e valores por extenso no idioma do destinatário) e renderizado. Corpo em HTML sanitizado; títulos e WhatsApp em texto puro.

### 5. Central de notificações

1. Um módulo dispara `NotificationCenter::dispatch(type, recipients, context)`.
2. O gate verifica se o tipo está ativo, se o canal está ativo, se o template existe no idioma (com fallback para `en`) e a preferência do usuário.
3. Cada canal × destinatário vira um job na fila `notifications` (3 tentativas, backoff 30s/120s):
   - `database`: grava a notificação e emite um evento Reverb para o sininho em tempo real;
   - `mail`;
   - `push`: FCM para todos os devices ativos do usuário;
   - `whatsapp`: via o WhatsApp service.
4. O super admin edita título, corpo e ações de cada tipo × canal × idioma, e liga ou desliga tipos e canais.

### 6. Cadastro de payers e issuers

1. CRUD de **payers**: nome e endereço multilinha.
2. CRUD de **issuers**: nome/razão social, email, CEP, rua e número, bairro, cidade/UF.
3. Ambos ficam compartilhados no household (visíveis a quem tem o módulo `invoices`).

### 7. Configurar recorrência de invoice

1. O usuário cria uma recorrência e preenche:
   - payer e issuer;
   - destinatários (1+ emails);
   - assunto e corpo (template);
   - descrição do serviço;
   - moeda (ex.: USD → "American dollar") e valor;
   - regra de data de emissão e regra de vencimento;
   - recurrence (ex.: mensal, dia 1, 08:00, America/Sao_Paulo);
   - modo **automático** ou **requer revisão**.
2. O preview mostra o PDF e o email da próxima execução com as datas já calculadas.
3. A recorrência pode ser pausada ou retomada.

Exemplo da configuração atual do Paulo:

```text
payer:      Initech Corp
issuer:     Jane Doe Consulting Ltda
recurrence: monthly, day 1, 08:00 America/Sao_Paulo
date rule:  execution day            → 01/10/2026
due rule:   last day of issue month  → 31/10/2026
currency:   USD   amount: 2000.00
subject:    Invoice {% invoice.number %} — {% current_month_name %} {% current_year %}
mode:       automatic
```

### 8. Geração e envio de invoices

1. O scheduler encontra as recorrências vencidas e despacha um job na fila `invoices` para cada uma.
2. O job calcula as datas pela regra, reserva o próximo número global do mês (`YYYYMM-NNN`, com lock), gera o PDF e salva a invoice.
3. **Modo automático:** envia o email com o PDF anexo aos destinatários e marca como `sent`. **Modo revisão:** marca como `pending_review` e notifica o usuário (push/in-app). O usuário revisa, pode editar os campos e então aprova o envio.
4. **Envio manual:** a partir de uma recorrência ou de uma invoice avulsa, o usuário clica em "gerar e enviar agora" (ou "reenviar" numa invoice existente).
5. Uma falha de envio deixa a invoice como `failed`, com retry e notificação ao usuário. A mesma recorrência nunca gera duas invoices para o mesmo período (idempotência por recorrência × período).
6. Histórico: lista de invoices com download do PDF e status.

### 9. Conectar conta de WhatsApp

1. O usuário clica em "conectar número". O backend cria uma instância na Evolution API e mostra o QR code (atualizado em tempo real via Reverb).
2. O usuário escaneia no celular. O webhook da Evolution API confirma `open`, e a conta fica ativa e passa a sincronizar contatos e grupos.
3. Desconexões (`closed`) chegam por webhook, atualizam o status e notificam o usuário.
4. O usuário pode ter várias contas e remover qualquer uma. Os outros usuários não veem nem usam essas contas.

### 10. Mensagens de WhatsApp agendadas e recorrentes

1. O usuário cria uma mensagem e escolhe:
   - a conta de origem (uma das suas);
   - os targets (contatos e/ou grupos, 1+);
   - o corpo (template `{% %}`);
   - **envio único** (data/hora) ou **recorrente** (recurrence).
2. O scheduler despacha os envios vencidos para a fila `whatsapp`. Cada target vira um envio individual com status (`sent` / `failed`) e retry.
3. Recorrentes podem ser pausadas ou retomadas. Únicas podem ser canceladas antes do horário.
4. Também é possível enviar na hora ("enviar agora"). Outros módulos usam o mesmo WhatsApp service.

### 11. Lista de compras

1. CRUD de listas (ex.: "Compras da semana") e de itens (nome, quantidade/observação).
2. No mercado, cada usuário marca e desmarca itens, e a mudança aparece **em tempo real** para os outros (Reverb). Itens marcados vão para o fim da lista.
3. Ação "limpar marcados" e ação "arquivar lista".
4. Via voz, "adicione leite na lista de compras dessa semana" resolve a lista pelo nome (fuzzy) e adiciona o item.

### 12. Lembretes e tarefas

1. O usuário cria um lembrete/tarefa com título, nota opcional, data/hora opcional e recurrence opcional.
2. Na data/hora, o scheduler dispara a notificação "lembrete" por **push e WhatsApp** (respeitando as preferências).
3. O usuário marca como concluído pela web, pelo app ou por voz. Recorrente: gera a próxima ocorrência.
4. Listas de pendentes, concluídos e atrasados, compartilhadas no household.

### 13. Comandos de voz (apps)

1. O gatilho pode ser "Siri, Jarvis …" (App Intent, iOS), App Actions (Android) ou o botão de microfone no app.
2. O speech-to-text nativo produz o texto.
3. O intérprete roda no **modo local**, com a IA on-device do celular, ou no **modo remoto**, via `POST /api/v1/voice/commands` com `{ "text": "..." }`. No modo remoto, o `laravel/ai` usa um agente com tools que mapeiam para as Actions. Em ambos os modos, o resultado é a mesma chamada à Action correspondente.
4. Se houver ambiguidade (ex.: dois contatos "Ana"), o app pergunta antes de executar. **Envio de WhatsApp sempre pede confirmação** antes de enviar.
5. A resposta é falada e mostrada na tela.

```http
POST /api/v1/voice/commands
Authorization: Bearer <sanctum-token>

{ "text": "adicione leite na lista de compras dessa semana" }

200 OK
{ "action": "shopping.add_item", "status": "done",
  "message": "Added \"leite\" to \"Compras da semana\"." }
```

### 14. Deploy

1. Imagem Docker única adaptada de um `.deploy` de projeto anterior, com os serviços `web`, `horizon`, `reverb` e `scheduler` no `docker-compose.prod.yml`, publicados via Dokploy.
2. Serviços de apoio: PostgreSQL, Redis e Evolution API (com o próprio storage), na mesma rede do Dokploy.
3. Os webhooks da Evolution API apontam para `/api/v1/webhooks/evolution`, com segredo compartilhado.

## Decisions (closed in the interviews)

- **Provedor de IA remoto:** **configurável pelo super admin**. O provedor e a chave ficam cadastrados no admin, como o `AiProviderConnection` do my-finance, e o `laravel/ai` usa a conexão ativa.
- **Formato do número da invoice:** **`YYYYMM-NNN` sempre** (ex.: `202610-001`), aceitando a quebra em relação ao `202609` histórico.
- **Escopo dos apps no MVP:** **só uso diário**. Os apps têm login, notificações e push, lista de compras, lembretes, WhatsApp (enviar e agendar mensagens) e voz. Admin, invoices, conexão de conta WhatsApp (QR) e edição de templates de notificação ficam **só na web**.
- **Tipos de notificação do MVP:**
  - invoice enviada, invoice falhou e invoice aguardando revisão;
  - conta WhatsApp desconectada e envio WhatsApp falhou;
  - lembrete vencendo;
  - convite recebido (email) e módulo liberado.

- **Driver de push:** **só FCM** para iOS e Android. O FCM entrega no iOS via APNs, usando a chave APNs cadastrada no Firebase.
- **Distribuição dos apps:** **instalação direta** por enquanto. No iOS é via Xcode/ad hoc com a conta de desenvolvedor; no Android é via APK assinado. Lojas ficam fora do MVP.
- **Login Google na API:** a opção **mais simples**. O ID token do app é validado no endpoint `tokeninfo` do Google (`aud`, `iss`, `exp`), sem nova dependência.
- **Versões mínimas dos apps:** **iOS 27** (a mais recente) e **Android 14 (API 34)** como minSdk (compileSdk/targetSdk seguem em 37).
