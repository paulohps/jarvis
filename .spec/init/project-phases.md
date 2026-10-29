# Jarvis — Project Phases

<!-- inputs: project-description.md@sha256:560c01b59ac4 user-stories.md@sha256:3fc6ffb02e84 database-schema.md@sha256:b7460d90fc23 -->

## Overview

O build segue **foundation-first**. As cinco primeiras fases levantam a base:

1. infraestrutura e dependências;
2. todas as migrations e seeds de lookup;
3. models do núcleo;
4. models dos módulos, com **todos os relacionamentos e factories prontos**;
5. design system web padrão iOS 27.

Depois vêm os serviços transversais (motor de templates, central de notificações), a autenticação, o **deploy no Dokploy logo após a auth** (decisão do desenvolvedor), o controle de módulos, o perfil e o motor de recorrência. Os módulos entram na ordem decidida: **Invoices → WhatsApp → Lembretes → Lista de compras**. Em seguida vêm a **API v1 completa**, o backend de push, o **app iOS**, o **app Android** e, por último, **voz** (backend, iOS e Android).

São **28 fases**, cada uma (com suas sub-fases) dimensionada para **uma sessão de agente**. O **MVP completo** é entregue ao fim da **Phase 28**, porque o desenvolvedor decidiu web, API e apps juntos. O primeiro marco de valor real em produção é a **Phase 14**: a invoice mensal sai sozinha do servidor Dokploy. Não existe `.spec/init/design/`. Por decisão do desenvolvedor, toda tela segue o **design system iOS 27 interno criado na Phase 5**, e é esse o "Design ref" das tarefas de tela. As fases são referenciadas por número ao serem entregues aos agentes.

O codebase hoje é o starter kit Livewire em branco: só existem o `User` padrão, as migrations `users`/`cache`/`jobs` e os testes de exemplo. Nenhuma tarefa está concluída. As migrations padrão existem, mas precisam ser alteradas e por isso ficam `[ ]`.

**Conventions:**
- `[ ]` pending · `[x]` done in the codebase.
- Phases and sub-phases are numbered (`Phase 1`, `Phase 5.3`) for reference by AI agents.
- Business-logic tasks list the **feature tests** to generate; frontend-only tasks list validatable **acceptance criteria** and a **Design ref**.
- Toda regra de negócio fica em `app/Actions/<Module>/*`, chamada tanto por Livewire quanto pela API (US-12.3). Livewire em classes em `app/Livewire/<Module>/*`, seguindo o my-finance.
- Testes em Pest 5, rodando contra **PostgreSQL** (banco `testing`, como no my-finance), porque o schema usa índice único parcial.
- Todo texto visível passa por `__()`, com `en` padrão e `pt_BR` completo.
- Antes de cada fase, o agente lê `.ai/rules/index.md` (se existir), usa `search-docs` do Boost para as APIs do ecossistema e roda `vendor/bin/pint --dirty --format agent` ao fim.
- Design ref padrão das telas: **"Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`"**.

---

## Phase 1: Fundação — infraestrutura e dependências

**Goal:** instalar e configurar todos os pacotes e serviços da stack aprovada. · **Depends on:** none · **Covers:** Tech Stack, US-12.3, US-12.2 (i18n), US-12.4 (base)

### Phase 1.1: Pacotes e configuração

- [ ] **Task:** Instalar as dependências aprovadas na project description: `laravel/horizon`, `laravel/reverb`, `laravel/socialite`, `laravel/sanctum` (via `php artisan install:api`), `laravel/ai`, `spatie/laravel-pdf` e `tallstackui/tallstackui`.
  - **Acceptance criteria:**
    - `composer show --direct` lista os 7 pacotes. Os assets e configs publicados ficam versionados (`config/horizon.php`, `config/reverb.php`, `config/laravel-pdf.php`, `config/tallstackui.php`).
    - `routes/api.php` existe e está registrado em `bootstrap/app.php`.
    - `php artisan about` roda sem erro.
  - **Traces:** Tech Stack, US-12.3

- [ ] **Task:** Trocar a infraestrutura de dados para PostgreSQL + Redis, tanto em dev (Sail `compose.yaml`) quanto nos testes.
  - **Acceptance criteria:**
    - `compose.yaml` sobe `pgsql`, `redis`, `mailpit`, `gotenberg` (driver de PDF, como no my-finance) e `evolution-api`, com volume próprio e porta configurável.
    - `.env.example` usa `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, `BROADCAST_CONNECTION=reverb`, `CACHE_STORE=database`, `LARAVEL_PDF_DRIVER=gotenberg` e `GOTENBERG_URL`, além das chaves `EVOLUTION_API_URL`, `EVOLUTION_API_KEY`, `EVOLUTION_WEBHOOK_SECRET`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_IOS_CLIENT_ID`, `GOOGLE_ANDROID_CLIENT_ID`, `SUPER_ADMIN_EMAIL` e `SUPER_ADMIN_NAME`.
    - `phpunit.xml` aponta para PostgreSQL com banco `testing`, e `php artisan test --compact` passa.
    - `.github/workflows/tests.yml` sobe um serviço PostgreSQL e roda Pint, Larastan e Pest.
  - **Traces:** Tech Stack, US-12.4

- [ ] **Task:** Configurar o Horizon com as filas `default`, `notifications`, `invoices` e `whatsapp`, e restringir o painel ao super admin.
  - **Acceptance criteria:**
    - `config/horizon.php` define supervisors cobrindo as 4 filas nos ambientes `local` e `production`.
    - O gate `viewHorizon` permite apenas `users.is_super_admin = true`.
  - **Feature tests:**
    - `horizon dashboard is forbidden to non super admins` → usuário comum recebe 403 em `/horizon`.
    - `horizon dashboard is available to the super admin` → super admin recebe 200.
  - **Traces:** Tech Stack, US-12.4

- [ ] **Task:** Configurar o Reverb e o Laravel Echo no front (`resources/js/echo.js`).
  - **Acceptance criteria:**
    - `npm run build` compila com Echo + `pusher-js` configurados por `VITE_REVERB_*`.
    - `routes/channels.php` existe, com o canal privado `App.Models.User.{id}` autorizando somente o próprio usuário.
  - **Feature tests:**
    - `user private channel authorizes only its owner` → autenticar o canal de outro usuário retorna 403.
  - **Traces:** Tech Stack, US-5.1, US-8.2

### Phase 1.2: Convenções transversais

- [ ] **Task:** Criar a macro `Blueprint::friendlyId()` (`string(30)->unique()`) e o trait `HasFriendlyId` (gera o id no `creating` e usa `friendly_id` como route key), como no my-finance.
  - **Acceptance criteria:**
    - Um model com o trait recebe `friendly_id` único de 30 caracteres ou menos ao ser criado.
    - O route model binding resolve por `friendly_id`, e o `id` numérico não resolve.
  - **Feature tests:**
    - `friendly id is generated on create and is unique` → dois registros nunca compartilham `friendly_id`.
    - `route binding resolves by friendly id only` → URL com o `id` numérico retorna 404.
  - **Traces:** database-schema (Notes: friendly_id)

- [ ] **Task:** Registrar `Relation::enforceMorphMap` com os aliases `user`, `invoice_recurrence`, `whatsapp_message` e `reminder`.
  - **Acceptance criteria:**
    - `recurrences.schedulable_type`, `notifications.notifiable_type` e `personal_access_tokens.tokenable_type` gravam os aliases, nunca o FQCN.
    - Um model fora do map lança exceção ao virar morph.
  - **Feature tests:**
    - `morph map stores short aliases` → criar uma recorrência para uma invoice recurrence grava `invoice_recurrence`.
  - **Traces:** database-schema (Notes: morphs), US-12.1

- [ ] **Task:** Montar a base de localização: `lang/en.json`, `lang/pt_BR.json`, `lang/en/*`, `lang/pt_BR/*`, a classe `App\Support\Localization` (`SUPPORTED_LOCALES = ['en', 'pt_BR']`) e o middleware `SetLocale`. O middleware usa o locale do usuário autenticado; sem usuário, usa o header `Accept-Language` (API) ou o padrão `en`.
  - **Acceptance criteria:**
    - Usuário com `pt_BR` vê strings traduzidas. Visitante vê `en`.
    - `Accept-Language: pt-BR` em rota pública da API devolve mensagens de validação em português.
    - `Carbon` usa o locale ativo para nomes de mês.
  - **Feature tests:**
    - `authenticated user locale drives translations` → usuário `pt_BR` recebe a página com textos em pt_BR.
    - `api honours accept language when unauthenticated` → 422 com mensagem em pt_BR.
  - **Traces:** US-3.2, US-12.2

- [ ] **Task:** Definir o formato de erro e o versionamento da API: prefixo `/api/v1`, rate limiter `api` (60/min por usuário ou IP) e respostas JSON para 401/403/404/422.
  - **Acceptance criteria:**
    - Qualquer exceção em `/api/*` retorna JSON, nunca HTML.
    - 422 segue o formato padrão do Laravel (`message` + `errors`).
  - **Feature tests:**
    - `api errors are always json` → rota inexistente em `/api/v1` retorna 404 JSON.
  - **Traces:** US-12.3

---

## Phase 2: Fundação — migrations e seeds de lookup

**Goal:** criar todas as 49 tabelas do schema e semear os lookups. · **Depends on:** Phase 1 · **Covers:** database-schema inteiro

### Phase 2.1: Lookups e identidade

- [ ] **Task:** Criar a migration das 18 tabelas de lookup: `locales`, `modules`, `social_providers`, `device_platforms`, `ai_providers`, `notification_types`, `notification_channels`, `recurrence_frequencies`, `weekdays`, `delivery_statuses`, `currencies`, `invoice_statuses`, `invoice_delivery_modes`, `invoice_date_rules`, `invoice_due_date_rules`, `whatsapp_connection_statuses`, `whatsapp_target_types` e `whatsapp_delivery_sources`.
  - **Acceptance criteria:**
    - Colunas, uniques e defaults idênticos ao DBML: `slug` unique e flags extras (`is_available_on_mobile`, `requires_value`, `requires_base_url`, `symbol`, `decimal_places`, `is_visible_in_profile`).
    - `weekdays.id` aceita ids fixos de 1 a 7 (ISO).
    - `php artisan migrate:fresh` roda em PostgreSQL sem erro.
  - **Traces:** database-schema (Lookup tables)

- [ ] **Task:** Alterar a migration padrão de `users` e criar `module_user`, `invitations`, `invitation_module`, `social_accounts`, `personal_access_tokens` (Sanctum), `devices` e `ai_provider_connections`.
  - **Acceptance criteria:**
    - `users` ganha `friendly_id`, `password` nullable, `avatar_path`, `locale_id`, `timezone` (default `UTC`), `is_super_admin`, `whatsapp_notification_phone`, `deactivated_at`, `last_seen_at` e `deleted_at`. A FK `whatsapp_notification_account_id` é criada na migration de WhatsApp (Phase 2.3) por causa da dependência circular.
    - Uniques compostos: `module_user (module_id, user_id)`, `invitation_module (invitation_id, module_id)`, `social_accounts (social_provider_id, provider_user_id)` e `social_accounts (user_id, social_provider_id)`.
    - `invitations.token_hash` é unique, com índice em `email`.
    - `devices.push_token` é unique e nullable.
    - `ai_provider_connections` tem o índice único parcial `UNIQUE (is_active) WHERE is_active` (PostgreSQL).
  - **Feature tests:**
    - `only one ai provider connection can be active` → inserir a segunda ativa viola a constraint.
  - **Traces:** database-schema (Identity), US-1.1, US-1.5, US-2.3, US-3.4

- [ ] **Task:** Criar as tabelas da central de notificações (`notification_type_channels`, `notification_templates`, `notification_preferences`, `notifications`) e da recorrência (`recurrences`, `recurrence_weekday`).
  - **Acceptance criteria:**
    - Uniques: `(notification_type_id, notification_channel_id)`, `(notification_type_channel_id, locale_id)`, `(user_id, notification_type_id, notification_channel_id)`, `recurrences (schedulable_type, schedulable_id)` e `recurrence_weekday (recurrence_id, weekday_id)`.
    - `notifications.id` é uuid, com os dois índices compostos do DBML.
    - `recurrences` tem o índice `(is_paused, next_run_at)`.
  - **Traces:** database-schema (Notification center, Shared recurrence), US-5.3, US-12.1

### Phase 2.2: Tabelas dos módulos de invoices

- [ ] **Task:** Criar `payers`, `issuers`, `invoice_recurrences`, `invoice_recurrence_recipients`, `invoice_number_sequences`, `invoices`, `invoice_recipients` e `invoice_deliveries`.
  - **Acceptance criteria:**
    - Soft delete em `payers`, `issuers` e `invoice_recurrences`.
    - `amount decimal(12,2)`. Snapshot completo de payer e issuer em `invoices`.
    - Uniques: `invoices.number`, `invoices (number_year_month, number_sequence)`, `invoices (invoice_recurrence_id, period_key)`, `invoice_number_sequences.year_month`, `invoice_recurrence_recipients (invoice_recurrence_id, email)` e `invoice_recipients (invoice_id, email)`.
  - **Feature tests:**
    - `invoice recurrence cannot produce two invoices for the same period` → o segundo insert com o mesmo `(invoice_recurrence_id, period_key)` falha.
    - `manual invoices with null period do not collide` → duas invoices avulsas (`period_key` null) coexistem.
  - **Traces:** database-schema (Invoices), US-6.5, US-6.7

### Phase 2.3: Tabelas de WhatsApp, compras e lembretes

