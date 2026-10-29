# Jarvis — Database Schema

<!-- inputs: project-description.md@sha256:560c01b59ac4 user-stories.md@sha256:3fc6ffb02e84 -->

## Overview

O modelo gira em torno de **users** e do acesso binário a **modules** (pivot `module_user`). Não há tabela de tenant: existe um **household único**, então os registros dos módulos são compartilhados por todos que têm o módulo ligado. A posse por usuário aparece só em `whatsapp_accounts`, `devices`, `social_accounts` e nas preferências de notificação; nas demais tabelas, `created_by_id` é apenas auditoria. A **central de notificações** é copiada do my-finance (`notification_types`, `notification_channels`, `notification_type_channels`, `notification_templates`, `notification_preferences`, `notifications`), acrescida dos canais `push` e `whatsapp`, com `devices` para os tokens FCM.

Os módulos se apoiam numa tabela **`recurrences` polimórfica** (`schedulable_type`/`schedulable_id`), compartilhada por `invoice_recurrences`, `whatsapp_messages` e `reminders`. Um único scheduler varre `recurrences.next_run_at`.

- **Invoices:** `payers`, `issuers`, `invoice_recurrences` (com `invoice_recurrence_recipients`), `invoices` (com snapshot de payer/issuer e `invoice_recipients`), `invoice_deliveries` e `invoice_number_sequences`, que garante o número global `YYYYMM-NNN`.
- **WhatsApp:** `whatsapp_accounts` (por usuário), `whatsapp_targets` (contatos e grupos sincronizados), `whatsapp_messages` (agendamentos), o pivot `whatsapp_message_whatsapp_target` e `whatsapp_deliveries`. `whatsapp_deliveries` é o log único do WhatsApp service: registra envios agendados, manuais, de notificação e de voz.
- **Lista de compras:** `shopping_lists` e `shopping_list_items`.
- **Lembretes:** `reminders` e `reminder_occurrences`.

Convenções aplicadas:

- **Laravel 13 / Eloquent:** tabelas no plural em snake_case, `id bigint` auto-incremento, FKs `<singular>_id`, `created_at`/`updated_at`, `deleted_at` para soft delete e pivots com os dois nomes no singular em ordem alfabética.
- **Lookup tables** no lugar de qualquer enum.
- **`friendly_id varchar(30) unique`**, como no my-finance, em toda entidade exposta em URL/API. O `id` numérico nunca sai.
- **Soft delete** em `users`, `payers`, `issuers`, `invoice_recurrences`, `whatsapp_messages`, `shopping_lists` e `reminders`.

## Schema (DBML)