- [ ] **Task:** Criar `whatsapp_accounts`, `whatsapp_targets`, `whatsapp_messages`, `whatsapp_message_whatsapp_target` e `whatsapp_deliveries`, e adicionar a FK `users.whatsapp_notification_account_id`.
  - **Acceptance criteria:**
    - Uniques: `whatsapp_accounts.instance_name`, `whatsapp_targets (whatsapp_account_id, jid)`, o pivot `(whatsapp_message_id, whatsapp_target_id)` e `whatsapp_deliveries (whatsapp_message_id, whatsapp_target_id, scheduled_for)`.
    - Índice `(whatsapp_account_id, created_at)`.
    - Soft delete em `whatsapp_messages`.
    - A FK de `users` usa `nullOnDelete`.
  - **Traces:** database-schema (WhatsApp), US-7.1, US-7.4

- [ ] **Task:** Criar `shopping_lists`, `shopping_list_items`, `reminders` e `reminder_occurrences`.
  - **Acceptance criteria:**
    - Soft delete em `shopping_lists` e `reminders`.
    - Índices `(shopping_list_id, checked_at, position)` e `(completed_at, due_at)`.
    - Unique `reminder_occurrences (reminder_id, due_at)`.
  - **Traces:** database-schema (Shopping, Reminders), US-8.1, US-9.1

### Phase 2.4: Seeders

- [ ] **Task:** Criar seeders idempotentes (`updateOrCreate` por `slug`) para todos os lookups, com os valores de "Lookup Table Seeds": locales `en`/`pt_BR`; modules com `is_available_on_mobile`; currencies `USD`/`EUR`/`BRL` com símbolo; weekdays com ids 1–7; frequências `once`/`daily`/`weekly`/`monthly`/`custom_cron`; e as demais lookups do schema.
  - **Acceptance criteria:**
    - Rodar `db:seed` duas vezes não duplica linhas.
    - `ai_providers` é semeado com os drivers que o `laravel/ai` instalado suporta (conferir com `composer show laravel/ai` e `search-docs`).
    - `notification_types` **não** é semeado aqui: o provisionador da Phase 7 cria as linhas.
  - **Feature tests:**
    - `lookup seeders are idempotent` → contagens iguais após dois seeds.
    - `weekday ids follow iso numbering` → `weekdays` com id 1 é `monday` e com id 7 é `sunday`.
  - **Traces:** database-schema (Lookup Table Seeds)

- [ ] **Task:** Criar o `SuperAdminSeeder`: cria ou atualiza o super admin a partir de `SUPER_ADMIN_EMAIL` e `SUPER_ADMIN_NAME`, com `is_super_admin = true`, locale `en` e sem senha (entra via Google ou reset).
  - **Acceptance criteria:**
    - Sem as envs, o seeder é ignorado com aviso, sem erro.
    - Rodar duas vezes mantém um único usuário.
  - **Feature tests:**
    - `super admin seeder is idempotent and flags the user` → um usuário, `is_super_admin = true`.
  - **Traces:** US-1.1, US-2.1

---

## Phase 3: Fundação — models do núcleo

**Goal:** models de lookup, identidade, notificações e recorrência, com todos os relacionamentos. · **Depends on:** Phase 2 · **Covers:** database-schema (núcleo)

### Phase 3.1: Lookups e identidade

- [ ] **Task:** Criar o model base `Lookup`, com `idFor(slug)` em cache e `scopeActive`, e os 18 models de lookup (`Locale`, `Module`, `SocialProvider`, `DevicePlatform`, `AiProvider`, `NotificationType`, `NotificationChannel`, `RecurrenceFrequency`, `Weekday`, `DeliveryStatus`, `Currency`, `InvoiceStatus`, `InvoiceDeliveryMode`, `InvoiceDateRule`, `InvoiceDueDateRule`, `WhatsappConnectionStatus`, `WhatsappTargetType`, `WhatsappDeliverySource`), com constantes de slug (`InvoiceStatus::SENT`…).
  - **Acceptance criteria:**
    - Todo slug semeado tem uma constante tipada.
    - `idFor()` lança exceção para slug inexistente e fica em cache, invalidado no `saved`.
    - `Currency::labelFor('USD')` devolve "American dollar" traduzido (`pt_BR`: "Dólar americano").
  - **Feature tests:**
    - `lookup id for resolves seeded slugs and caches them` → a segunda chamada não consulta o banco.
    - `currency label is translated` → `pt_BR` devolve o nome em português.
  - **Traces:** database-schema (Lookup tables), US-6.3

- [ ] **Task:** Reescrever o model `User` com casts, `SoftDeletes`, `HasFriendlyId` e `HasApiTokens`. Relações: `locale`, `modules` (belongsToMany via `module_user`, `withPivot('enabled_by_id')`), `socialAccounts`, `devices`, `whatsappAccounts`, `whatsappNotificationAccount`, `notificationPreferences`, `invitationsSent`. Helpers: `hasModule(slug)` (true para super admin), `isDeactivated()` e `isSuperAdmin()`.
  - **Acceptance criteria:**
    - `password` hashed e nullable.
    - `avatar_path` vira URL via accessor `avatarUrl`.
    - `hasModule` retorna true para super admin sem linhas em `module_user`.
  - **Feature tests:**
    - `super admin has every module` → `hasModule('invoices')` é true sem pivot.
    - `regular user has only enabled modules` → true só para os módulos com linha no pivot.
  - **Traces:** US-2.1, US-2.2, US-3.1

- [ ] **Task:** Criar os models `Invitation` (com `belongsToMany` modules via `invitation_module`; `invitedBy`, `acceptedUser`, `locale`; accessor `status` derivado de `accepted_at`/`revoked_at`/`expires_at`), `SocialAccount`, `Device` (`user`, `platform`, `personalAccessToken`) e `AiProviderConnection` (`api_key` com cast `encrypted`, `provider`, `createdBy`).
  - **Acceptance criteria:**
    - `Invitation::status` retorna `pending`, `accepted`, `expired` ou `revoked`.
    - `api_key` nunca aparece em `toArray()`.
  - **Feature tests:**
    - `invitation status is derived from timestamps` → os 4 estados.
    - `ai provider api key is encrypted at rest` → o valor cru no banco difere do texto.
  - **Traces:** US-1.1, US-2.3, US-3.4

### Phase 3.2: Notificações e recorrência

- [ ] **Task:** Criar os models da central: `NotificationType` e `NotificationChannel` (lookups com `typeChannels`), `NotificationTypeChannel` (`type`, `channel`, `templates`), `NotificationTemplate` (`typeChannel`, `locale`, `updatedBy`, cast `actions` array), `NotificationPreference` (`user`, `type`, `channel`) e o model `Notification` estendendo `DatabaseNotification`, com `notificationType`.
  - **Acceptance criteria:**
    - Todas as relações navegáveis nos dois sentidos.
    - Observer invalida o cache de configuração de notificações ao salvar (como `NotificationSettingsObserver` do my-finance).
  - **Traces:** database-schema (Notification center), US-5.5

- [ ] **Task:** Criar o model `Recurrence` (`schedulable` morphTo, `frequency`, `weekdays` belongsToMany via `recurrence_weekday`; casts de `time_of_day`, `starts_at`, `ends_at`, `next_run_at`, `last_run_at`, `is_paused`), o trait `HasRecurrence` (morphOne `recurrence`) e a interface `Schedulable` (`runScheduledSlot(CarbonImmutable $slot): void`, `ownerTimezone(): string`).
  - **Acceptance criteria:**
    - Um dono soft-deleted não é retornado por `Recurrence::due()`.
    - O scope `due(now)` filtra `is_paused = false` e `next_run_at <= now`.
  - **Feature tests:**
    - `due scope skips paused and future recurrences` → só as vencidas e ativas.
    - `due scope skips soft deleted owners` → o dono com `deleted_at` é ignorado.
  - **Traces:** US-12.1

- [ ] **Task:** Criar as factories do núcleo: `UserFactory` (states `superAdmin`, `deactivated`, `withModules([...])`, `googleOnly`), `InvitationFactory` (states `expired`, `accepted`, `revoked`), `SocialAccountFactory`, `DeviceFactory` (`ios`, `android`, `withPushToken`), `AiProviderConnectionFactory` (`active`), `NotificationTemplateFactory`, `NotificationPreferenceFactory` e `RecurrenceFactory` (`once`, `daily`, `weekly`, `monthly`, `lastDayOfMonth`, `cron`, `paused`).
  - **Acceptance criteria:**
    - Cada factory cria um registro válido sem argumentos.
    - Os states produzem exatamente o estado nomeado.
  - **Feature tests:**
    - `core factories create valid records` → cada factory persiste sem violar constraints.
  - **Traces:** database-schema (núcleo)

---

## Phase 4: Fundação — models dos módulos

**Goal:** models de invoices, WhatsApp, compras e lembretes, com relações e factories. · **Depends on:** Phase 3 · **Covers:** database-schema (módulos)

### Phase 4.1: Invoices

- [ ] **Task:** Criar `Payer` e `Issuer` (`SoftDeletes`, `HasFriendlyId`, `createdBy`, `invoiceRecurrences`, `invoices`), `InvoiceRecurrence` (`SoftDeletes`, `HasRecurrence`, implementa `Schedulable`; relações `payer` com trashed, `issuer`, `currency`, `dateRule`, `dueDateRule`, `deliveryMode`, `recipients`, `invoices`, `createdBy`) e `InvoiceRecurrenceRecipient`.
  - **Acceptance criteria:**
    - `amount` com cast `decimal:2`.
    - `runScheduledSlot` existe como stub, implementado na Phase 14.
  - **Traces:** database-schema (`payers`, `issuers`, `invoice_recurrences`, `invoice_recurrence_recipients`)

- [ ] **Task:** Criar `Invoice` (`HasFriendlyId`; relações `recurrence`, `status`, `currency`, `payer`/`issuer` withTrashed, `recipients`, `deliveries`, `approvedBy`, `createdBy`; casts de datas), `InvoiceRecipient`, `InvoiceDelivery` (`invoice`, `status`, `triggeredBy`) e `InvoiceNumberSequence`.
  - **Acceptance criteria:**
    - `issue_date`/`due_date` com cast `date`.
    - Helpers `isSent()`, `isPendingReview()` e `isCancelled()`.
  - **Traces:** database-schema (`invoices`, `invoice_recipients`, `invoice_deliveries`, `invoice_number_sequences`)

### Phase 4.2: WhatsApp, compras e lembretes

- [ ] **Task:** Criar `WhatsappAccount` (`user`, `status`, `targets`, `messages`, `deliveries`; helper `isOpen()`), `WhatsappTarget` (`account`, `type`, `messages` belongsToMany via `whatsapp_message_whatsapp_target`), `WhatsappMessage` (`SoftDeletes`, `HasRecurrence`, `Schedulable`; `user`, `account`, `targets`, `deliveries`) e `WhatsappDelivery` (`account`, `target`, `message`, `source`, `status`, `triggeredBy`).
  - **Acceptance criteria:**
    - Todas as relações do DBML navegáveis.
    - `WhatsappMessage::isOnce()` deriva de `recurrence.frequency = once`.
  - **Traces:** database-schema (`whatsapp_accounts`, `whatsapp_targets`, `whatsapp_messages`, `whatsapp_message_whatsapp_target`, `whatsapp_deliveries`)

- [ ] **Task:** Criar `ShoppingList` (`SoftDeletes`, `HasFriendlyId`, `items` ordenados por `checked_at` nulls first e depois `position`, `createdBy`, scope `notArchived`), `ShoppingListItem` (`list`, `checkedBy`, `createdBy`), `Reminder` (`SoftDeletes`, `HasRecurrence`, `Schedulable`, `occurrences`, `createdBy`) e `ReminderOccurrence` (`reminder`, `completedBy`; scopes `pending`, `overdue`, `completed`).
  - **Acceptance criteria:**
    - O scope `overdue` usa `due_at < now()` e `completed_at` null.
    - `pending` inclui as ocorrências sem data.
  - **Feature tests:**
    - `reminder occurrence scopes classify pending overdue and completed` → cada ocorrência em exatamente um filtro.
    - `shopping list items order checked last` → itens marcados vêm depois dos não marcados.
  - **Traces:** database-schema (`shopping_lists`, `shopping_list_items`, `reminders`, `reminder_occurrences`), US-8.2, US-9.1

- [ ] **Task:** Criar as factories dos módulos com states: `PayerFactory`, `IssuerFactory`, `InvoiceRecurrenceFactory` (`automatic`, `requiresReview`, `withRecipients(n)`), `InvoiceFactory` (`pendingReview`, `sent`, `failed`, `cancelled`, `oneOff`), `InvoiceDeliveryFactory`, `WhatsappAccountFactory` (`open`, `closed`, `connecting`), `WhatsappTargetFactory` (`contact`, `group`, `manual`), `WhatsappMessageFactory` (`once`, `recurring`, `cancelled`), `WhatsappDeliveryFactory` (`sent`, `failed`), `ShoppingListFactory` (`archived`), `ShoppingListItemFactory` (`checked`), `ReminderFactory` (`dated`, `undated`, `recurring`) e `ReminderOccurrenceFactory` (`completed`, `overdue`).
  - **Acceptance criteria:**
    - Cada factory persiste sem violar constraints.
    - Os states de dono recorrente criam a `recurrences` associada.
  - **Feature tests:**
    - `module factories create valid records` → cada factory persiste.
    - `relationships resolve for every module model` → cada relação declarada retorna o tipo esperado.
  - **Traces:** database-schema (módulos)

---

## Phase 5: Fundação — design system web iOS 27

**Goal:** layout, tokens e componentes reutilizáveis no padrão iOS 27 (Liquid Glass refinado). · **Depends on:** Phase 1 · **Covers:** US-12.2, US-11.1 (referência visual)

### Phase 5.1: Tokens e TallStackUI

- [ ] **Task:** Definir os tokens de tema no `resources/css/app.css` (Tailwind 4 `@theme`):
  - cores semânticas de sistema iOS (label, secondary label, separator, system background, grouped background, tint azul) em claro e escuro;
  - materiais de vidro com menos transparência (`backdrop-blur` + fundo ~80% opaco);
  - raios contínuos (10/14/22px);
  - fonte do sistema (`-apple-system, BlinkMacSystemFont, "SF Pro", system-ui`);
  - sombras suaves.
  - **Acceptance criteria:**
    - O modo escuro segue `prefers-color-scheme`.
    - Contraste de texto ≥ 4.5:1 nos dois temas.
    - Nenhuma cor hardcoded fora dos tokens.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-12.2

- [ ] **Task:** Personalizar o TallStackUI (`TallStackUi::personalize()` / config) para botões, inputs, select, toggle, modal, slide-over, toast, badge, card e table no visual iOS 27.
  - **Acceptance criteria:**
    - Os componentes TallStackUI renderizam com os tokens da Phase 5.1.
    - Os toggles têm formato de switch iOS.
    - O modal abre como sheet a partir da base em telas menores que 640px.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-12.2

### Phase 5.2: Layouts e componentes compartilhados

- [ ] **Task:** Criar o layout `app-shell`:
  - sidebar translúcida no desktop (≥ 1024px) e tab bar inferior no mobile (menos de 1024px);
  - top bar com título grande estilo iOS;
  - slot para o sininho de notificações (preenchido na Phase 7);
  - menu montado a partir dos módulos do usuário (`hasModule`) e item "Admin" só para super admin.
  - **Acceptance criteria:**
    - Em 360px de largura não há scroll horizontal.
    - Um módulo sem acesso não aparece no menu.
    - O link ativo é destacado.
    - Skip-link de acessibilidade.
  - **Feature tests:**
    - `navigation shows only enabled modules` → usuário com `shopping` vê só esse item de módulo.
    - `admin entry is visible only to super admin`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-12.2, US-2.1

- [ ] **Task:** Criar o layout `guest` (auth card centralizado, com marca "Jarvis", troca de idioma e fundo com material de vidro) e as páginas de erro 403/404/419/500 no mesmo estilo.
  - **Acceptance criteria:**
    - As páginas de erro aparecem traduzidas em `en` e `pt_BR`.
    - O 403 de módulo mostra "You don't have access to this module".
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-12.2, US-2.1

- [ ] **Task:** Criar os componentes Blade compartilhados em `resources/views/components/ui/`: `page-header`, `inset-grouped-list` e `list-row` (lista agrupada iOS), `segmented-control`, `empty-state`, `settings-section`, `localized-date` (fuso do usuário), `money` (moeda + `2,000.00`), `confirm-dialog`, `status-badge` e `search-field`.
  - **Acceptance criteria:**
    - Cada componente aceita slots e props documentados no PHPDoc do componente.
    - `localized-date` converte de UTC para o fuso do usuário.
    - `money` formata com 2 casas e separador de milhar.
  - **Feature tests:**
    - `localized date renders in the user timezone` → `2026-10-01 11:00 UTC` vira `08:00` em `America/Sao_Paulo`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-12.2, US-3.2

- [ ] **Task:** Criar a página de dashboard (`/`, autenticada) com cards dos módulos ligados (ícone, nome e atalho) e um estado vazio quando o usuário não tem módulos.
  - **Acceptance criteria:**
    - Usuário sem módulos vê o empty state "Ask the admin to enable a module".
    - Visitante é redirecionado ao login.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-2.1, US-12.2

---

## Phase 6: Motor de templates `{% %}`

**Goal:** portar o motor de templates do my-finance como serviço transversal, com schemas por contexto, validação, editor e preview. · **Depends on:** Phase 5 · **Covers:** US-4.1, US-4.2, Workflow 4

### Phase 6.1: Motor

- [ ] **Task:** Portar de `../my-finance/app/Services/Notifications/Template/*` para `app/Services/Templates/*`: `TemplateLexer`, `TemplateParser`, os nós (`TextNode`, `VariableNode`, `IfNode`, `ForNode`), `TemplateRenderer` (`renderHtml`/`renderText`, `MAX_LOOP_ITERATIONS = 500`), `TemplateValidator`, `HtmlSanitizer`, `TemplateError` e `TemplateSyntaxException`.
  - **Acceptance criteria:**
    - Suporta `{% var %}`, `{% a.b %}`, `{% if x %}…{% else %}…{% endif %}`, `{% if a.b == "v" %}`, `!=` e `{% for i in items %}…{% endfor %}`.
    - `{{ }}` sai literal.
    - Valores são escapados. O HTML do corpo é sanitizado: sem `<script>`, sem `on*` e sem `javascript:`.
  - **Feature tests:**
    - `renders variables and dotted paths`.
    - `renders if else and comparisons` → `==` e `!=` com string entre aspas.
    - `for loops are capped at 500 iterations` → um contexto com 600 itens renderiza 500.
    - `double braces are literal text`.
    - `interpolated values are html escaped`.
    - `body html is sanitized` → `<script>` e `onclick` removidos.
    - `unclosed tag raises syntax error with position`.
  - **Traces:** US-4.1, US-4.2

- [ ] **Task:** Criar o contrato `TemplateSchema` (variáveis disponíveis, com tipo e exemplo) e a classe `TemplateContextBuilder`, que monta as variáveis comuns no idioma e fuso do destinatário: `current_month_name`, `current_year`, `current_date` e `next_month_name`.
  - **Acceptance criteria:**
    - Datas e valores chegam ao renderer já formatados.
    - `current_month_name` em `pt_BR` é "outubro" e em `en` é "October".
  - **Feature tests:**
    - `common variables are localized` → o mesmo instante renderiza o mês no idioma do destinatário.
    - `common variables use the recipient timezone` → `2026-11-01 01:00 UTC` em `America/Sao_Paulo` ainda é outubro.
  - **Traces:** US-4.1, US-3.2

- [ ] **Task:** Criar a regra de validação `ValidTemplate(TemplateSchema $schema)`, usada pelas Actions ao salvar templates.
  - **Acceptance criteria:**
    - Tag não fechada → erro com linha e coluna.
    - Variável fora do schema → erro "Unknown variable `x`".
    - `for` sobre algo que não é coleção → erro.
  - **Feature tests:**
    - `template with unknown variable fails validation`.
    - `template with unclosed tag fails validation`.
    - `valid template passes validation`.
  - **Traces:** US-4.1

### Phase 6.2: Editor e preview

- [ ] **Task:** Criar o componente `x-ui.template-editor`, com textarea, painel de variáveis do schema (clicar insere `{% var %}` na posição do cursor via Alpine) e exibição dos erros de validação.
  - **Acceptance criteria:**
    - Clicar numa variável insere a tag no cursor.
    - Erros de validação aparecem abaixo do campo com a posição.
    - Funciona com `wire:model`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-4.1

- [ ] **Task:** Criar a Action `PreviewTemplate(schema, template, user)`, que renderiza com os dados de exemplo do schema no idioma e fuso do usuário, e o componente `x-ui.template-preview` (HTML sanitizado ou texto puro, conforme o contexto).
  - **Acceptance criteria:**
    - O preview de título e de WhatsApp sai em texto puro; o de corpo de email sai em HTML sanitizado.
    - Template inválido mostra o erro em vez do preview.
  - **Feature tests:**
    - `preview renders sample data in the user locale`.
    - `preview of plain text contexts strips html`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-4.2

---

## Phase 7: Central de notificações (núcleo, in-app e email)

**Goal:** portar a central do my-finance com os canais `database` (Reverb) e `mail`, o admin de templates e os tipos de conta. · **Depends on:** Phases 3, 6 · **Covers:** US-5.1, US-5.3, US-5.4, US-5.5, Workflow 5

### Phase 7.1: Núcleo

- [ ] **Task:** Portar de `../my-finance/app/Services/Notifications/*` (sem o conceito de workspace/audiences): `CenterNotification` (`ShouldQueue`, fila `notifications`, `#[Tries(3)]`, `#[Backoff(30, 120)]`), `NotificationCenter::dispatch()`, `NotificationRegistry`, `NotificationProvisioner`, `NotificationGate`, `ChannelDefaults`, `ChannelSettings`, `TemplateSettings`, `TypeSettings`, `RenderedNotification` e `ChannelDrivers`.
  - **Acceptance criteria:**
    - Cada canal × destinatário vira um job próprio.
    - O gate checa tipo ativo, canal ativo na plataforma, canal ativo no tipo, template no idioma (com fallback `en`) e preferência do usuário (ou default do tipo × canal), no despacho e de novo no worker.
    - Um tipo de módulo só vai para usuários com o módulo (`hasModule`).
  - **Feature tests:**
    - `each channel is queued as its own job on the notifications queue`.
    - `failing mail does not block database channel`.
    - `gate blocks inactive type`.
    - `gate blocks channel disabled on platform`.
    - `gate respects user opt out`.
    - `gate falls back to english template`.
    - `module types skip users without the module`.
  - **Traces:** US-5.3, US-5.4

- [ ] **Task:** Criar o comando `notifications:provision` (roda no deploy e em `db:seed`). Ele cria as linhas de `notification_types`, `notification_type_channels` e `notification_templates` em `en` e `pt_BR` a partir das classes registradas, sem sobrescrever template editado.
  - **Acceptance criteria:**
    - É idempotente.
    - Um template com `updated_by_id` preenchido nunca é sobrescrito pelos defaults.
  - **Feature tests:**
    - `provisioning creates types channels and templates in both locales`.
    - `provisioning never overwrites edited templates`.
  - **Traces:** US-5.4, US-5.5

- [ ] **Task:** Criar os tipos de conta com defaults `en`/`pt_BR`: `invitation` (canal `mail` apenas, com link de aceite) e `module_enabled` (`database` + `mail`, com nome do módulo e link). Os tipos de módulo são criados nas Phases 14, 15, 16 e 17.
  - **Acceptance criteria:**
    - `invitation` funciona para um destinatário sem conta (on-demand mail notifiable).
    - O schema de variáveis de cada tipo está declarado.
  - **Feature tests:**
    - `invitation notification is mailed to a non user address`.
    - `module enabled notification renders module name in recipient locale`.
  - **Traces:** US-5.4, US-1.1, US-2.1

### Phase 7.2: In-app em tempo real e admin

- [ ] **Task:** Criar o canal `database` com broadcast: ao gravar, emitir um evento Reverb no canal privado do usuário. Criar a Action `MarkNotificationsRead` (uma ou todas) e o `NotificationFeed` paginado.
  - **Acceptance criteria:**
    - O evento leva `id`, título renderizado e contagem de não lidas.
    - Marcar como lida altera só notificações do próprio usuário.
  - **Feature tests:**
    - `database notification broadcasts to the owner private channel` (`Event::fake`/`Broadcast` assert).
    - `user cannot mark another users notification as read` → 404.
    - `mark all as read clears unread count`.
  - **Traces:** US-5.1

- [ ] **Task:** Criar o componente Livewire `Notifications\Bell` no `app-shell` (contador, dropdown/sheet com as últimas 10, "marcar todas como lidas", ações levando à tela) com listener Echo, e a página `NotificationHistory` paginada.
  - **Acceptance criteria:**
    - Uma nova notificação incrementa o contador sem refresh (Echo).
    - A ação da notificação navega para o link do tipo.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-5.1

- [ ] **Task:** Criar o admin de notificações (super admin):
  - listar tipos e ligar/desligar tipo (`UpdateNotificationType`);
  - ligar/desligar canal na plataforma e no tipo (`UpdateNotificationTypeChannel`);
  - editar título/corpo/ações por tipo × canal × idioma com `x-ui.template-editor` e preview (`SaveNotificationTemplates`, grava `updated_by_id`).
  - **Acceptance criteria:**
    - Apenas super admin acessa (os demais recebem 403).
    - Template inválido não salva.
    - Desligar um canal na plataforma o oculta das preferências de todos.
  - **Feature tests:**
    - `non super admin cannot manage notification templates`.
    - `saving template records last editor`.
    - `invalid template is rejected`.
    - `disabled type stops dispatching`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-5.5

---

## Phase 8: Autenticação web — convites, senha e Google

**Goal:** acesso só por convite, com login por senha ou Google. · **Depends on:** Phases 5, 7 · **Covers:** US-1.1, US-1.2, US-1.3, US-1.4, Workflow 1

### Phase 8.1: Login e senha

- [ ] **Task:** Criar as Actions `AuthenticateUser` e `LogoutUser` e as telas Livewire de login, sem cadastro público. Rate limit de 5 tentativas por minuto por email + IP. Usuário desativado ou soft-deleted não entra.
  - **Acceptance criteria:**
    - Credenciais inválidas mostram uma mensagem genérica.
    - A 6ª tentativa no minuto é bloqueada.
    - O logout invalida a sessão e regenera o token CSRF.
    - Não existe rota `/register`.
  - **Feature tests:**
    - `user logs in with valid credentials`.
    - `invalid credentials show generic error without revealing email existence`.
    - `login is throttled after five attempts per minute`.
    - `deactivated user cannot log in`.
    - `there is no public registration route` → `/register` retorna 404.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-1.4, US-2.2

- [ ] **Task:** Criar a recuperação de senha (`SendPasswordResetLink`, `ResetPassword`) com o password broker, link válido por 60 minutos e uso único.
  - **Acceptance criteria:**
    - O pedido para um email inexistente responde igual ao de um existente.
    - O token expira em 60 min e é invalidado após o uso.
  - **Feature tests:**
    - `reset link response is identical for unknown emails`.
    - `reset token expires after sixty minutes`.
    - `reset token cannot be reused`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-1.4

### Phase 8.2: Convites