```dbml
// ============================================================
// Lookup tables
// ============================================================

Table locales {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null, note: 'en, pt_BR']
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

Table modules {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null, note: 'invoices, whatsapp, shopping, reminders']
  description text [null]
  is_available_on_mobile boolean [not null, default: false]
  sort_order smallint [not null, default: 0]
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

Table social_providers {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

Table device_platforms {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table ai_providers {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null, note: 'driver name accepted by laravel/ai']
  requires_base_url boolean [not null, default: false]
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

Table notification_types {
  id bigint [pk, increment]
  slug varchar [unique, not null, note: 'key() of the notification class; label comes from the class']
  is_active boolean [not null, default: true]
  is_visible_in_profile boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

Table notification_channels {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null, note: 'Laravel channel name']
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

Table recurrence_frequencies {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table weekdays {
  id bigint [pk, increment, note: 'ISO-8601: 1 = Monday ... 7 = Sunday']
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table delivery_statuses {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table currencies {
  id bigint [pk, increment]
  name varchar [not null, note: 'English long name, translated in lang files ("American dollar")']
  slug varchar [unique, not null, note: 'ISO 4217 code']
  symbol varchar(8) [not null]
  decimal_places smallint [not null, default: 2]
  is_active boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp
}

Table invoice_statuses {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table invoice_delivery_modes {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table invoice_date_rules {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  requires_value boolean [not null, default: false]
  created_at timestamp
  updated_at timestamp
}

Table invoice_due_date_rules {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  requires_value boolean [not null, default: false]
  created_at timestamp
  updated_at timestamp
}

Table whatsapp_connection_statuses {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table whatsapp_target_types {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

Table whatsapp_delivery_sources {
  id bigint [pk, increment]
  name varchar [not null]
  slug varchar [unique, not null]
  created_at timestamp
  updated_at timestamp
}

// ============================================================
// Identity, access & profile
// ============================================================

Table users {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  name varchar [not null]
  email varchar [unique, not null]
  email_verified_at timestamp [null]
  password varchar [null, note: 'null for Google-only users (US-3.1)']
  remember_token varchar(100) [null]
  avatar_path varchar [null, note: 'jpg/png/webp, max 2MB (US-3.1)']
  locale_id bigint [ref: > locales.id, not null, note: 'default en (US-3.2)']
  timezone varchar(64) [not null, default: 'UTC', note: 'PHP identifier (US-3.2)']
  is_super_admin boolean [not null, default: false]
  whatsapp_notification_account_id bigint [ref: > whatsapp_accounts.id, null, note: 'source account for the whatsapp notification channel (US-3.3)']
  whatsapp_notification_phone varchar(20) [null, note: 'E.164 destination for the whatsapp notification channel']
  deactivated_at timestamp [null, note: 'US-2.2']
  last_seen_at timestamp [null]
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp [null]
}

Table module_user {
  id bigint [pk, increment]
  module_id bigint [ref: > modules.id, not null]
  user_id bigint [ref: > users.id, not null]
  enabled_by_id bigint [ref: > users.id, null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (module_id, user_id) [unique]
  }
}

Table invitations {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  email varchar [not null]
  token_hash varchar(64) [unique, not null, note: 'sha256 of the link token']
  locale_id bigint [ref: > locales.id, not null]
  invited_by_id bigint [ref: > users.id, not null]
  accepted_user_id bigint [ref: > users.id, null]
  expires_at timestamp [not null, note: 'created_at + 7 days (US-1.1)']
  accepted_at timestamp [null]
  revoked_at timestamp [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    email
  }
}

Table invitation_module {
  id bigint [pk, increment]
  invitation_id bigint [ref: > invitations.id, not null]
  module_id bigint [ref: > modules.id, not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (invitation_id, module_id) [unique]
  }
}

Table social_accounts {
  id bigint [pk, increment]
  user_id bigint [ref: > users.id, not null]
  social_provider_id bigint [ref: > social_providers.id, not null]
  provider_user_id varchar [not null]
  email varchar [null]
  last_used_at timestamp [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (social_provider_id, provider_user_id) [unique]
    (user_id, social_provider_id) [unique]
  }
}

Table personal_access_tokens {
  id bigint [pk, increment]
  tokenable_type varchar [not null, note: 'Sanctum morph']
  tokenable_id bigint [not null]
  name text [not null, note: 'device_name sent at login (US-1.5)']
  token varchar(64) [unique, not null]
  abilities text [null]
  last_used_at timestamp [null]
  expires_at timestamp [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (tokenable_type, tokenable_id)
  }
}

Table devices {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  user_id bigint [ref: > users.id, not null]
  personal_access_token_id bigint [ref: > personal_access_tokens.id, null, note: 'revoking the device revokes the token (US-3.4)']
  device_platform_id bigint [ref: > device_platforms.id, not null]
  name varchar [not null]
  push_token varchar(512) [unique, null, note: 'FCM registration token (iOS and Android); removed when rejected (US-5.2)']
  last_used_at timestamp [null]
  created_at timestamp
  updated_at timestamp
}

Table ai_provider_connections {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  ai_provider_id bigint [ref: > ai_providers.id, not null]
  name varchar(100) [not null]
  model varchar [not null]
  api_key text [null, note: 'encrypted cast']
  api_key_last_four varchar(4) [null]
  base_url varchar [null]
  is_active boolean [not null, default: false, note: 'at most one active (partial unique index)']
  created_by_id bigint [ref: > users.id, not null]
  created_at timestamp
  updated_at timestamp
}

// ============================================================
// Notification center (copied from my-finance + push/whatsapp)
// ============================================================

Table notification_type_channels {
  id bigint [pk, increment]
  notification_type_id bigint [ref: > notification_types.id, not null]
  notification_channel_id bigint [ref: > notification_channels.id, not null]
  is_active boolean [not null, default: true]
  is_enabled_by_default boolean [not null, default: true]
  created_at timestamp
  updated_at timestamp

  indexes {
    (notification_type_id, notification_channel_id) [unique]
  }
}

Table notification_templates {
  id bigint [pk, increment]
  notification_type_channel_id bigint [ref: > notification_type_channels.id, not null]
  locale_id bigint [ref: > locales.id, not null]
  title varchar(512) [not null, note: '{% %} template; mail subject / push title']
  body text [not null, note: '{% %} template; sanitized HTML']
  actions json [null, note: 'list of {link_key, label}']
  updated_by_id bigint [ref: > users.id, null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (notification_type_channel_id, locale_id) [unique]
  }
}

Table notification_preferences {
  id bigint [pk, increment]
  user_id bigint [ref: > users.id, not null]
  notification_type_id bigint [ref: > notification_types.id, not null]
  notification_channel_id bigint [ref: > notification_channels.id, not null]
  is_enabled boolean [not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (user_id, notification_type_id, notification_channel_id) [unique]
  }
}

Table notifications {
  id uuid [pk, note: 'Laravel database notifications']
  type varchar [not null, note: 'notification class (framework column)']
  notification_type_id bigint [ref: > notification_types.id, null]
  notifiable_type varchar [not null]
  notifiable_id bigint [not null]
  data json [not null]
  read_at timestamp [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (notifiable_type, notifiable_id, read_at, created_at)
    (notifiable_type, notifiable_id, created_at)
  }
}

// ============================================================
// Shared recurrence
// ============================================================

Table recurrences {
  id bigint [pk, increment]
  schedulable_type varchar [not null, note: 'morph map alias: invoice_recurrence | whatsapp_message | reminder']
  schedulable_id bigint [not null]
  recurrence_frequency_id bigint [ref: > recurrence_frequencies.id, not null]
  time_of_day time [null, note: 'local time in `timezone`']
  day_of_month smallint [null, note: '1-31 for monthly; clamps to last day']
  is_last_day_of_month boolean [not null, default: false]
  cron_expression varchar(100) [null, note: 'only for custom frequency; validated']
  timezone varchar(64) [not null, note: 'owner timezone at save time']
  starts_at timestamp [not null, note: 'UTC; for once = the single run']
  ends_at timestamp [null]
  next_run_at timestamp [null, note: 'UTC; null when finished']
  last_run_at timestamp [null]
  is_paused boolean [not null, default: false]
  created_at timestamp
  updated_at timestamp

  indexes {
    (schedulable_type, schedulable_id) [unique]
    (is_paused, next_run_at)
  }
}

Table recurrence_weekday {
  id bigint [pk, increment]
  recurrence_id bigint [ref: > recurrences.id, not null]
  weekday_id bigint [ref: > weekdays.id, not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (recurrence_id, weekday_id) [unique]
  }
}

// ============================================================
// Module: invoices
// ============================================================

Table payers {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  name varchar(255) [not null]
  address text [not null, note: 'multiline "Bill to" block']
  created_by_id bigint [ref: > users.id, not null]
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp [null]
}

Table issuers {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  name varchar(255) [not null]
  email varchar [not null]
  postal_code varchar(20) [not null]
  street varchar [not null, note: 'street + number']
  district varchar [not null]
  city varchar [not null]
  state varchar(50) [not null]
  created_by_id bigint [ref: > users.id, not null]
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp [null]
}

Table invoice_recurrences {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  name varchar(100) [not null]
  payer_id bigint [ref: > payers.id, not null]
  issuer_id bigint [ref: > issuers.id, not null]
  currency_id bigint [ref: > currencies.id, not null]
  amount decimal(12,2) [not null, note: '> 0']
  service_description text [not null]
  email_subject varchar(512) [not null, note: '{% %} template']
  email_body text [not null, note: '{% %} template']
  invoice_date_rule_id bigint [ref: > invoice_date_rules.id, not null]
  invoice_date_day smallint [null, note: '1-31 when rule = day_of_current_month']
  invoice_due_date_rule_id bigint [ref: > invoice_due_date_rules.id, not null]
  due_date_value smallint [null, note: 'days (0-365) or day of next month (1-31)']
  invoice_delivery_mode_id bigint [ref: > invoice_delivery_modes.id, not null]
  created_by_id bigint [ref: > users.id, not null]
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp [null]
}

Table invoice_recurrence_recipients {
  id bigint [pk, increment]
  invoice_recurrence_id bigint [ref: > invoice_recurrences.id, not null]
  email varchar [not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (invoice_recurrence_id, email) [unique]
  }
}

Table invoice_number_sequences {
  id bigint [pk, increment]
  year_month char(6) [unique, not null, note: 'YYYYMM']
  last_number integer [not null, default: 0, note: 'incremented under SELECT ... FOR UPDATE']
  created_at timestamp
  updated_at timestamp
}

Table invoices {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  invoice_recurrence_id bigint [ref: > invoice_recurrences.id, null, note: 'null for one-off invoices (US-6.7)']
  period_key varchar(32) [null, note: 'idempotency key of the recurrence run, e.g. 2026-10']
  number varchar(12) [unique, not null, note: 'YYYYMM-NNN, global']
  number_year_month char(6) [not null]
  number_sequence integer [not null]
  invoice_status_id bigint [ref: > invoice_statuses.id, not null]
  issue_date date [not null]
  due_date date [not null]
  currency_id bigint [ref: > currencies.id, not null]
  amount decimal(12,2) [not null]
  service_description text [not null]
  email_subject varchar(512) [not null, note: 'template copied from recurrence, editable in review']
  email_body text [not null]
  payer_id bigint [ref: > payers.id, null]
  payer_name varchar(255) [not null, note: 'snapshot']
  payer_address text [not null, note: 'snapshot']
  issuer_id bigint [ref: > issuers.id, null]
  issuer_name varchar(255) [not null, note: 'snapshot']
  issuer_email varchar [not null]
  issuer_postal_code varchar(20) [not null]
  issuer_street varchar [not null]
  issuer_district varchar [not null]
  issuer_city varchar [not null]
  issuer_state varchar(50) [not null]
  pdf_path varchar [null]
  sent_at timestamp [null]
  approved_by_id bigint [ref: > users.id, null, note: 'review approval (US-6.6)']
  created_by_id bigint [ref: > users.id, null, note: 'null when generated by the scheduler']
  created_at timestamp
  updated_at timestamp

  indexes {
    (invoice_recurrence_id, period_key) [unique]
    (number_year_month, number_sequence) [unique]
    invoice_status_id
  }
}

Table invoice_recipients {
  id bigint [pk, increment]
  invoice_id bigint [ref: > invoices.id, not null]
  email varchar [not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (invoice_id, email) [unique]
  }
}

Table invoice_deliveries {
  id bigint [pk, increment]
  invoice_id bigint [ref: > invoices.id, not null]
  delivery_status_id bigint [ref: > delivery_statuses.id, not null]
  triggered_by_id bigint [ref: > users.id, null, note: 'null = scheduler; set on manual send / resend']
  attempts smallint [not null, default: 0]
  error_message text [null]
  sent_at timestamp [null]
  created_at timestamp
  updated_at timestamp
}

// ============================================================
// Module: whatsapp
// ============================================================

Table whatsapp_accounts {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  user_id bigint [ref: > users.id, not null, note: 'owner; never shared (US-7.1)']
  nickname varchar(100) [not null]
  instance_name varchar(100) [unique, not null, note: 'Evolution API instance']
  phone_number varchar(20) [null, note: 'E.164, known after connection']
  whatsapp_connection_status_id bigint [ref: > whatsapp_connection_statuses.id, not null]
  connected_at timestamp [null]
  disconnected_at timestamp [null]
  last_synced_at timestamp [null]
  created_at timestamp
  updated_at timestamp
}

Table whatsapp_targets {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  whatsapp_account_id bigint [ref: > whatsapp_accounts.id, not null]
  whatsapp_target_type_id bigint [ref: > whatsapp_target_types.id, not null]
  jid varchar [not null, note: 'contact JID / group JID']
  name varchar [null]
  is_synced boolean [not null, default: true, note: 'false for numbers typed manually']
  created_at timestamp
  updated_at timestamp

  indexes {
    (whatsapp_account_id, jid) [unique]
  }
}

Table whatsapp_messages {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  user_id bigint [ref: > users.id, not null]
  whatsapp_account_id bigint [ref: > whatsapp_accounts.id, not null]
  body text [not null, note: '{% %} template, rendered as plain text']
  cancelled_at timestamp [null, note: 'once messages cancelled before run (US-7.3)']
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp [null]
}

Table whatsapp_message_whatsapp_target {
  id bigint [pk, increment]
  whatsapp_message_id bigint [ref: > whatsapp_messages.id, not null]
  whatsapp_target_id bigint [ref: > whatsapp_targets.id, not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (whatsapp_message_id, whatsapp_target_id) [unique]
  }
}

Table whatsapp_deliveries {
  id bigint [pk, increment]
  whatsapp_account_id bigint [ref: > whatsapp_accounts.id, not null]
  whatsapp_target_id bigint [ref: > whatsapp_targets.id, not null]
  whatsapp_message_id bigint [ref: > whatsapp_messages.id, null, note: 'null for send-now / notification / voice']
  whatsapp_delivery_source_id bigint [ref: > whatsapp_delivery_sources.id, not null]
  delivery_status_id bigint [ref: > delivery_statuses.id, not null]
  body text [not null, note: 'rendered text actually sent']
  scheduled_for timestamp [null, note: 'run slot; idempotency with message + target']
  attempts smallint [not null, default: 0]
  external_message_id varchar [null, note: 'Evolution API message id']
  error_message text [null]
  triggered_by_id bigint [ref: > users.id, null]
  sent_at timestamp [null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (whatsapp_message_id, whatsapp_target_id, scheduled_for) [unique]
    (whatsapp_account_id, created_at)
  }
}

// ============================================================
// Module: shopping
// ============================================================

Table shopping_lists {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  name varchar(100) [not null]
  archived_at timestamp [null]
  created_by_id bigint [ref: > users.id, not null]
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp [null]
}

Table shopping_list_items {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  shopping_list_id bigint [ref: > shopping_lists.id, not null]
  name varchar(255) [not null]
  quantity_note varchar(255) [null]
  position integer [not null, default: 0]
  checked_at timestamp [null]
  checked_by_id bigint [ref: > users.id, null]
  client_updated_at timestamp [null, note: 'last-write-wins for offline sync (US-8.2)']
  created_by_id bigint [ref: > users.id, not null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (shopping_list_id, checked_at, position)
  }
}

// ============================================================
// Module: reminders
// ============================================================

Table reminders {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  title varchar(255) [not null]
  note text [null]
  created_by_id bigint [ref: > users.id, not null, note: 'receives reminder_due (US-9.2)']
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp [null]
}

Table reminder_occurrences {
  id bigint [pk, increment]
  friendly_id varchar(30) [unique, not null]
  reminder_id bigint [ref: > reminders.id, not null]
  due_at timestamp [null, note: 'UTC; null for undated tasks']
  notified_at timestamp [null, note: 'idempotency of reminder_due']
  completed_at timestamp [null]
  completed_by_id bigint [ref: > users.id, null]
  created_at timestamp
  updated_at timestamp

  indexes {
    (reminder_id, due_at) [unique]
    (completed_at, due_at)
  }
}
```