- [ ] **Task:** Criar as Actions `InviteUser` (email + módulos + locale; token aleatório com hash sha256 em `token_hash`; `expires_at` = agora + 7 dias; dispara o tipo `invitation`), `ResendInvitation` (novo token, invalida o anterior) e `RevokeInvitation`.
  - **Acceptance criteria:**
    - Email com conta existente → erro de validação "user already exists".
    - O reenvio troca o hash, e o link antigo passa a falhar.
    - Só o super admin executa (policy).
  - **Feature tests:**
    - `super admin invites a family member with modules`.
    - `inviting an existing user fails validation`.
    - `resending invalidates the previous token`.
    - `revoked invitation cannot be accepted`.
    - `non super admin cannot invite`.
  - **Traces:** US-1.1

- [ ] **Task:** Criar a tela de admin de convites, com formulário (email, locale, módulos) e lista com status `pending`/`accepted`/`expired`/`revoked`, reenviar e revogar.
  - **Acceptance criteria:**
    - O status vem do accessor derivado.
    - As ações só aparecem para convites `pending`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-1.1

- [ ] **Task:** Criar a Action `AcceptInvitation` (nome + senha ≥ 8 confirmada; cria o usuário com o locale do convite, liga os módulos em `module_user`, marca `accepted_at`/`accepted_user_id` e autentica) e a tela de aceite com o email bloqueado.
  - **Acceptance criteria:**
    - Token expirado, revogado ou usado mostra a página de erro, sem criar conta.
    - Os módulos do convite são ligados exatamente.
  - **Feature tests:**
    - `accepting creates user with invited modules and logs in`.
    - `expired invitation cannot be accepted`.
    - `used invitation cannot be accepted twice`.
    - `password must have at least eight characters and be confirmed`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-1.2

### Phase 8.3: Google

- [ ] **Task:** Criar o login Google web com Socialite (Action `AuthenticateWithGoogle`). Ordem de resolução: (1) conta social já vinculada → login; (2) usuário com o mesmo email → vincula `social_accounts` e loga; (3) convite `pending` válido para o email → cria a conta com os módulos e aceita o convite; (4) nenhum dos casos → recusa "invitation required". No fluxo de aceite iniciado pelo link, um email do Google diferente do convidado é recusado.
  - **Acceptance criteria:**
    - Nenhuma conta é criada sem convite.
    - `social_accounts.last_used_at` é atualizado.
    - Usuário desativado é recusado.
  - **Feature tests:**
    - `google login links existing user on first use`.
    - `google login with pending invitation creates the account`.
    - `google login without account or invitation is refused`.
    - `google email different from invitation is refused during acceptance`.
    - `deactivated user cannot log in with google`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-1.3

---

## Phase 9: Deploy no Dokploy

**Goal:** produção no ar cedo, com web, Horizon, Reverb, scheduler, Gotenberg e Evolution API. · **Depends on:** Phase 8 · **Covers:** US-12.4, Workflow 14

### Phase 9.1: Imagem e compose

- [ ] **Task:** Adaptar o `Dockerfile` de um projeto anterior, `entrypoint.sh` e `php/prod.ini` para `.deploy/` no Jarvis, trocando `pdo_mysql` por `pdo_pgsql` e `pgsql` e incluindo `redis`, `pcntl` (Horizon) e `intl` (Carbon/locale).
  - **Acceptance criteria:**
    - `docker build -f .deploy/Dockerfile .` gera a imagem.
    - O `entrypoint` do papel `web` roda `migrate --force`, `notifications:provision`, `db:seed --class=LookupSeeder --force`, `config:cache`, `route:cache`, `view:cache` e `event:cache`.
  - **Traces:** US-12.4

- [ ] **Task:** Criar o `.deploy/docker-compose.prod.yml` (Dokploy tipo Compose, rede `dokploy-network`), com os serviços `web`, `horizon`, `reverb`, `scheduler` (`schedule:work` ou `schedule:run` por minuto), `gotenberg` e `evolution-api` (volume persistente). PostgreSQL e Redis são serviços separados do Dokploy.
  - **Acceptance criteria:**
    - Todos os serviços usam a mesma imagem (exceto `gotenberg` e `evolution-api`).
    - O Reverb fica exposto por WebSocket com TLS no domínio do app.
    - O comentário do arquivo documenta Compose Path, envs e serviços externos, como no projeto de origem.
  - **Traces:** US-12.4

- [ ] **Task:** Garantir o healthcheck `/up` e documentar no compose as envs obrigatórias de produção (`APP_KEY`, `DB_*`, `REDIS_*`, `REVERB_*`, `MAIL_*`, `GOOGLE_*`, `EVOLUTION_*`, `GOTENBERG_URL`, `SUPER_ADMIN_*`).
  - **Acceptance criteria:**
    - `/up` retorna 200 com o DB acessível.
    - Os containers `horizon` e `scheduler` reiniciam sozinhos em falha (`restart: unless-stopped`).
  - **Feature tests:**
    - `health endpoint responds ok`.
  - **Traces:** US-12.4

---

## Phase 10: Controle de módulos e administração

**Goal:** acesso binário por módulo, gestão de usuários e provedor de IA. · **Depends on:** Phases 7, 8 · **Covers:** US-2.1, US-2.2, US-2.3, Workflow 2

### Phase 10.1: Módulos

- [ ] **Task:** Criar o middleware `EnsureModuleAccess` (alias `module:<slug>`), aplicado a rotas web e API, e o gate `access-module`.
  - **Acceptance criteria:**
    - Módulo desligado → 403 na web (página de erro) e 403 JSON na API.
    - Super admin sempre passa.
    - Vale na requisição seguinte à mudança, sem cache stale.
  - **Feature tests:**
    - `web module route is forbidden without the module`.
    - `api module route is forbidden without the module`.
    - `super admin passes every module middleware`.
    - `revoking a module takes effect on the next request`.
  - **Traces:** US-2.1, US-12.3

- [ ] **Task:** Criar as Actions `EnableModuleForUser` (cria a linha `module_user` com `enabled_by_id` e dispara `module_enabled`) e `DisableModuleForUser` (remove a linha, sem apagar dados), e a matriz Livewire usuário × módulo no admin (toggle por célula, sem o super admin).
  - **Acceptance criteria:**
    - O toggle persiste na hora.
    - Religar devolve o acesso aos mesmos dados.
    - O `module_enabled` é enviado só ao ligar, não ao desligar.
  - **Feature tests:**
    - `enabling a module notifies the user`.
    - `disabling a module keeps module data`.
    - `only super admin can toggle modules`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-2.1

### Phase 10.2: Usuários e IA

- [ ] **Task:** Criar as Actions `DeactivateUser` e `ReactivateUser` e a lista de usuários no admin (nome, email, último acesso, módulos). Desativar marca `deactivated_at`, revoga todos os `personal_access_tokens`, encerra as sessões e emite o evento `UserDeactivated` (consumido na Phase 16 para pausar as recorrências de WhatsApp). O super admin não pode se desativar.
  - **Acceptance criteria:**
    - O usuário desativado perde a sessão web e os tokens de API na hora.
    - O middleware `last_seen_at` atualiza no máximo 1 vez por minuto.
  - **Feature tests:**
    - `deactivating revokes all api tokens`.
    - `deactivated user session is terminated`.
    - `super admin cannot deactivate self`.
    - `reactivated user can log in again`.
    - `user deactivated event is dispatched`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-2.2

- [ ] **Task:** Criar as Actions `SaveAiProviderConnection`, `ActivateAiProviderConnection` (desativa as outras na mesma transação) e `TestAiProviderConnection` (chamada mínima via `laravel/ai`), e a tela de admin com a chave mascarada (só `api_key_last_four`).
  - **Acceptance criteria:**
    - A chave nunca volta inteira para a UI.
    - Só uma conexão ativa.
    - O teste mostra sucesso ou a mensagem de erro do provedor.
  - **Feature tests:**
    - `activating a connection deactivates the others`.
    - `api key is never exposed after saving`.
    - `test connection reports provider failure` (fake do `laravel/ai`).
    - `only super admin manages ai providers`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-2.3

---

## Phase 11: Perfil e preferências

**Goal:** perfil copiado do my-finance: dados, idioma, fuso, notificações e dispositivos. · **Depends on:** Phases 7, 8 · **Covers:** US-3.1, US-3.2, US-3.3, US-3.4, Workflow 3

### Phase 11.1: Perfil

- [ ] **Task:** Criar as Actions `UpdateProfile` (nome e avatar JPG/PNG/WebP de até 2MB em `avatar_path`, apagando o arquivo anterior) e `UpdatePassword` (exige a senha atual; usuário só com Google pode definir a primeira senha sem senha atual).
  - **Acceptance criteria:**
    - Avatar maior que 2MB ou de outro tipo → 422.
    - Senha atual errada → 422.
  - **Feature tests:**
    - `avatar must be an image up to two megabytes`.
    - `changing password requires current password`.
    - `google only user can set a first password`.
  - **Traces:** US-3.1

- [ ] **Task:** Criar a Action `DisconnectSocialAccount` (só permite se houver senha definida) e a seção "Connected accounts".
  - **Acceptance criteria:**
    - Desvincular sem senha → erro "Set a password first".
  - **Feature tests:**
    - `cannot disconnect google without a password`.
    - `can disconnect google when a password exists`.
  - **Traces:** US-3.1

- [ ] **Task:** Criar a Action `UpdatePreferences`: locale ∈ {`en`, `pt_BR`} e timezone validado com `timezone:all`, gravado em `users`.
  - **Acceptance criteria:**
    - Valor inválido → 422.
    - A próxima requisição já usa o novo idioma.
  - **Feature tests:**
    - `locale must be en or pt_BR`.
    - `timezone must be a valid php identifier`.
    - `next request uses the new locale`.
  - **Traces:** US-3.2

- [ ] **Task:** Criar a página Livewire `ProfilePage` com abas Profile / Preferences / Notifications / Devices / Security, no padrão de lista agrupada iOS.
  - **Acceptance criteria:**
    - Cada aba carrega via Livewire sem recarregar a página.
    - Mensagens de sucesso via toast.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-3.1, US-3.2

### Phase 11.2: Notificações e dispositivos

- [ ] **Task:** Criar a Action `UpdateNotificationPreferences` e a matriz tipo × canal no perfil, mostrando só os tipos `is_visible_in_profile` e os canais ativos. O canal `whatsapp` só aparece habilitável quando o usuário tem `whatsapp_notification_account_id` e `whatsapp_notification_phone` (configurados na Phase 16).
  - **Acceptance criteria:**
    - Sem escolha explícita, a célula mostra o default do tipo × canal.
    - A mudança vale para o próximo despacho.
  - **Feature tests:**
    - `preferences matrix hides inactive channels`.
    - `opting out stops the next dispatch on that channel`.
    - `whatsapp channel is unavailable without account and phone`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-3.3

- [ ] **Task:** Criar a Action `RevokeDevice` (remove o `push_token` e revoga o `personal_access_token` associado) e a aba Devices (nome, plataforma, último uso).
  - **Acceptance criteria:**
    - Só os dispositivos do próprio usuário aparecem e podem ser revogados.
    - Após revogar, chamadas com aquele token retornam 401.
  - **Feature tests:**
    - `revoking a device revokes its api token`.
    - `user cannot revoke another users device`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-3.4

---

## Phase 12: Motor de recorrência compartilhado

**Goal:** um único cálculo de recorrência e um único scheduler para invoices, WhatsApp e lembretes. · **Depends on:** Phase 3 · **Covers:** US-12.1

### Phase 12.1: Cálculo e persistência

- [ ] **Task:** Criar o serviço `RecurrenceCalculator::nextRunAfter(Recurrence, CarbonImmutable $after)`, que calcula no fuso do dono e devolve em UTC. Regras por preset:
  - `once`: `starts_at` se ainda for futuro, senão null;
  - `daily`: o próximo `time_of_day`;
  - `weekly`: o próximo dia marcado em `recurrence_weekday`;
  - `monthly`: o dia N, limitado ao último dia do mês, ou `is_last_day_of_month`;
  - `custom_cron`: via `dragonmantank/cron-expression`, que já é dependência do Laravel.

  Todos respeitam `ends_at`.
  - **Acceptance criteria:**
    - Lida com DST.
    - O dia 31 em fevereiro cai no dia 28 ou 29.
    - Depois de `ends_at`, devolve null.
  - **Feature tests:**
    - `daily next run respects owner timezone`.
    - `weekly picks next selected weekday`.
    - `monthly day 31 clamps to last day of shorter months`.
    - `monthly last day flag picks last day`.
    - `once returns null after its run`.
    - `cron expression is honoured`.
    - `no next run after ends at`.
  - **Traces:** US-12.1

- [ ] **Task:** Criar a Action `SaveRecurrence(owner, data)`, que valida o preset (pelo menos 1 dia na semanal, `day_of_month` entre 1 e 31, cron válido, `ends_at` maior que `starts_at`, `timezone` do dono), grava `recurrences`/`recurrence_weekday` e calcula `next_run_at`. Criar também as Actions `PauseRecurrence` e `ResumeRecurrence` (a retomada recalcula a partir de agora).
  - **Acceptance criteria:**
    - Cron inválido → 422.
    - Retomar não dispara as execuções perdidas durante a pausa.
  - **Feature tests:**
    - `weekly recurrence requires at least one weekday`.
    - `invalid cron expression is rejected`.
    - `resuming recalculates from now without backfilling`.
  - **Traces:** US-12.1

### Phase 12.2: Scheduler e UI

- [ ] **Task:** Criar o comando `recurrences:dispatch`, agendado a cada minuto com `withoutOverlapping`. Ele seleciona `Recurrence::due()` com lock (`FOR UPDATE SKIP LOCKED`), despacha `RunScheduledSlot(recurrence, slot)` para a fila do dono, grava `last_run_at` e avança `next_run_at` a partir de **agora**, para que execuções perdidas rodem uma única vez.
  - **Acceptance criteria:**
    - Após 3 horas de indisponibilidade, uma recorrência horária via cron dispara 1 vez, não 3.
    - Recorrência pausada ou com dono soft-deleted não dispara.
    - Duas execuções concorrentes do comando não duplicam o despacho.
  - **Feature tests:**
    - `due recurrences dispatch one job per slot`.
    - `missed runs execute only once on recovery`.
    - `paused recurrence is not dispatched`.
    - `soft deleted owner is not dispatched`.
    - `concurrent dispatch does not duplicate jobs`.
  - **Traces:** US-12.1

- [ ] **Task:** Criar o componente Livewire `RecurrencePicker` (form object reutilizável): segmented control com os presets, seletor de dias da semana, dia do mês ou "último dia", campo de cron com preview das próximas 3 execuções, horário, início/fim e o fuso exibido.
  - **Acceptance criteria:**
    - O preview mostra as 3 próximas datas no fuso do usuário.
    - O preset `once` mostra só data e hora.
    - Funciona embutido em formulários de outros componentes.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-12.1, US-6.3, US-7.3, US-9.1

---

## Phase 13: Invoices — cadastros, recorrência e preview

**Goal:** payers, issuers, recorrência de invoice com datas dinâmicas e o PDF fiel ao modelo. · **Depends on:** Phases 6, 10, 12 · **Covers:** US-6.1, US-6.2, US-6.3, US-6.4, Workflows 6 e 7

### Phase 13.1: Cadastros

- [ ] **Task:** Criar as Actions `CreatePayer`, `UpdatePayer` e `DeletePayer` (nome até 255 caracteres obrigatório; endereço multilinha obrigatório; exclusão bloqueada se houver `invoice_recurrences` ativa usando o payer; soft delete) e as telas Livewire (lista + form em sheet) sob `module:invoices`.
  - **Acceptance criteria:**
    - Excluir um payer em uso → erro explicando o motivo.
    - Editar não altera invoices existentes, porque elas guardam snapshot.
  - **Feature tests:**
    - `payer requires name and address`.
    - `payer used by active recurrence cannot be deleted`.
    - `editing payer does not change existing invoice snapshot`.
    - `users without invoices module cannot manage payers`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-6.1

- [ ] **Task:** Criar as Actions `CreateIssuer`, `UpdateIssuer` e `DeleteIssuer` (nome, email, CEP, rua e número, bairro, cidade, UF obrigatórios; N issuers; mesmas regras de exclusão e snapshot) e as telas.
  - **Acceptance criteria:**
    - Email inválido → 422.
    - Excluir um issuer em uso → erro.
  - **Feature tests:**
    - `issuer requires all address fields`.
    - `issuer used by active recurrence cannot be deleted`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-6.2

### Phase 13.2: Recorrência de invoice

- [ ] **Task:** Criar o serviço `InvoiceDateCalculator::for(InvoiceRecurrence, CarbonImmutable $runAt)`, que devolve `issue_date` e `due_date`:
  - emissão `execution_day`: o dia da execução no fuso do dono;
  - emissão `day_of_current_month`: o dia X do mês, limitado ao último dia;
  - vencimento `days_after_issue`: emissão + N dias (0–365);
  - vencimento `day_of_next_month`: o dia X do mês seguinte, limitado ao último dia;
  - vencimento `last_day_of_issue_month`.
  - **Acceptance criteria:**
    - Reproduz o exemplo: execução em 01/09/2026 → emissão 01/09/2026 e vencimento 30/09/2026.
  - **Feature tests:**
    - `execution day and last day of issue month match the sample invoice`.
    - `day of current month clamps to month end`.
    - `days after issue adds n days`.
    - `day of next month clamps to month end`.
    - `issue date uses owner timezone at run time`.
  - **Traces:** US-6.3

- [ ] **Task:** Criar o `InvoiceTemplateSchema`, com as variáveis `current_month_name`, `current_year`, `invoice.number`, `invoice.date`, `invoice.due_date`, `invoice.amount`, `invoice.currency`, `payer.name` e `issuer.name`, mais dados de exemplo e o builder de contexto a partir de uma `Invoice`.
  - **Acceptance criteria:**
    - Datas no formato `dd/mm/yyyy`.
    - `invoice.amount` como `2,000.00`.
    - `invoice.currency` por extenso no idioma do destinatário.
  - **Feature tests:**
    - `invoice context formats amount and dates`.
  - **Traces:** US-6.3, US-4.1

- [ ] **Task:** Criar as Actions `SaveInvoiceRecurrence` e `DeleteInvoiceRecurrence` (soft delete pausa a recorrência). Validações:
  - payer e issuer ativos;
  - 1 a 10 destinatários com email válido e sem duplicata;
  - assunto e corpo válidos pelo `InvoiceTemplateSchema`;
  - descrição obrigatória;
  - moeda ativa e `amount` > 0 com 2 casas;
  - regras de data com `invoice_date_day` entre 1 e 31 e `due_date_value` coerente com a regra;
  - modo `automatic` ou `requires_review`;
  - recorrência via `SaveRecurrence`.

  Criar também a tela Livewire com `RecurrencePicker`, `x-ui.template-editor`, a data da próxima execução e o botão pausar/retomar.
  - **Acceptance criteria:**
    - 0 ou 11 destinatários → 422.
    - Variável desconhecida no assunto → 422.
    - A tela mostra a próxima execução no fuso do usuário.
  - **Feature tests:**
    - `recurrence requires between one and ten recipients`.
    - `recurrence rejects unknown template variables`.
    - `amount must be positive`.
    - `due date value must match rule`.
    - `saving computes next run at`.
    - `deleting recurrence pauses its schedule`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-6.3, US-12.1

- [ ] **Task:** Criar a view do PDF `invoices.pdf` com `spatie/laravel-pdf` (driver Gotenberg), fiel à invoice de exemplo:
  - título "Invoice" em azul;
  - "Receiver information" (nome, email, CEP + cidade/UF, rua + bairro);
  - "Bill to" (nome + endereço);
  - "Invoice number / Date / Due date" em 3 colunas;
  - "Description" multilinha;
  - moeda por extenso e valor em destaque.
  - **Acceptance criteria:**
    - O layout reproduz as seções e a ordem do modelo.
    - Os rótulos do PDF ficam em inglês, como no modelo, seja qual for o idioma do usuário.
  - **Feature tests:**
    - `pdf view renders all sections of the sample invoice` (assert do HTML renderizado contendo os blocos e valores).
  - **Design ref:** a invoice de exemplo fornecida pelo desenvolvedor (fora do repositório)
  - **Traces:** US-6.4, Workflow 8

- [ ] **Task:** Criar a Action `PreviewInvoiceRecurrence`, que calcula a próxima execução, as datas e o número provisório `YYYYMM-PREVIEW` **sem** consumir a sequência, e renderiza o PDF e o email. Criar também o preview na tela.
  - **Acceptance criteria:**
    - O preview nunca altera `invoice_number_sequences`.
    - O PDF do preview traz a marca "PREVIEW".
  - **Feature tests:**
    - `preview does not consume invoice numbers`.
    - `preview uses next run dates`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-6.4

---

## Phase 14: Invoices — geração, envio, revisão e histórico

**Goal:** a invoice sai sozinha, com revisão opcional, envio manual e histórico (**primeiro marco de valor em produção**). · **Depends on:** Phase 13 · **Covers:** US-6.5, US-6.6, US-6.7, US-6.8, US-5.4, Workflow 8

### Phase 14.1: Geração

- [ ] **Task:** Criar o serviço `InvoiceNumberGenerator::next(CarbonImmutable $issueDate)`: numa transação, `SELECT … FOR UPDATE` em `invoice_number_sequences` do `YYYYMM` (cria a linha se faltar), incrementa e devolve `YYYYMM-NNN` (zero-padded em 3 dígitos). O número é global.
  - **Acceptance criteria:**
    - O primeiro número do mês é `-001`.
    - Mês novo reinicia em `001`.
    - Concorrência não duplica.
    - Um número `cancelled` não é reutilizado.
  - **Feature tests:**
    - `first invoice of the month is 001`.
    - `sequence resets each month`.
    - `concurrent generation never duplicates numbers`.
    - `cancelled numbers are not reused`.
  - **Traces:** US-6.5

- [ ] **Task:** Criar a Action `GenerateInvoice(recurrence|data, runAt, periodKey)`: calcula as datas, reserva o número, grava a `invoices` com snapshot de payer e issuer, `invoice_recipients`, `email_subject`/`email_body` copiados e o status inicial (`pending_review` ou `draft`, conforme o modo), gera o PDF em `pdf_path` (disco privado) e é idempotente por `(invoice_recurrence_id, period_key)`.
  - **Acceptance criteria:**
    - Repetir para o mesmo período devolve a invoice existente, sem criar outra nem consumir número.
    - O PDF fica salvo e acessível só via rota autorizada.
  - **Feature tests:**
    - `generating twice for the same period returns the same invoice`.
    - `generated invoice snapshots payer and issuer`.
    - `review mode creates pending review invoice`.
  - **Traces:** US-6.5, US-6.6

- [ ] **Task:** Implementar `InvoiceRecurrence::runScheduledSlot` (job na fila `invoices`). O job gera a invoice para o `period_key` do slot (`YYYY-MM` em recorrência mensal; data ISO nos demais presets). No modo `automatic`, envia na sequência. No modo `requires_review`, notifica `invoice_pending_review`.
  - **Acceptance criteria:**
    - Recorrência mensal dia 1 às 08:00 `America/Sao_Paulo` gera em 01/10/2026 a invoice `202610-001` e envia.
  - **Feature tests:**
    - `scheduled slot generates and sends automatic invoice`.
    - `scheduled slot in review mode notifies pending review`.
  - **Traces:** US-6.5, US-6.6

### Phase 14.2: Envio e notificações

- [ ] **Task:** Criar a Action `SendInvoice(invoice, triggeredBy?)` com o Mailable `InvoiceMail`: assunto e corpo renderizados pelo `InvoiceTemplateSchema` no idioma do remetente e PDF anexo `invoice-{number}.pdf`, para todos os `invoice_recipients`. O envio cria um `invoice_deliveries` (`pending` → `sent`/`failed`) em job na fila `invoices` com 3 tentativas. No sucesso: invoice `sent`, `sent_at` e notificação `invoice_sent`. Na falha definitiva: invoice `failed`, `error_message` e notificação `invoice_failed`.
  - **Acceptance criteria:**
    - O email sai com o anexo para cada destinatário.
    - A falha após 3 tentativas marca `failed` e notifica.
  - **Feature tests:**
    - `sending mails every recipient with the pdf attached` (`Mail::fake`).
    - `successful send marks invoice sent and notifies`.
    - `definitive failure marks invoice failed and notifies`.
    - `subject renders template variables`.
  - **Traces:** US-6.5, US-5.4

- [ ] **Task:** Criar os tipos de notificação `invoice_sent`, `invoice_failed` e `invoice_pending_review`, com defaults `en`/`pt_BR` nos canais `database`, `mail`, `push` e `whatsapp`, schema de variáveis (número, payer, valor, link) e audiência = usuários com o módulo `invoices`.
  - **Acceptance criteria:**
    - Provisionados pelo `notifications:provision`.
    - O link leva à invoice.
  - **Feature tests:**
    - `invoice notification types are provisioned in both locales`.
    - `invoice notifications go only to users with the invoices module`.
  - **Traces:** US-5.4

### Phase 14.3: Revisão, manual e histórico

- [ ] **Task:** Criar as Actions `UpdatePendingInvoice` (edita descrição, datas, valor e destinatários de uma invoice `pending_review`/`draft` e regenera o PDF), `ApproveInvoice` (grava `approved_by_id` e chama `SendInvoice`) e `CancelInvoice` (status `cancelled`, número não reutilizado), e a tela de revisão.
  - **Acceptance criteria:**
    - Só invoices `pending_review`/`draft` são editáveis.
    - Uma invoice `sent` não pode ser editada nem cancelada.
  - **Feature tests:**
    - `pending invoice can be edited and pdf regenerated`.
    - `approving sends the invoice and records approver`.
    - `sent invoice cannot be edited`.
    - `cancelled invoice keeps its number reserved`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-6.6

- [ ] **Task:** Criar as Actions `GenerateAndSendNow(recurrence)` (aplica as regras à data atual, com `period_key` null, sem alterar `next_run_at`), `CreateOneOffInvoice` (mesmos campos, sem recorrência) e `ResendInvoice` (mesmo PDF e número, nova `invoice_deliveries` com `triggered_by_id`).
  - **Acceptance criteria:**
    - O envio manual não afeta a próxima execução agendada.
    - O reenvio preserva o número.
  - **Feature tests:**
    - `send now does not change next run`.
    - `one off invoice gets the next global number`.
    - `resend reuses number and pdf and logs a delivery`.
  - **Traces:** US-6.7

- [ ] **Task:** Criar o histórico de invoices em Livewire (paginado; filtros por status, payer e mês; colunas número, payer, data, vencimento, moeda, valor e status) e a rota de download do PDF autorizada por `module:invoices`.
  - **Acceptance criteria:**
    - O download por usuário sem o módulo → 403.
    - Os filtros combinam.
  - **Feature tests:**
    - `invoice history filters by status payer and month`.
    - `pdf download requires invoices module`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-6.8

---

## Phase 15: WhatsApp — Evolution API e contas

**Goal:** conectar números por QR, acompanhar o status em tempo real e sincronizar contatos e grupos. · **Depends on:** Phases 7, 10 · **Covers:** US-7.1, US-7.2, Workflow 9

### Phase 15.1: Cliente e webhook