## Relationships

- Um **user** pertence a um **locale**. Opcionalmente aponta para uma **whatsapp_account** própria como origem do canal de notificação WhatsApp.
- **users** e **modules** são many-to-many via **module_user**, cuja presença significa módulo ligado. `enabled_by_id` aponta o super admin.
- Uma **invitation** pertence ao **user** que convidou, a um **locale** e, depois de aceita, ao **user** criado. **invitations** e **modules** são many-to-many via **invitation_module**.
- Um **user** tem muitas **social_accounts**, cada uma de um **social_provider**.
- Um **user** tem muitos **personal_access_tokens** (Sanctum, morph `tokenable`) e muitos **devices**. Um **device** pertence a um **device_platform** e opcionalmente ao seu **personal_access_token**.
- Uma **ai_provider_connection** pertence a um **ai_provider** e ao **user** que a criou.
- Um **notification_type** e um **notification_channel** formam **notification_type_channels**. Cada um tem um **notification_template** por **locale**.
- Uma **notification_preference** liga **user** × **notification_type** × **notification_channel**.
- Uma **notification** pertence a um notifiable (morph, sempre **user**) e opcionalmente a um **notification_type**.
- Uma **recurrence** pertence polimorficamente (`schedulable`) a uma **invoice_recurrence**, **whatsapp_message** ou **reminder**, com no máximo uma por dono. Ela tem uma **recurrence_frequency**, e **recurrences** e **weekdays** são many-to-many via **recurrence_weekday**.
- Uma **invoice_recurrence** pertence a um **payer**, um **issuer**, uma **currency**, uma **invoice_date_rule**, uma **invoice_due_date_rule**, um **invoice_delivery_mode** e ao **user** criador. Ela tem muitos **invoice_recurrence_recipients** e muitas **invoices**.
- Uma **invoice** pertence opcionalmente a uma **invoice_recurrence**, a um **invoice_status** e a uma **currency**, e opcionalmente ao **payer**/**issuer** de origem (com snapshot). Tem muitos **invoice_recipients** e muitas **invoice_deliveries**.
- Uma **invoice_delivery** pertence a uma **invoice**, a um **delivery_status** e opcionalmente ao **user** que disparou.
- Uma **whatsapp_account** pertence a um **user** e a um **whatsapp_connection_status**, e tem muitos **whatsapp_targets**.
- Um **whatsapp_target** pertence a uma **whatsapp_account** e a um **whatsapp_target_type**.
- Uma **whatsapp_message** pertence a um **user** e a uma **whatsapp_account**. **whatsapp_messages** e **whatsapp_targets** são many-to-many via **whatsapp_message_whatsapp_target**.
- Uma **whatsapp_delivery** pertence a uma **whatsapp_account**, a um **whatsapp_target**, a uma **whatsapp_delivery_source** e a um **delivery_status**, e opcionalmente a uma **whatsapp_message** e ao **user** que disparou.
- Uma **shopping_list** tem muitos **shopping_list_items**. Os itens referenciam o **user** criador e o **user** que marcou.
- Um **reminder** tem muitas **reminder_occurrences**. Cada ocorrência referencia opcionalmente o **user** que concluiu.

## Lookup Table Seeds

- **locales** → `en` (English), `pt_BR` (Português (Brasil))
- **modules** → `invoices` (web only), `whatsapp` (mobile), `shopping` (mobile), `reminders` (mobile)
- **social_providers** → `google`
- **device_platforms** → `ios`, `android`
- **ai_providers** → os drivers suportados pelo `laravel/ai` instalado (confirmar na implementação: ex. `openai`, `anthropic`, `gemini`, `openrouter`, `ollama`)
- **notification_types** → `invoice_sent`, `invoice_failed`, `invoice_pending_review`, `whatsapp_account_disconnected`, `whatsapp_message_failed`, `reminder_due`, `invitation`, `module_enabled` (provisionados a partir das classes registradas)
- **notification_channels** → `database` (In the app), `mail` (E-mail), `push` (Push), `whatsapp` (WhatsApp)
- **recurrence_frequencies** → `once`, `daily`, `weekly`, `monthly`, `custom_cron`
- **weekdays** → `1 monday`, `2 tuesday`, `3 wednesday`, `4 thursday`, `5 friday`, `6 saturday`, `7 sunday` (o id fixo é o número ISO)
- **delivery_statuses** → `pending`, `sent`, `failed`
- **currencies** → `USD` (American dollar, $), `EUR` (Euro, €), `BRL` (Brazilian real, R$)
- **invoice_statuses** → `draft`, `pending_review`, `sent`, `failed`, `cancelled`
- **invoice_delivery_modes** → `automatic`, `requires_review`
- **invoice_date_rules** → `execution_day`, `day_of_current_month` (requires_value)
- **invoice_due_date_rules** → `days_after_issue` (requires_value), `day_of_next_month` (requires_value), `last_day_of_issue_month`
- **whatsapp_connection_statuses** → `connecting`, `open`, `closed`
- **whatsapp_target_types** → `contact`, `group`
- **whatsapp_delivery_sources** → `scheduled`, `manual`, `notification`, `voice`

## Notes & Conventions

- **Soft deletes:** `users`, `payers`, `issuers`, `invoice_recurrences`, `whatsapp_messages`, `shopping_lists` e `reminders`. Ao soft-deletar um dono de recorrência, a `recurrences` correspondente é pausada (`is_paused = true`) e o scheduler ignora donos com `deleted_at`.
- **Pivots:** `module_user`, `invitation_module`, `recurrence_weekday` e `whatsapp_message_whatsapp_target` (nome padrão do Eloquent para os models `WhatsappMessage` × `WhatsappTarget`).
- **`friendly_id varchar(30) unique`** existe em toda entidade exposta por URL/API. O `id` numérico fica interno. As lookups e os pivots não têm `friendly_id`.
- **Morphs:** `recurrences.schedulable_type`, `notifications.notifiable_type` e `personal_access_tokens.tokenable_type` são colunas de framework, não enums de domínio. Usar `Relation::enforceMorphMap` com aliases curtos (`invoice_recurrence`, `whatsapp_message`, `reminder`, `user`).
- **Status derivado por timestamps, não por lookup:**
  - convite `pending`/`accepted`/`expired`/`revoked` vem de `accepted_at`, `revoked_at` e `expires_at`;
  - usuário ativo/desativado vem de `deactivated_at`;
  - recorrência pausada vem de `is_paused`;
  - mensagem `once` cancelada vem de `cancelled_at`;
  - item marcado vem de `checked_at`;
  - lista arquivada vem de `archived_at`;
  - ocorrência concluída ou atrasada vem de `completed_at` e `due_at`.
- **"Módulo ligado" é a presença da linha em `module_user`.** O super admin não precisa de linhas, porque `is_super_admin` concede tudo. Desligar apaga a linha, não os dados (US-2.1).
- **Numeração de invoice (US-6.5):** dentro de uma transação, faça `SELECT ... FOR UPDATE` em `invoice_number_sequences` do `year_month`, depois `last_number + 1` e grave em `invoices.number`. Os uniques em `number` e em `(number_year_month, number_sequence)` são a rede de segurança. Um número de invoice `cancelled` nunca é reutilizado.
- **Idempotência:**
  - `invoices (invoice_recurrence_id, period_key)` unique;
  - `whatsapp_deliveries (whatsapp_message_id, whatsapp_target_id, scheduled_for)` unique;
  - `reminder_occurrences (reminder_id, due_at)` unique, com `notified_at` para não notificar duas vezes.

  No PostgreSQL, os uniques com colunas nulas não colidem, então envios manuais (`period_key`/`whatsapp_message_id` null) ficam livres, como pretendido.
- **Snapshot:** a invoice copia os campos de payer e issuer. Editar ou excluir o cadastro não altera PDFs já gerados (US-6.1, US-6.2).
- **Limite 1–10 destinatários** (US-6.3) é validado na Action, não no banco.
- **`ai_provider_connections`:** no máximo uma ativa, via índice único parcial PostgreSQL `UNIQUE (is_active) WHERE is_active`. `api_key` usa o cast `encrypted`.
- **Índices úteis:**
  - `recurrences (is_paused, next_run_at)` para o scheduler;
  - `notifications (notifiable_*, read_at, created_at)` para o sininho;
  - `shopping_list_items (shopping_list_id, checked_at, position)` para a ordem "marcados no fim";
  - `reminder_occurrences (completed_at, due_at)` para os filtros pendentes/atrasados;
  - `whatsapp_deliveries (whatsapp_account_id, created_at)` para o histórico.
- **Tabelas de framework não listadas:** `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`. Vêm das migrations padrão do Laravel; o Horizon usa Redis.
- **Conceitos não persistidos:**
  - **Household:** não há tabela de tenant. O household é implícito (instância única), e o escopo vem só de `module_user`.
  - **Action:** é padrão de código, não dado.
  - **Template (motor `{% %}`):** não tem tabela própria. Os templates são colunas texto nos donos (`notification_templates.title/body`, `invoice_recurrences.email_subject/email_body`, `invoices.email_*`, `whatsapp_messages.body`), e o schema de variáveis vive no código de cada contexto.
  - **WhatsApp service:** é código. O efeito persistido é `whatsapp_deliveries`.
  - **Voice command:** o texto do comando não é persistido no MVP. Só o efeito (item, lembrete, delivery com source `voice`) é gravado.
  - **Payloads de webhook da Evolution API:** não são persistidos. Atualizam `whatsapp_accounts` e `whatsapp_deliveries`.
- **Conceito super admin:** é `users.is_super_admin` (boolean). Não existe tabela de roles, porque o acesso é binário.

## Open Questions

- **Lista de `ai_providers`:** depende dos drivers do `laravel/ai` na versão que for instalada. Confirmar com `composer show laravel/ai` na fase de implementação.