- [ ] **Task:** Criar o cliente `EvolutionApiClient` (Http com `EVOLUTION_API_URL` e `EVOLUTION_API_KEY`), com os métodos `createInstance`, `connect` (QR), `logout`, `deleteInstance`, `fetchContacts`, `fetchGroups` e `sendText`, mais um fake para os testes. Conferir endpoints e payloads na documentação da versão implantada da Evolution API.
  - **Acceptance criteria:**
    - Timeouts de 10s e exceção tipada `EvolutionApiException` em resposta ≥ 400.
    - Nenhum teste chama a rede (`Http::fake`).
  - **Feature tests:**
    - `client raises typed exception on api errors`.
    - `client sends api key header`.
  - **Traces:** US-7.1, US-7.4

- [ ] **Task:** Criar o webhook `POST /api/v1/webhooks/evolution` (segredo compartilhado; sem segredo → 401), tratando os eventos de QR (broadcast do novo QR para o dono) e de conexão (`connecting`/`open`/`closed`).
  - **Acceptance criteria:**
    - Transição para `open` grava `connected_at` e `phone_number` e dispara a sincronização.
    - Transição para `closed` grava `disconnected_at` e notifica `whatsapp_account_disconnected`.
    - Instância desconhecida → 200 ignorado.
  - **Feature tests:**
    - `webhook without secret is rejected`.
    - `open event marks account connected and queues sync`.
    - `closed event notifies the owner`.
    - `qr event broadcasts to the owner only`.
  - **Traces:** US-7.1, US-7.2

### Phase 15.2: Contas

- [ ] **Task:** Criar a Action `ConnectWhatsappAccount(user, nickname)`, que cria a instância (`instance_name` único), grava a conta `connecting` e devolve o QR, e a tela Livewire de conexão com QR atualizado por Echo e renovação automática.
  - **Acceptance criteria:**
    - Só usuários com o módulo `whatsapp`.
    - O QR troca na tela sem refresh.
    - Ao conectar, a tela mostra o status `open`.
  - **Feature tests:**
    - `connecting creates an instance and a connecting account`.
    - `users without whatsapp module cannot connect accounts`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-7.1

- [ ] **Task:** Criar o job `SyncWhatsappTargets(account)`, que faz upsert de contatos e grupos em `whatsapp_targets` (`whatsapp_target_type` `contact`/`group`, `is_synced = true`) por `(whatsapp_account_id, jid)`.
  - **Acceptance criteria:**
    - Re-sincronizar não duplica.
    - Os nomes são atualizados.
    - Grava `last_synced_at`.
  - **Feature tests:**
    - `sync upserts contacts and groups without duplicates`.
  - **Traces:** US-7.1

- [ ] **Task:** Criar a `WhatsappAccountPolicy` (só o dono vê e usa), a Action `RemoveWhatsappAccount` (logout + delete da instância, pausa das `whatsapp_messages` da conta, remoção da conta) e a lista de contas com status em tempo real, apelido e botão remover.
  - **Acceptance criteria:**
    - Outro usuário não vê nem usa a conta (403).
    - Remover pausa as recorrências da conta.
  - **Feature tests:**
    - `accounts are visible only to their owner`.
    - `removing an account pauses its scheduled messages`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-7.2

- [ ] **Task:** Criar o tipo de notificação `whatsapp_account_disconnected` (defaults `en`/`pt_BR`; canais `database`, `mail` e `push`; o canal `whatsapp` não se aplica porque a conta caiu), endereçado ao dono da conta.
  - **Acceptance criteria:**
    - Provisionado.
    - O link leva à tela de reconexão.
  - **Feature tests:**
    - `disconnect notification goes only to the account owner`.
  - **Traces:** US-5.4, US-7.2

---

## Phase 16: WhatsApp — serviço genérico, agendamentos e canal de notificação

**Goal:** um serviço de envio reutilizável, mensagens únicas e recorrentes, envio imediato, histórico e o canal `whatsapp`. · **Depends on:** Phases 12, 15 · **Covers:** US-7.3, US-7.4, US-7.5, US-3.3, US-2.2, Workflow 10

### Phase 16.1: Serviço genérico

- [ ] **Task:** Criar a Action `SendWhatsAppMessage(account, target, body, source, ?message, ?scheduledFor, ?triggeredBy)`, que cria `whatsapp_deliveries` (`pending`) e despacha `DeliverWhatsappMessage` na fila `whatsapp` (3 tentativas com backoff). Conta não `open` → `failed` imediato com erro claro, sem chamar a API. Sucesso grava `external_message_id`, `sent_at` e `sent`. Falha definitiva grava `failed` e notifica `whatsapp_message_failed` ao dono.
  - **Acceptance criteria:**
    - É usada pelo módulo, pelo canal de notificação e pela voz (nenhum outro caminho chama `sendText`).
    - Idempotente por `(whatsapp_message_id, whatsapp_target_id, scheduled_for)`.
  - **Feature tests:**
    - `closed account fails fast without calling the api`.
    - `successful send stores external id`.
    - `definitive failure notifies the owner`.
    - `duplicate scheduled slot is not sent twice`.
  - **Traces:** US-7.4

- [ ] **Task:** Criar o `WhatsappTemplateSchema` (variáveis comuns + `recipient.name` e `sender.name`), com renderização em texto puro, e o tipo `whatsapp_message_failed` (defaults `en`/`pt_BR`; canais `database`, `mail` e `push`).
  - **Acceptance criteria:**
    - O corpo renderiza sem HTML.
    - O tipo é provisionado.
  - **Feature tests:**
    - `whatsapp body renders as plain text`.
  - **Traces:** US-7.3, US-5.4

### Phase 16.2: Agendamentos e histórico

- [ ] **Task:** Criar as Actions `ScheduleWhatsappMessage` e `UpdateWhatsappMessage`. Validações:
  - conta do próprio usuário com status `open`;
  - 1+ targets da conta, ou número E.164 digitado (cria `whatsapp_targets` com `is_synced = false`);
  - corpo válido;
  - recorrência `once` com data futura, ou recorrente via `SaveRecurrence`.

  Criar também `CancelWhatsappMessage` (só `once` antes do horário; grava `cancelled_at`) e o pausar/retomar (`PauseRecurrence`/`ResumeRecurrence`).
  - **Acceptance criteria:**
    - Data no passado → 422.
    - Target de outra conta → 422.
    - Cancelar uma mensagem já enviada → erro.
  - **Feature tests:**
    - `scheduling requires an open account owned by the user`.
    - `once message in the past is rejected`.
    - `manual e164 number creates an unsynced target`.
    - `cancelled once message is not sent`.
    - `targets from another account are rejected`.
  - **Traces:** US-7.3

- [ ] **Task:** Implementar `WhatsappMessage::runScheduledSlot`, que chama `SendWhatsAppMessage` para cada target com `source = scheduled` e `scheduled_for = slot`.
  - **Acceptance criteria:**
    - Uma delivery por target por slot.
    - Mensagem cancelada ou soft-deleted não envia.
  - **Feature tests:**
    - `scheduled slot sends one delivery per target`.
    - `recurring message sends again on the next slot`.
  - **Traces:** US-7.3, US-7.4

- [ ] **Task:** Criar as telas Livewire de mensagens (lista com a próxima execução, form com conta, seletor de targets com busca, `x-ui.template-editor`, preview e `RecurrencePicker`), a Action `SendWhatsappNow` (`source = manual`, sem agendamento) e o histórico paginado de deliveries das contas do usuário (data, conta, target, trecho e status).
  - **Acceptance criteria:**
    - O histórico mostra só as contas do usuário.
    - O "Enviar agora" aparece na tela de mensagem e numa ação rápida.
  - **Feature tests:**
    - `send now creates a manual delivery without schedule`.
    - `history lists only the users own accounts`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-7.3, US-7.5

### Phase 16.3: Canal de notificação e regra de desativação

- [ ] **Task:** Criar o canal de notificação `whatsapp` (driver em `ChannelDrivers`), que envia via `SendWhatsAppMessage` com `source = notification`, conta = `users.whatsapp_notification_account_id` e destino = `whatsapp_notification_phone`. Criar também, no perfil, a configuração (Action `UpdateWhatsappNotificationSettings`: conta própria e telefone E.164).
  - **Acceptance criteria:**
    - Sem conta ou telefone, o gate pula o canal.
    - A conta precisa ser do próprio usuário.
  - **Feature tests:**
    - `whatsapp channel sends through the users own account`.
    - `whatsapp channel is skipped without settings`.
    - `notification account must belong to the user`.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-3.3, US-5.3

- [ ] **Task:** Criar o listener `PauseWhatsappSchedulesOfDeactivatedUser` para o evento `UserDeactivated`.
  - **Acceptance criteria:**
    - Todas as recorrências de `whatsapp_messages` do usuário ficam `is_paused = true`.
  - **Feature tests:**
    - `deactivating a user pauses their whatsapp schedules`.
  - **Traces:** US-2.2

---

## Phase 17: Lembretes e tarefas

**Goal:** lembretes compartilhados com ocorrências, recorrência e aviso por push e WhatsApp. · **Depends on:** Phases 12, 16 · **Covers:** US-9.1, US-9.2, US-5.4, Workflow 12

### Phase 17.1: Regras

- [ ] **Task:** Criar as Actions `CreateReminder` e `UpdateReminder` (título obrigatório até 255 caracteres, nota opcional, data e hora opcionais, recorrência opcional). Sem data → 1 ocorrência com `due_at` null. Com data e sem repetição → recorrência `once` + 1 ocorrência. Recorrente → recorrência + ocorrência no `next_run_at`. Criar também `DeleteReminder` (soft delete pausa a recorrência).
  - **Acceptance criteria:**
    - Sempre existe exatamente 1 ocorrência aberta por lembrete ativo.
    - O lembrete é compartilhado no household.
  - **Feature tests:**
    - `undated reminder creates one undated occurrence`.
    - `dated reminder creates a once recurrence and occurrence`.
    - `recurring reminder creates occurrence at next run`.
    - `deleting reminder pauses its recurrence`.
  - **Traces:** US-9.1

- [ ] **Task:** Criar as Actions `CompleteReminderOccurrence` (grava `completed_at`/`completed_by_id`; em lembrete recorrente cria a próxima ocorrência no `next_run_at`, com unique para não duplicar) e `ReopenReminderOccurrence`.
  - **Acceptance criteria:**
    - Concluir registra quem e quando.
    - Reabrir limpa os dois campos.
    - Concluir duas vezes não cria duas ocorrências futuras.
  - **Feature tests:**
    - `completing records who and when`.
    - `completing a recurring reminder creates the next occurrence once`.
    - `reopening clears completion`.
  - **Traces:** US-9.1

- [ ] **Task:** Implementar `Reminder::runScheduledSlot`: obtém ou cria a ocorrência do slot; se não estiver concluída e `notified_at` for null, dispara `reminder_due` ao criador e grava `notified_at`; em lembrete recorrente garante a próxima ocorrência. Criar o tipo `reminder_due` (defaults `en`/`pt_BR`; canais `database`, `push` e `whatsapp`, todos ativos por padrão, e `mail` desligado por padrão).
  - **Acceptance criteria:**
    - Notifica no máximo 1 vez por ocorrência, em até 1 minuto após `due_at`.
    - Ocorrência concluída antes do horário não notifica.
  - **Feature tests:**
    - `due reminder notifies the creator once`.
    - `completed occurrence is not notified`.
    - `recurring reminder schedules next occurrence after slot`.
    - `reminder due respects channel preferences`.
  - **Traces:** US-9.2, US-5.4

### Phase 17.2: Telas

- [ ] **Task:** Criar as telas Livewire de lembretes: segmented control "Pending / Overdue / Completed", linha com checkbox circular iOS, data no fuso do usuário, indicação de recorrência, form em sheet com `RecurrencePicker`, e "concluído por X" nos concluídos.
  - **Acceptance criteria:**
    - Cada ocorrência aparece em exatamente um filtro.
    - O checkbox conclui sem recarregar.
    - Funciona em 360px.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-9.1

---

## Phase 18: Lista de compras

**Goal:** listas compartilhadas com marcação em tempo real. · **Depends on:** Phase 10 · **Covers:** US-8.1, US-8.2, Workflow 11

### Phase 18.1: Regras e realtime

- [ ] **Task:** Criar as Actions `CreateShoppingList`, `RenameShoppingList`, `ArchiveShoppingList`, `UnarchiveShoppingList` e `DeleteShoppingList` (nome obrigatório até 100 caracteres; soft delete), e `AddShoppingListItem`, `UpdateShoppingListItem` e `RemoveShoppingListItem` (nome obrigatório; `quantity_note` opcional; `position` no fim).
  - **Acceptance criteria:**
    - Uma lista arquivada sai da tela principal e fica consultável em "Archived".
    - Tudo é compartilhado entre os usuários com o módulo `shopping`.
  - **Feature tests:**
    - `list name is required and limited to 100 chars`.
    - `archived lists are excluded from the main list`.
    - `items are appended at the end`.
    - `users without shopping module are forbidden`.
  - **Traces:** US-8.1

- [ ] **Task:** Criar as Actions `ToggleShoppingListItem(item, checked, ?clientUpdatedAt)` (grava `checked_at`/`checked_by_id` com last-write-wins por `client_updated_at`: uma escrita mais antiga que a gravada é ignorada) e `ClearCheckedItems`. Criar os eventos de broadcast `ShoppingListChanged` no canal privado `shopping-lists.{friendly_id}`, autorizado para quem tem o módulo `shopping`.
  - **Acceptance criteria:**
    - Toda mutação de lista ou item emite o evento.
    - Uma escrita offline mais antiga não sobrescreve uma mais nova.
  - **Feature tests:**
    - `toggling broadcasts to the list channel`.
    - `older offline write does not override newer state`.
    - `clear checked removes only checked items`.
    - `list channel authorizes only users with the shopping module`.
  - **Traces:** US-8.2

### Phase 18.2: Telas

- [ ] **Task:** Criar as telas Livewire: índice de listas (ativas e arquivadas) e tela da lista com campo "Add item" fixo, itens marcados no fim com texto riscado, "Clear checked" e listener Echo atualizando em até 2s.
  - **Acceptance criteria:**
    - Duas janelas abertas veem a marcação uma da outra sem refresh.
    - Os itens marcados vão para o fim.
    - É usável com uma mão em 360px.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*`; sem mockup em `.spec/init/design/`
  - **Traces:** US-8.1, US-8.2

---

## Phase 19: API v1 — autenticação, perfil, dispositivos e notificações

**Goal:** a base da API para os apps, sobre as mesmas Actions. · **Depends on:** Phases 8, 11 · **Covers:** US-1.5, US-12.3, US-3.2, US-3.4, US-5.1, US-11.1

### Phase 19.1: Auth

- [ ] **Task:** Criar `POST /api/v1/auth/login` (email + senha + `device_name`, reaproveitando as regras de `AuthenticateUser`, com rate limit) e `POST /api/v1/auth/logout` (revoga só o token atual).
  - **Acceptance criteria:**
    - Devolve `{ token, user }`.
    - Credenciais inválidas → 422.
    - Usuário desativado → 422 com mensagem.
  - **Feature tests:**
    - `api login returns a named sanctum token`.
    - `api login with invalid credentials returns 422`.
    - `api logout revokes only the current token`.
    - `revoked token gets 401`.
  - **Traces:** US-1.5

- [ ] **Task:** Criar `POST /api/v1/auth/google`, que recebe o ID token do Google Sign-In nativo e o valida no servidor: `aud` ∈ {`GOOGLE_IOS_CLIENT_ID`, `GOOGLE_ANDROID_CLIENT_ID`, `GOOGLE_CLIENT_ID`}, `iss` do Google e `exp` válido, via endpoint `tokeninfo` do Google (decisão do desenvolvedor: opção mais simples, sem nova dependência). Aplica as mesmas regras de convite de `AuthenticateWithGoogle`.
  - **Acceptance criteria:**
    - Token de outro `aud` → 422.
    - Sem conta e sem convite → 422 "invitation required".
  - **Feature tests:**
    - `google api login creates account from pending invitation` (`Http::fake` do Google).
    - `google token with foreign audience is rejected`.
    - `google api login without invitation is refused`.
  - **Traces:** US-1.5, US-1.3

### Phase 19.2: Perfil, dispositivos e notificações

- [ ] **Task:** Criar `GET /api/v1/me` (`UserResource`: friendly_id, nome, email, avatar, locale, timezone, `modules` com os slugs ligados e `is_available_on_mobile`) e `PATCH /api/v1/me/preferences` (Action `UpdatePreferences`).
  - **Acceptance criteria:**
    - Super admin recebe todos os módulos.
    - Preferências inválidas → 422.
  - **Feature tests:**
    - `me returns enabled module slugs`.
    - `preferences endpoint reuses update preferences action`.
  - **Traces:** US-1.5, US-3.2, US-11.1, US-12.3

- [ ] **Task:** Criar `POST /api/v1/devices` (registra ou atualiza o dispositivo do token atual com plataforma, nome e `push_token` único, reatribuindo um token já existente de outro registro), `GET /api/v1/devices` e `DELETE /api/v1/devices/{device}` (`RevokeDevice`).
  - **Acceptance criteria:**
    - Um `push_token` duplicado move para o dispositivo atual.
    - Só os dispositivos do próprio usuário.
  - **Feature tests:**
    - `registering a device links it to the current token`.
    - `duplicate push token is reassigned`.
    - `user cannot delete another users device`.
  - **Traces:** US-3.4, US-5.2

- [ ] **Task:** Criar `GET /api/v1/notifications` (paginado, `NotificationResource`), `POST /api/v1/notifications/{id}/read` e `POST /api/v1/notifications/read-all`, além de `POST /api/v1/broadcasting/auth` com `auth:sanctum` para os apps assinarem os canais privados do Reverb.
  - **Acceptance criteria:**
    - Marcar a notificação de outro usuário → 404.
    - Canal de lista sem o módulo → 403.
  - **Feature tests:**
    - `api notifications are paginated and scoped to the user`.
    - `api read all clears unread`.
    - `sanctum token can authorize private channels`.
  - **Traces:** US-5.1, US-8.2

---

## Phase 20: API v1 — endpoints dos módulos de uso diário

**Goal:** lista de compras, lembretes e WhatsApp na API, com as mesmas Actions da web. · **Depends on:** Phases 16, 17, 18, 19 · **Covers:** US-12.3, US-8.1, US-8.2, US-9.1, US-7.3, US-7.5

### Phase 20.1: Endpoints

- [ ] **Task:** Criar os endpoints de lista de compras sob `module:shopping`: `GET|POST /shopping/lists`, `PATCH|DELETE /shopping/lists/{list}`, `POST /shopping/lists/{list}/archive|unarchive`, `POST /shopping/lists/{list}/items`, `PATCH|DELETE /shopping/items/{item}`, `POST /shopping/items/{item}/toggle` (aceita `client_updated_at`) e `POST /shopping/lists/{list}/clear-checked`.
  - **Acceptance criteria:**
    - As respostas usam `ShoppingListResource`/`ShoppingListItemResource` com `friendly_id`.
    - As mutações emitem os mesmos eventos de broadcast da web.
  - **Feature tests:**
    - `api shopping endpoints use the same actions as livewire` (mesma validação 422).
    - `api toggle applies last write wins`.
    - `api shopping requires module`.
  - **Traces:** US-8.1, US-8.2, US-12.3

- [ ] **Task:** Criar os endpoints de lembretes sob `module:reminders`: `GET /reminders?filter=pending|overdue|completed`, `POST /reminders`, `PATCH|DELETE /reminders/{reminder}`, `POST /reminder-occurrences/{occurrence}/complete` e `/reopen`.
  - **Acceptance criteria:**
    - Os filtros retornam os mesmos conjuntos da web.
    - Datas em ISO-8601 UTC, com o fuso do usuário em `meta`.
  - **Feature tests:**
    - `api reminder filters match web scopes`.
    - `api complete creates next occurrence for recurring reminders`.
  - **Traces:** US-9.1, US-12.3

- [ ] **Task:** Criar os endpoints de WhatsApp sob `module:whatsapp`: `GET /whatsapp/accounts` (só leitura; conectar fica na web), `GET /whatsapp/accounts/{account}/targets?search=`, `GET|POST /whatsapp/messages`, `PATCH|DELETE /whatsapp/messages/{message}`, `POST /whatsapp/messages/{message}/cancel|pause|resume`, `POST /whatsapp/send-now` e `GET /whatsapp/deliveries`.
  - **Acceptance criteria:**
    - Policy de dono em todas as rotas.
    - Não existe endpoint de conexão por QR (fica só na web).
  - **Feature tests:**
    - `api whatsapp resources are scoped to the owner`.
    - `api schedule message validates like web`.
    - `api send now creates a manual delivery`.
  - **Traces:** US-7.3, US-7.5, US-12.3

- [ ] **Task:** Garantir que a API não expõe os módulos e telas exclusivos da web (invoices, admin, conexão de WhatsApp, templates de sistema) e publicar a especificação OpenAPI da v1 em `docs/api/v1.yaml`, como contrato dos apps.
  - **Acceptance criteria:**
    - `php artisan route:list --path=api/v1` não lista rotas de invoices nem de admin.
    - O OpenAPI cobre todos os endpoints das Phases 19 e 20.
  - **Feature tests:**
    - `api exposes no invoice or admin routes`.
  - **Traces:** US-12.3, US-11.1

---

## Phase 21: Push — backend FCM

**Goal:** o canal `push` da central de notificações. · **Depends on:** Phases 7, 19 · **Covers:** US-5.2, US-5.3

### Phase 21.1: Canal push

- [ ] **Task:** Configurar o envio via **FCM HTTP v1** para iOS e Android (decisão do desenvolvedor: só FCM). Preferir `Http` + service account do Firebase (`FIREBASE_CREDENTIALS` com o JSON da service account e `FIREBASE_PROJECT_ID`), sem pacote novo. Se um pacote for indispensável, pare e registre a necessidade, sem instalar.
  - **Acceptance criteria:**
    - O access token OAuth da service account é obtido e fica em cache até expirar.
    - Nenhuma dependência nova sem aprovação.
  - **Traces:** US-5.2

- [ ] **Task:** Criar o driver do canal `push` em `ChannelDrivers`. Ele envia título e corpo renderizados (texto puro) para todos os `devices` do usuário com `push_token`, com payload `deep_link` vindo da primeira ação do tipo, e remove o `push_token` quando o FCM responde `UNREGISTERED`/`INVALID_ARGUMENT` para o token.
  - **Acceptance criteria:**
    - Usuário com 2 dispositivos recebe 2 envios.
    - Um token inválido é limpo e os demais seguem.
    - Falha de um dispositivo não falha o job dos outros.
  - **Feature tests:**
    - `push is sent to every device with a token`.
    - `invalid token is removed`.
    - `push payload carries the deep link`.
    - `push respects user preference`.
  - **Traces:** US-5.2, US-5.3

---

## Phase 22: App iOS — fundação

**Goal:** projeto Swift/SwiftUI no monorepo, com auth, navegação por módulos, i18n, push e realtime. · **Depends on:** Phases 19, 20, 21 · **Covers:** US-1.5, US-11.1, US-5.1, US-5.2, US-12.2

### Phase 22.1: Projeto e auth

- [ ] **Task:** Criar `apps/ios` (Xcode, SwiftUI, deployment target **iOS 27**, distribuição por instalação direta via Xcode/ad hoc) com módulos `Networking` (cliente da API v1 gerado ou escrito a partir de `docs/api/v1.yaml`), `Auth` (token Sanctum no Keychain) e `Localization` (`en`/`pt_BR` via String Catalog), e um README de build.
  - **Acceptance criteria:**
    - `xcodebuild test` roda no CI local.
    - O 401 de qualquer chamada leva de volta ao login.
    - O idioma segue a preferência do usuário vinda de `/me`.
  - **Feature tests:**
    - XCTest `APIClientTests` → um 401 limpa o token e emite `sessionExpired`.
    - XCTest `TokenStoreTests` → o token persiste no Keychain e é removido no logout.
  - **Traces:** US-1.5, US-12.2

- [ ] **Task:** Criar as telas de login: email/senha e "Continue with Google" (SDK Google Sign-In → ID token → `/auth/google`), com `device_name` = nome do aparelho, mensagens de erro de "invitation required" e o visual iOS 27.
  - **Acceptance criteria:**
    - O login Google funciona para um email convidado.
    - O email não convidado vê a mensagem traduzida.
  - **Design ref:** Design system iOS 27 interno (Phase 5) — `resources/views/components/ui/*` como referência de tokens; componentes nativos SwiftUI/Liquid Glass; sem mockup em `.spec/init/design/`
  - **Traces:** US-1.5, US-1.3

### Phase 22.2: Shell, push e realtime

- [ ] **Task:** Criar o `TabView` montado a partir de `/me` com as abas Shopping, Reminders e WhatsApp (só os módulos ligados e `is_available_on_mobile`), mais Notifications e Settings, além da tela "No access" ao receber 403 e o refresh de `/me` ao voltar ao foreground.
  - **Acceptance criteria:**
    - Um módulo desligado some no próximo refresh.
    - Invoices e admin não existem no app.
  - **Feature tests:**
    - XCTest `TabBuilderTests` → os slugs de `/me` geram exatamente as abas esperadas.
  - **Design ref:** Design system iOS 27 interno (Phase 5); componentes nativos SwiftUI/Liquid Glass; sem mockup em `.spec/init/design/`
  - **Traces:** US-11.1

- [ ] **Task:** Implementar o push: pedir permissão, registrar no FCM (Firebase Messaging SDK, com a chave APNs no Firebase) e enviar o token FCM a `POST /devices`. Tocar no push abre o deep link na aba e tela corretas.
  - **Acceptance criteria:**
    - Negar a permissão não quebra o app.
    - O deep link `jarvis://reminders/{id}` abre o lembrete.
  - **Feature tests:**
    - XCTest `DeepLinkRouterTests` → cada formato de deep link resolve para a rota certa.
  - **Traces:** US-5.2

- [ ] **Task:** Criar o cliente realtime: protocolo Pusher contra o Reverb, auth via `/broadcasting/auth` com Bearer, a tela de notificações (lista, marcar lida/todas) e o badge de não lidas.
  - **Acceptance criteria:**
    - Uma notificação nova aparece sem pull-to-refresh com o app aberto.
    - Reconecta após perder a rede.
  - **Design ref:** Design system iOS 27 interno (Phase 5); componentes nativos SwiftUI/Liquid Glass; sem mockup em `.spec/init/design/`
  - **Traces:** US-5.1

---

## Phase 23: App iOS — módulos de uso diário

**Goal:** lista de compras, lembretes e WhatsApp no iOS. · **Depends on:** Phase 22 · **Covers:** US-8.1, US-8.2, US-9.1, US-7.3, US-7.5

### Phase 23.1: Telas

- [ ] **Task:** Criar as telas de lista de compras (índice, lista com add rápido, marcar item com haptic, marcados no fim, clear checked, arquivar) com realtime no canal `shopping-lists.{id}`.
  - **Acceptance criteria:**
    - A marcação feita em outro aparelho aparece em até 2s.
    - Funciona com uma mão.
  - **Design ref:** Design system iOS 27 interno (Phase 5); componentes nativos SwiftUI/Liquid Glass; sem mockup em `.spec/init/design/`
  - **Traces:** US-8.1, US-8.2

- [ ] **Task:** Criar a fila offline de mutações de itens (persistida localmente). Cada mutação leva o `client_updated_at` do momento do toque e é reenviada ao reconectar; o servidor resolve com last-write-wins.
  - **Acceptance criteria:**
    - Marcar sem rede reflete na UI na hora e sincroniza ao voltar.
    - Mutações reenviadas não duplicam itens.
  - **Feature tests:**
    - XCTest `OfflineQueueTests` → as mutações persistem entre reinícios e são enviadas em ordem com `client_updated_at` original.
  - **Traces:** US-8.2

- [ ] **Task:** Criar as telas de lembretes (filtros Pending/Overdue/Completed, concluir/reabrir, criar e editar com data, hora e recorrência) e as telas de WhatsApp (lista de mensagens, agendar com seleção de conta e targets, cancelar/pausar/retomar, enviar agora, histórico).
  - **Acceptance criteria:**
    - Os filtros batem com a web.
    - Agendar no passado mostra o erro 422 traduzido.
    - Não há tela de conexão de conta (leva à web).
  - **Design ref:** Design system iOS 27 interno (Phase 5); componentes nativos SwiftUI/Liquid Glass; sem mockup em `.spec/init/design/`
  - **Traces:** US-9.1, US-7.3, US-7.5

---

## Phase 24: App Android — fundação

**Goal:** projeto Kotlin/Jetpack Compose no monorepo, com auth, navegação por módulos, i18n, push e realtime. · **Depends on:** Phases 19, 20, 21 · **Covers:** US-1.5, US-11.1, US-5.1, US-5.2, US-12.2

### Phase 24.1: Projeto e auth

- [ ] **Task:** Criar `apps/android` (Gradle, Kotlin, Jetpack Compose, **minSdk 37 / Android 17**, APK assinado para instalação direta) com as camadas `network` (Retrofit/Ktor a partir de `docs/api/v1.yaml`), `auth` (token no EncryptedSharedPreferences/DataStore) e strings `en`/`pt-rBR`.
  - **Acceptance criteria:**
    - `./gradlew test` passa.
    - Um 401 leva ao login.
    - O idioma segue `/me`.
  - **Feature tests:**
    - JUnit `ApiClientTest` → um 401 limpa o token e emite `SessionExpired`.
    - JUnit `TokenStoreTest` → persistir e remover.
  - **Traces:** US-1.5, US-12.2

- [ ] **Task:** Criar as telas de login: email/senha e Google via Credential Manager (ID token → `/auth/google`), com as mensagens de convite e o visual equivalente ao iOS 27 no Material 3 (vidro, cantos e tipografia).
  - **Acceptance criteria:**
    - Um email convidado entra.
    - Um email não convidado vê a mensagem traduzida.
  - **Design ref:** Design system iOS 27 interno (Phase 5) adaptado ao Compose; sem mockup em `.spec/init/design/`
  - **Traces:** US-1.5, US-1.3

### Phase 24.2: Shell, push e realtime

- [ ] **Task:** Criar a bottom navigation a partir de `/me` (Shopping, Reminders e WhatsApp quando ligados, mais Notifications e Settings) e a tela "No access" no 403.
  - **Acceptance criteria:**
    - Um módulo desligado some no refresh.
    - Não há invoices nem admin.
  - **Feature tests:**
    - JUnit `NavigationBuilderTest` → os slugs geram as abas esperadas.
  - **Design ref:** Design system iOS 27 interno (Phase 5) adaptado ao Compose; sem mockup em `.spec/init/design/`
  - **Traces:** US-11.1

- [ ] **Task:** Implementar o push com FCM (permissão `POST_NOTIFICATIONS`, token para `POST /devices`, deep links), o cliente realtime Pusher/Reverb com Bearer e a tela de notificações com badge.
  - **Acceptance criteria:**
    - Tocar no push abre a tela certa.
    - Uma notificação nova aparece em tempo real com o app aberto.
  - **Feature tests:**
    - JUnit `DeepLinkRouterTest` → cada deep link resolve a rota certa.
  - **Design ref:** Design system iOS 27 interno (Phase 5) adaptado ao Compose; sem mockup em `.spec/init/design/`
  - **Traces:** US-5.1, US-5.2

---

## Phase 25: App Android — módulos de uso diário

**Goal:** lista de compras, lembretes e WhatsApp no Android. · **Depends on:** Phase 24 · **Covers:** US-8.1, US-8.2, US-9.1, US-7.3, US-7.5

### Phase 25.1: Telas

- [ ] **Task:** Criar as telas de lista de compras com realtime e a fila offline (Room/DataStore) com `client_updated_at`, reenviada pelo WorkManager ao reconectar.
  - **Acceptance criteria:**
    - Uma marcação offline sincroniza ao voltar a rede.
    - A marcação de outro aparelho aparece em até 2s.
  - **Feature tests:**
    - JUnit `OfflineQueueTest` → ordem e `client_updated_at` preservados; sem duplicar.
  - **Design ref:** Design system iOS 27 interno (Phase 5) adaptado ao Compose; sem mockup em `.spec/init/design/`
  - **Traces:** US-8.1, US-8.2

- [ ] **Task:** Criar as telas de lembretes (filtros, concluir/reabrir, criar e editar com recorrência) e de WhatsApp (mensagens, agendar, cancelar/pausar/retomar, enviar agora, histórico).
  - **Acceptance criteria:**
    - Paridade de comportamento com o iOS da Phase 23.
    - Erros 422 traduzidos.
  - **Design ref:** Design system iOS 27 interno (Phase 5) adaptado ao Compose; sem mockup em `.spec/init/design/`
  - **Traces:** US-9.1, US-7.3, US-7.5

---

## Phase 26: Voz — backend (intérprete remoto e execução)

**Goal:** endpoint de comandos de voz com o agente `laravel/ai`, que executa as mesmas Actions. · **Depends on:** Phases 10, 16, 17, 18, 19 · **Covers:** US-10.2, US-10.3, US-2.3, Workflow 13

### Phase 26.1: Contrato e resolução

- [ ] **Task:** Definir o contrato de ação estruturada `VoiceAction`: `shopping.add_item`, `shopping.check_item`, `reminders.create`, `reminders.complete` e `whatsapp.send`, cada uma com seus parâmetros. Criar o executor `ExecuteVoiceAction`, que chama as Actions existentes, verifica `hasModule` e devolve `done`, `needs_confirmation`, `needs_clarification` ou `forbidden`.
  - **Acceptance criteria:**
    - O executor nunca contém regra de negócio própria; só chama as Actions das Phases 16, 17 e 18.
    - Módulo desligado → `forbidden` com mensagem traduzida.
  - **Feature tests:**
    - `voice action reuses the domain actions`.
    - `voice action for a disabled module is forbidden`.
  - **Traces:** US-10.2, US-10.3

- [ ] **Task:** Criar os resolvedores fuzzy `ResolveShoppingList` ("dessa semana" → nome mais próximo entre as listas ativas), `ResolveReminder` e `ResolveWhatsappTarget` (entre os targets das contas do próprio usuário). 0 candidatos → erro amigável. 2 ou mais → `needs_clarification` com as opções.
  - **Acceptance criteria:**
    - Nunca escolhe sozinho quando há 2 ou mais candidatos com pontuação próxima.
    - Nunca resolve um target de conta de outro usuário.
  - **Feature tests:**
    - `ambiguous list name asks for clarification`.
    - `target resolution only considers the users own accounts`.
    - `single match resolves directly`.
  - **Traces:** US-10.3

- [ ] **Task:** Criar o fluxo de confirmação do WhatsApp: `whatsapp.send` **sempre** devolve `needs_confirmation` com um `confirmation_token` (cache, 5 min, uso único) e um resumo. `POST /api/v1/voice/commands/confirm` executa o envio (`source = voice`).
  - **Acceptance criteria:**
    - Sem confirmação nada é enviado.
    - Um token expirado ou reutilizado → 422.
  - **Feature tests:**
    - `whatsapp voice command never sends without confirmation`.
    - `confirmation token is single use and expires in five minutes`.
    - `confirmed voice send creates a voice delivery`.
  - **Traces:** US-10.3, US-7.4

### Phase 26.2: Intérprete remoto e endpoints

- [ ] **Task:** Criar o agente `VoiceCommandAgent` (`laravel/ai`, conexão ativa de `ai_provider_connections`), com uma tool por `VoiceAction`, instruções no idioma do usuário e data e hora atuais no fuso do usuário (para "amanhã às 9h").
  - **Acceptance criteria:**
    - Sem conexão ativa → erro "AI provider not configured".
    - "adicione leite na lista de compras dessa semana" vira `shopping.add_item`.
    - Conferir a API de agents/tools na versão instalada via `search-docs`.
  - **Feature tests:**
    - `agent maps add item utterance to shopping add item` (fake do `laravel/ai`).
    - `missing ai provider returns friendly error`.
    - `relative dates use the user timezone`.
  - **Traces:** US-10.2, US-2.3

- [ ] **Task:** Criar `POST /api/v1/voice/commands` (`{ text }` → intérprete remoto → `ExecuteVoiceAction`) e `POST /api/v1/voice/actions` (recebe uma `VoiceAction` já estruturada pelo intérprete local do app e executa pelo mesmo executor). As respostas trazem `action`, `status` e `message` traduzida para TTS.
  - **Acceptance criteria:**
    - Os dois endpoints produzem o mesmo efeito para a mesma ação.
    - Rate limit de 20/min por usuário.
  - **Feature tests:**
    - `remote and structured endpoints produce the same effect`.
    - `voice endpoint response matches documented shape`.
    - `voice endpoints are rate limited`.
  - **Traces:** US-10.2, US-10.3

---

## Phase 27: Voz — iOS

**Goal:** microfone, Siri (App Intents), intérprete local e TTS no iOS. · **Depends on:** Phases 23, 26 · **Covers:** US-10.1, US-10.2, US-10.3

### Phase 27.1: Gatilhos e intérprete

- [ ] **Task:** Criar o botão de microfone global com o framework Speech (transcrição no idioma do usuário), o pedido de permissão com explicação e o fallback ao negar.
  - **Acceptance criteria:**
    - Sem permissão, o app explica como habilitar e não trava.
    - A transcrição aparece enquanto o usuário fala.
  - **Design ref:** Design system iOS 27 interno (Phase 5); componentes nativos SwiftUI/Liquid Glass; sem mockup em `.spec/init/design/`
  - **Traces:** US-10.1

- [ ] **Task:** Criar App Intents para "add shopping item", "create reminder", "complete reminder" e "send WhatsApp message", acionáveis por "Siri, …" com o nome do app. Fato: o iOS não permite palavra de ativação própria, e o SiriKit foi depreciado na WWDC 2026.
  - **Acceptance criteria:**
    - Cada intent chama `/voice/actions`.
    - O envio de WhatsApp pede confirmação no diálogo da Siri.
  - **Feature tests:**
    - XCTest `IntentMappingTests` → cada intent produz a `VoiceAction` correta.
  - **Traces:** US-10.1, US-10.3

- [ ] **Task:** Criar o intérprete local com Apple Foundation Models (quando disponível no aparelho), que produz a mesma `VoiceAction`. Preferência em Settings: "local", "remoto" ou "automático" (local se disponível, senão remoto). Executar a resposta com UI de confirmação ou esclarecimento e TTS (`AVSpeechSynthesizer`) no idioma do usuário.
  - **Acceptance criteria:**
    - Aparelho sem suporte cai para o remoto no modo automático.
    - `needs_clarification` mostra as opções.
    - `needs_confirmation` exige um toque antes de enviar.
  - **Feature tests:**
    - XCTest `InterpreterSelectionTests` → a preferência e a disponibilidade escolhem o intérprete certo.
    - XCTest `VoiceResponseHandlerTests` → cada `status` leva ao fluxo de UI correto.
  - **Traces:** US-10.2, US-10.3

---

## Phase 28: Voz — Android

**Goal:** microfone, App Actions, intérprete local e TTS no Android (fecha o MVP). · **Depends on:** Phases 25, 26 · **Covers:** US-10.1, US-10.2, US-10.3

### Phase 28.1: Gatilhos e intérprete

- [ ] **Task:** Criar o botão de microfone com `SpeechRecognizer` (idioma do usuário) e o fluxo de permissão `RECORD_AUDIO`.
  - **Acceptance criteria:**
    - Negar a permissão mostra a explicação, sem crash.
  - **Design ref:** Design system iOS 27 interno (Phase 5) adaptado ao Compose; sem mockup em `.spec/init/design/`
  - **Traces:** US-10.1

- [ ] **Task:** Criar App Actions (`shortcuts.xml` com capabilities para adicionar item, criar lembrete, concluir lembrete e enviar mensagem), que chamam `/voice/actions`. O envio de WhatsApp pede confirmação no app.
  - **Acceptance criteria:**
    - Cada capability abre o fluxo certo.
    - O WhatsApp nunca envia sem confirmação.
  - **Feature tests:**
    - JUnit `ShortcutMappingTest` → cada capability gera a `VoiceAction` correta.
  - **Traces:** US-10.1, US-10.3

- [ ] **Task:** Criar o intérprete local com Gemini Nano (ML Kit GenAI, quando disponível), com a preferência local/remoto/automático, o tratamento de `needs_confirmation`/`needs_clarification` e TTS (`TextToSpeech`) no idioma do usuário.
  - **Acceptance criteria:**
    - Aparelho sem suporte cai para o remoto no modo automático.
    - Paridade de comportamento com o iOS.
  - **Feature tests:**
    - JUnit `InterpreterSelectionTest` → a escolha do intérprete é correta.
    - JUnit `VoiceResponseHandlerTest` → cada status leva ao fluxo certo.
  - **Traces:** US-10.2, US-10.3
