# Jarvis — User Stories

<!-- inputs: project-description.md@sha256:560c01b59ac4 -->

## Overview

Jarvis é uma plataforma pessoal e familiar de automação do dia a dia. Tem uma versão **web** (Laravel + Livewire + TallStackUI), uma **API REST v1** (Sanctum) e **apps nativos iOS (Swift) e Android (Kotlin)** num monorepo. Os dados ficam num **household único**: quem tem um módulo ligado vê e opera tudo dentro dele. A exceção são as contas de WhatsApp e os dispositivos de push, que são por usuário.

O MVP entrega a fundação (convites, login Google e senha, módulos por usuário, perfil, motor de templates `{% %}`, central de notificações com in-app, email, push e WhatsApp, Reverb, Horizon e deploy Dokploy) e quatro módulos: **Invoices**, **WhatsApp**, **Lista de compras** e **Lembretes**. Os apps cobrem o **uso diário**: login, notificações, push, listas, lembretes, mensagens de WhatsApp e voz. Admin, invoices, conexão de conta WhatsApp e edição de templates de sistema ficam **só na web**.

**User Types:**
- **Super admin** - O Paulo. Convida usuários, liga e desliga módulos por usuário, gerencia templates e tipos de notificação e configura o provedor de IA. Tem todos os módulos.
- **Membro da família** - Usuário convidado. Usa apenas os módulos ligados para ele, pela web e pelos apps.
- **Destinatário externo** - Quem recebe invoices por email ou mensagens por WhatsApp. Não tem conta e não acessa o sistema.

---

## 1. Acesso e contas

### US-1.1: Convidar um familiar
**As a** super admin
**I want to** convidar alguém por email escolhendo os módulos iniciais
**So that** só pessoas da família consigam criar conta

**Acceptance Criteria:**
- [ ] O formulário de convite exige um email válido e permite marcar 0+ módulos.
- [ ] O convite gera um token único válido por **7 dias** e envia um email com o link de aceite no idioma escolhido no convite (`en` padrão).
- [ ] Convidar um email que já tem conta retorna erro de validação "user already exists".
- [ ] Reenviar um convite pendente invalida o token anterior e gera um novo.
- [ ] O super admin vê a lista de convites com status `pending`, `accepted` ou `expired` e pode revogar os pendentes.
- [ ] Apenas o super admin acessa a tela de convites; os demais recebem 403.

**Expected Result:** O familiar recebe o email com o link e o convite aparece como `pending`.

---

### US-1.2: Aceitar convite com senha
**As a** membro da família
**I want to** aceitar o convite definindo nome e senha
**So that** eu tenha acesso ao Jarvis

**Acceptance Criteria:**
- [ ] O link válido abre o formulário com o email pré-preenchido e bloqueado.
- [ ] A senha tem no mínimo 8 caracteres e precisa ser confirmada.
- [ ] Ao aceitar, a conta é criada, os módulos do convite são ligados, o convite vira `accepted` e o usuário já fica logado.
- [ ] Token expirado, revogado ou já usado mostra uma página de erro, sem criar conta.

**Expected Result:** O usuário é criado, autenticado e cai no dashboard com os módulos do convite.

---

### US-1.3: Entrar com Google
**As a** membro da família
**I want to** entrar com minha conta Google
**So that** eu não precise de senha

**Acceptance Criteria:**
- [ ] O botão "Continue with Google" aparece no login e no aceite de convite.
- [ ] Se o email do Google já tem conta, o login é feito e a conta social é vinculada na primeira vez.
- [ ] Se o email do Google tem convite `pending` válido, a conta é criada com os módulos do convite e o convite vira `accepted`.
- [ ] Se o email do Google não tem conta nem convite, o acesso é recusado com a mensagem "invitation required" e nenhuma conta é criada.
- [ ] Ao aceitar convite via Google, um email diferente do convidado é recusado.

**Expected Result:** Só emails convidados ou já cadastrados conseguem entrar com Google.

---

### US-1.4: Login, logout e recuperação de senha na web
**As a** membro da família
**I want to** entrar com email e senha, sair e recuperar a senha
**So that** eu controle minha sessão

**Acceptance Criteria:**
- [ ] O login com credenciais inválidas mostra um erro genérico, sem revelar se o email existe.
- [ ] O login tem rate limit de 5 tentativas por minuto por email + IP.
- [ ] O link de recuperação de senha vale **60 minutos** e só pode ser usado uma vez.
- [ ] O logout invalida a sessão e redireciona para o login.

**Expected Result:** O usuário gerencia o acesso web com segurança básica.

---

### US-1.5: Login nos apps com token por dispositivo
**As a** membro da família
**I want to** entrar no app iOS/Android com Google ou senha
**So that** eu use o Jarvis pelo celular

**Acceptance Criteria:**
- [ ] `POST /api/v1/auth/login` (email + senha + `device_name`) retorna um token Sanctum. Credenciais inválidas retornam 422.
- [ ] `POST /api/v1/auth/google` recebe o ID token do Google nativo, valida-o no backend e aplica as mesmas regras de convite da US-1.3.
- [ ] Cada login cria um token nomeado pelo dispositivo, e `POST /api/v1/auth/logout` revoga apenas o token atual.
- [ ] `GET /api/v1/me` retorna usuário, idioma, fuso e a lista de slugs dos módulos ligados.
- [ ] Chamadas sem token ou com token revogado retornam 401.

**Expected Result:** O app autentica, sabe quais módulos mostrar e cada dispositivo pode ser revogado individualmente.

---

## 2. Administração

### US-2.1: Ligar e desligar módulos por usuário
**As a** super admin
**I want to** ver a matriz usuário × módulo e ligar ou desligar cada célula
**So that** cada familiar acesse só o que precisa

**Acceptance Criteria:**
- [ ] A matriz lista todos os usuários (exceto o super admin, que tem tudo) × módulos `invoices`, `whatsapp`, `shopping` e `reminders`.
- [ ] Alternar uma célula persiste na hora e vale na próxima requisição do usuário.
- [ ] Rotas web de módulo desligado retornam 403, e endpoints `/api/v1/<module>/*` também retornam 403.
- [ ] O menu web e o `GET /api/v1/me` refletem os módulos ligados.
- [ ] Ligar um módulo dispara a notificação "módulo liberado" para o usuário (US-5.4).
- [ ] Desligar um módulo não apaga dados; religar devolve o acesso aos mesmos dados.

**Expected Result:** O acesso por módulo é binário e efetivo na web, na API e nos apps.

---

### US-2.2: Gerenciar usuários
**As a** super admin
**I want to** listar, desativar e reativar usuários
**So that** eu tire o acesso de alguém sem apagar dados do household

**Acceptance Criteria:**
- [ ] A lista mostra nome, email, último acesso e módulos ligados.
- [ ] Um usuário desativado não consegue logar (web, Google ou API) e tem todos os tokens Sanctum revogados.
- [ ] Um usuário desativado tem suas recorrências de WhatsApp pausadas, porque usam as contas dele.
- [ ] O super admin não pode desativar a si mesmo.

**Expected Result:** O super admin controla quem está ativo no sistema.

---

### US-2.3: Configurar o provedor de IA
**As a** super admin
**I want to** cadastrar provedor, modelo e chave de API do `laravel/ai`
**So that** os comandos de voz remotos funcionem com o provedor que eu escolher

**Acceptance Criteria:**
- [ ] O cadastro aceita provedores suportados pelo `laravel/ai` instalado, com modelo e chave de API.
- [ ] A chave é guardada criptografada e nunca é exibida de volta inteira (só os últimos 4 caracteres).
- [ ] O botão "testar conexão" faz uma chamada mínima e mostra sucesso ou erro.
- [ ] Apenas uma conexão fica ativa por vez. Sem conexão ativa, o modo remoto de voz retorna erro "AI provider not configured".

**Expected Result:** A IA remota usa a conexão ativa configurada pelo super admin.

---

## 3. Perfil e preferências

### US-3.1: Editar perfil
**As a** membro da família
**I want to** editar nome, avatar e senha e ver minhas contas sociais
**So that** meus dados fiquem corretos

**Acceptance Criteria:**
- [ ] O avatar aceita JPG, PNG ou WebP de até 2MB.
- [ ] Trocar a senha exige a senha atual. Usuário só com Google pode definir uma senha.
- [ ] A seção "Connected accounts" mostra o Google vinculado e permite desvincular só se existir senha definida.

**Expected Result:** O perfil é atualizado sem perder nenhuma forma de acesso.

---

### US-3.2: Idioma e fuso horário
**As a** membro da família
**I want to** escolher idioma (`en` / `pt_BR`) e fuso horário
**So that** a interface, as datas e as recorrências sigam meu contexto

**Acceptance Criteria:**
- [ ] O idioma aceita apenas `en` e `pt_BR`; o padrão de um usuário novo é `en`.
- [ ] O fuso aceita apenas identificadores PHP válidos (`timezone:all`); valor inválido dá erro de validação.
- [ ] Datas são guardadas em UTC e exibidas no fuso do usuário.
- [ ] A mudança vale na web e na API (`PATCH /api/v1/me/preferences`), e as notificações seguintes saem no novo idioma.

**Expected Result:** Toda a experiência do usuário respeita o idioma e o fuso escolhidos.

---

### US-3.3: Preferências de notificação
**As a** membro da família
**I want to** ligar e desligar cada tipo de notificação por canal (in-app, email, push, WhatsApp)
**So that** eu receba só o que importa onde importa

**Acceptance Criteria:**
- [ ] A matriz mostra apenas os tipos visíveis no perfil e os canais ativos na plataforma.
- [ ] Sem escolha explícita, vale o default do tipo × canal.
- [ ] Para o canal WhatsApp, o usuário escolhe a conta de origem (uma das suas) e o número de destino. Sem isso, o canal fica indisponível na matriz.
- [ ] A mudança vale a partir da próxima notificação despachada.

**Expected Result:** O gate da central respeita as preferências do usuário.

---

### US-3.4: Gerenciar dispositivos
**As a** membro da família
**I want to** ver e revogar os dispositivos com push e sessão de app
**So that** um celular perdido não receba mais nada

**Acceptance Criteria:**
- [ ] A lista mostra nome do dispositivo, plataforma (iOS/Android) e último uso.
- [ ] Revogar remove o push token e revoga o token Sanctum associado. O app nesse dispositivo recebe 401 na próxima chamada.

**Expected Result:** Dispositivos revogados param de receber push e perdem acesso à API.

---

## 4. Templates

### US-4.1: Escrever templates com variáveis e condições
**As a** membro da família
**I want to** usar `{% variavel %}`, `{% if %}` e `{% for %}` em mensagens e emails
**So that** os textos mudem sozinhos a cada envio

**Acceptance Criteria:**
- [ ] Suporta variável (`{% current_month_name %}`), caminho (`{% invoice.number %}`), `{% if x %}…{% else %}…{% endif %}`, comparação `==` e `!=` com string entre aspas, e `{% for item in items %}…{% endfor %}`.
- [ ] Loops com mais de 500 iterações são truncados em 500.
- [ ] `{{ }}` é tratado como texto literal.
- [ ] Ao salvar, tag não fechada ou variável fora do schema do contexto dá erro de validação, com posição do erro.
- [ ] O editor lista as variáveis disponíveis no contexto (invoice, WhatsApp, notificação), e clicar numa delas insere a tag.
- [ ] O motor é o mesmo para notificações, WhatsApp e email de invoice (uma única implementação).

**Expected Result:** Templates inválidos nunca são salvos e os válidos renderizam com os valores do contexto.

---

### US-4.2: Pré-visualizar template
**As a** membro da família
**I want to** ver o template renderizado com dados de exemplo
**So that** eu confira o texto antes de agendar

**Acceptance Criteria:**
- [ ] O preview renderiza no idioma e fuso do usuário, com datas e valores já formatados.
- [ ] Corpo HTML (email e notificação) sai sanitizado: só a formatação leve é mantida, sem script nem atributos de evento.
- [ ] Títulos e mensagens de WhatsApp saem em texto puro.
- [ ] Valores interpolados são escapados.

**Expected Result:** O usuário vê exatamente o que o destinatário vai receber.

---

## 5. Notificações

### US-5.1: Receber notificações in-app em tempo real
**As a** membro da família
**I want to** ver um sininho com contador e a lista de notificações
**So that** eu saiba o que aconteceu sem recarregar a página

**Acceptance Criteria:**
- [ ] Uma notificação nova aparece no sininho via Reverb, sem refresh, em até 5s após o despacho.
- [ ] Dá para marcar uma ou todas como lidas, e o contador atualiza.
- [ ] As ações da notificação (links) levam à tela correspondente.
- [ ] `GET /api/v1/notifications` (paginado) e `POST /api/v1/notifications/{id}/read` atendem os apps.

**Expected Result:** O usuário acompanha notificações em tempo real na web e no app.

---

### US-5.2: Receber push no celular
**As a** membro da família
**I want to** receber push no iOS e no Android
**So that** eu seja avisado mesmo com o app fechado

**Acceptance Criteria:**
- [ ] O app registra o push token (FCM) em `POST /api/v1/devices` após login e permissão concedida.
- [ ] O canal `push` envia para todos os dispositivos ativos do usuário.
- [ ] Um token rejeitado pelo FCM como inválido é removido automaticamente.
- [ ] Tocar no push abre a tela relacionada no app (deep link).

**Expected Result:** Push chega a todos os dispositivos válidos e leva ao contexto certo.

---

### US-5.3: Entrega por canal com fila e retry
**As a** membro da família
**I want to** que cada canal seja entregue de forma independente
**So that** uma falha de email não impeça o push ou o in-app

**Acceptance Criteria:**
- [ ] Cada canal × destinatário vira um job na fila `notifications` do Horizon.
- [ ] Cada job tem 3 tentativas com backoff de 30s e 120s.
- [ ] O gate é checado no despacho e de novo no worker: tipo ativo, canal ativo, template no idioma (fallback `en`) e preferência do usuário.
- [ ] O canal `whatsapp` usa o WhatsApp service (US-7.4).

**Expected Result:** Notificações chegam por todos os canais permitidos, com isolamento de falhas.

---

### US-5.4: Tipos de notificação do MVP
**As a** membro da família
**I want to** ser avisado dos eventos importantes dos meus módulos
**So that** eu não precise ficar checando

**Acceptance Criteria:**
- [ ] Existem e estão registrados os tipos `invoice_sent`, `invoice_failed`, `invoice_pending_review`, `whatsapp_account_disconnected`, `whatsapp_message_failed`, `reminder_due`, `invitation` (email apenas) e `module_enabled`.
- [ ] Cada tipo vem com template default em `en` e `pt_BR` para cada canal suportado.
- [ ] Tipos de módulo só são enviados a usuários com o módulo ligado.

**Expected Result:** Os eventos do MVP geram notificações com textos default nos dois idiomas.

---

### US-5.5: Administrar tipos, canais e templates de notificação
**As a** super admin
**I want to** editar título, corpo e ações de cada tipo × canal × idioma e ligar ou desligar tipos e canais
**So that** eu controle a comunicação do sistema

**Acceptance Criteria:**
- [ ] O editor usa o motor de templates com o schema de variáveis do tipo e preview (US-4.1, US-4.2).
- [ ] Desligar um canal na plataforma o remove da matriz de preferências de todos os usuários.
- [ ] Desligar um tipo impede novos despachos desse tipo.
- [ ] O sistema registra o último editor (`updated_by`) de cada template.
- [ ] Um template editado nunca é sobrescrito pelos defaults no reprovisionamento.

**Expected Result:** O super admin muda a comunicação sem deploy.

---

## 6. Invoices (web)

### US-6.1: Cadastrar payers
**As a** membro da família
**I want to** cadastrar quem será cobrado (nome e endereço)
**So that** eu reutilize o payer nas invoices

**Acceptance Criteria:**
- [ ] O nome é obrigatório (até 255 caracteres) e o endereço é texto multilinha obrigatório.
- [ ] Um payer usado por recorrência ativa não pode ser excluído (erro explicando o motivo).
- [ ] Editar um payer não altera os PDFs de invoices já geradas, porque a invoice guarda um snapshot dos dados.

**Expected Result:** Payers ficam disponíveis para recorrências e invoices avulsas.

---

### US-6.2: Cadastrar issuers
**As a** membro da família
**I want to** cadastrar um ou mais cobradores
**So that** eu emita invoices em nome de quem estiver cobrando

**Acceptance Criteria:**
- [ ] Campos: nome/razão social, email, CEP, rua e número, bairro e cidade/UF, todos obrigatórios.
- [ ] Permite N issuers.
- [ ] Mesmas regras de exclusão e snapshot do US-6.1.

**Expected Result:** Issuers ficam disponíveis com os dados que aparecem em "Receiver information".

---

### US-6.3: Configurar recorrência de invoice
**As a** membro da família
**I want to** criar uma recorrência com payer, issuer, destinatários, email, descrição, datas dinâmicas, moeda, valor e frequência
**So that** a invoice mensal saia sozinha

**Acceptance Criteria:**
- [ ] Destinatários: 1 a 10 emails válidos.
- [ ] Assunto e corpo são templates validados com o schema de invoice: `current_month_name`, `current_year`, `invoice.number`, `invoice.date`, `invoice.due_date`, `invoice.amount`, `invoice.currency`, `payer.name` e `issuer.name`.
- [ ] Regra de emissão: "dia da execução" ou "dia X do mês corrente" (1–31; se o mês não tiver o dia, usa o último).
- [ ] Regra de vencimento: "emissão + N dias" (0–365), "dia X do mês seguinte" ou "último dia do mês da emissão".
- [ ] Moeda é um código ISO 4217 da lista suportada (USD, EUR, BRL…), exibida por extenso no PDF (ex.: "American dollar"). O valor é > 0 com 2 casas.
- [ ] A frequência usa a recorrência compartilhada (US-12.1).
- [ ] O modo é `automatic` ou `requires_review`.
- [ ] O status da recorrência é `active` ou `paused`, e a tela mostra a data da próxima execução.

**Expected Result:** A recorrência ativa fica agendada com a próxima execução calculada no fuso do usuário.

---

### US-6.4: Pré-visualizar invoice e email da próxima execução
**As a** membro da família
**I want to** ver o PDF e o email que serão gerados na próxima execução
**So that** eu confirme datas e valores antes de ativar

**Acceptance Criteria:**
- [ ] O preview calcula as datas pelas regras para a próxima execução e usa um número provisório marcado como "preview", sem consumir a sequência.
- [ ] O PDF segue o layout da invoice de exemplo: título "Invoice", Receiver information, Bill to, Invoice number/Date/Due date, Description, moeda por extenso e valor `2,000.00`.
- [ ] Datas no PDF no formato `dd/mm/yyyy`.

**Expected Result:** O usuário vê a invoice idêntica à que será enviada.

---

### US-6.5: Gerar e enviar automaticamente pela recorrência
**As a** membro da família
**I want to** que o sistema gere o PDF e envie aos destinatários na frequência configurada
**So that** eu não precise lembrar de emitir a invoice todo mês

**Acceptance Criteria:**
- [ ] O scheduler encontra as recorrências `active` vencidas e despacha um job na fila `invoices`.
- [ ] O job calcula as datas, reserva o próximo número global `YYYYMM-NNN` com lock (sem duplicar em concorrência), gera o PDF e grava a invoice com snapshot de payer e issuer.
- [ ] Modo `automatic`: envia o email com o PDF anexo para todos os destinatários, marca a invoice como `sent` e notifica `invoice_sent`.
- [ ] A mesma recorrência nunca gera duas invoices para o mesmo período (idempotência recorrência × período).
- [ ] Uma falha de envio após 3 tentativas marca `failed` e notifica `invoice_failed`.

**Expected Result:** No dia configurado a invoice sai sozinha e o usuário é avisado.

---

### US-6.6: Revisar invoice antes do envio
**As a** membro da família
**I want to** que recorrências em modo revisão parem para minha aprovação
**So that** eu confira antes de mandar ao cliente

**Acceptance Criteria:**
- [ ] No modo `requires_review`, a invoice gerada fica `pending_review` e notifica `invoice_pending_review`.
- [ ] Na revisão dá para editar descrição, datas, valor e destinatários, e o PDF é regenerado ao salvar.
- [ ] "Aprovar e enviar" envia e marca como `sent`. "Descartar" marca como `cancelled`, e o número não é reutilizado.

**Expected Result:** Nenhuma invoice em modo revisão sai sem aprovação.

---

### US-6.7: Envio manual e reenvio
**As a** membro da família
**I want to** gerar e enviar uma invoice agora, ou reenviar uma existente
**So that** eu resolva casos fora do calendário

**Acceptance Criteria:**
- [ ] "Gerar e enviar agora" numa recorrência cria uma invoice com as regras aplicadas à data atual, sem alterar a próxima execução agendada.
- [ ] É possível criar uma invoice avulsa (sem recorrência) preenchendo os mesmos campos.
- [ ] "Reenviar" numa invoice `sent` ou `failed` envia o mesmo PDF e o mesmo número novamente.

**Expected Result:** O usuário emite ou reenvia invoices sob demanda.

---

### US-6.8: Histórico de invoices
**As a** membro da família
**I want to** listar invoices com status e baixar o PDF
**So that** eu tenha o registro do que foi cobrado

**Acceptance Criteria:**
- [ ] Lista paginada com número, payer, data, vencimento, moeda, valor e status (`pending_review`, `sent`, `failed`, `cancelled`).
- [ ] Filtros por status, payer e mês.
- [ ] O download do PDF só é permitido a usuários com o módulo `invoices`.

**Expected Result:** O histórico completo fica acessível na web.

---

## 7. WhatsApp

### US-7.1: Conectar conta de WhatsApp (web)
**As a** membro da família
**I want to** conectar um ou mais números via QR code
**So that** eu possa enviar mensagens pelo Jarvis

**Acceptance Criteria:**
- [ ] "Conectar número" cria uma instância na Evolution API e exibe o QR code, atualizado em tempo real via Reverb.
- [ ] O webhook `open` marca a conta como conectada e inicia a sincronização de contatos e grupos.
- [ ] O QR expirado é renovado automaticamente enquanto a tela estiver aberta.
- [ ] O usuário pode ter N contas e dar um apelido a cada uma.
- [ ] Contas são por usuário: outros usuários não as veem nem as usam (403 na API).

**Expected Result:** O número fica conectado e listado só para o dono.

---

### US-7.2: Acompanhar e remover contas
**As a** membro da família
**I want to** ver o status das minhas contas e remover uma
**So that** eu saiba se posso enviar mensagens

**Acceptance Criteria:**
- [ ] O status (`connecting`, `open`, `closed`) atualiza em tempo real via webhook + Reverb.
- [ ] A transição para `closed` notifica `whatsapp_account_disconnected`.
- [ ] Remover a conta faz logout e apaga a instância na Evolution API e pausa as mensagens agendadas que a usam.
- [ ] O webhook `/api/v1/webhooks/evolution` rejeita requisições sem o segredo compartilhado (401).

**Expected Result:** O usuário sabe o estado de cada número e pode desconectá-lo.

---

### US-7.3: Agendar mensagem única ou recorrente
**As a** membro da família
**I want to** agendar uma mensagem para contatos ou grupos, uma vez ou de forma recorrente
**So that** eu automatize avisos e lembretes da família

**Acceptance Criteria:**
- [ ] Os campos são conta de origem (só as do usuário com status `open`), 1+ targets (contatos e/ou grupos sincronizados, ou número digitado em formato E.164), corpo (template validado) e tipo `once` (data/hora futura) ou `recurring` (US-12.1).
- [ ] Data/hora no passado dá erro de validação.
- [ ] Uma mensagem `once` pode ser cancelada antes do horário.
- [ ] Uma mensagem `recurring` pode ser pausada e retomada.
- [ ] Disponível na web e nos apps (`/api/v1/whatsapp/messages`).

**Expected Result:** A mensagem fica agendada com a próxima execução visível.

---

### US-7.4: Envio pelo WhatsApp service genérico
**As a** membro da família
**I want to** que os envios sejam feitos por um serviço único reutilizável
**So that** mensagens agendadas, notificações e comandos de voz se comportem igual

**Acceptance Criteria:**
- [ ] Uma única Action/serviço `SendWhatsAppMessage(account, target, body)` é usada pelo módulo WhatsApp, pelo canal de notificação e pela voz.
- [ ] Os envios correm na fila `whatsapp`, um job por target, com status `sent`/`failed` por target.
- [ ] Cada job tem 3 tentativas com backoff.
- [ ] Falha definitiva notifica `whatsapp_message_failed`.
- [ ] Conta `closed` falha imediatamente com erro claro, sem chamar a Evolution API.

**Expected Result:** Qualquer módulo envia WhatsApp pelo mesmo caminho, com rastreio por destinatário.

---

### US-7.5: Enviar agora e ver histórico
**As a** membro da família
**I want to** enviar uma mensagem na hora e ver o histórico de envios
**So that** eu confirme o que foi entregue

**Acceptance Criteria:**
- [ ] "Enviar agora" dispara pelo WhatsApp service sem criar agendamento.
- [ ] O histórico lista data, conta, target, trecho da mensagem e status, paginado, só das contas do usuário.

**Expected Result:** O usuário tem envio imediato e rastreabilidade.

---

## 8. Lista de compras

### US-8.1: Gerenciar listas e itens
**As a** membro da família
**I want to** criar listas de compras e adicionar itens
**So that** a casa toda saiba o que comprar

**Acceptance Criteria:**
- [ ] A lista tem nome obrigatório (até 100 caracteres).
- [ ] O item tem nome obrigatório e quantidade/observação opcional.
- [ ] Listas e itens são compartilhados no household (todos com o módulo `shopping`).
- [ ] Editar e excluir itens. Arquivar lista (sai da tela principal, mas consultável).
- [ ] Disponível na web e nos apps (`/api/v1/shopping/lists`).

**Expected Result:** As listas da casa ficam acessíveis em qualquer dispositivo.

---

### US-8.2: Marcar itens no mercado em tempo real
**As a** membro da família
**I want to** marcar itens como pegos e ver as marcações dos outros na hora
**So that** duas pessoas no mercado não comprem a mesma coisa

**Acceptance Criteria:**
- [ ] Marcar e desmarcar persiste e é transmitido via Reverb para todos com a lista aberta (web e app) em até 2s.
- [ ] Itens marcados vão para o fim da lista.
- [ ] "Limpar marcados" remove todos os itens marcados da lista.
- [ ] No app, a marcação feita offline é sincronizada ao reconectar; em conflito, vale a última escrita.

**Expected Result:** A lista fica consistente entre todos os celulares em tempo real.

---

## 9. Lembretes e tarefas

### US-9.1: Criar e concluir lembretes/tarefas
**As a** membro da família
**I want to** criar lembretes e tarefas e marcá-los como concluídos
**So that** nada da casa seja esquecido

**Acceptance Criteria:**
- [ ] Título obrigatório, nota opcional, data/hora opcional e recorrência opcional (US-12.1).
- [ ] Os filtros são "pendentes", "atrasados" (data/hora passada, não concluídos) e "concluídos".
- [ ] Concluir registra quem concluiu e quando. É possível reabrir.
- [ ] Os lembretes são compartilhados no household.
- [ ] Disponível na web e nos apps (`/api/v1/reminders`).

**Expected Result:** Tarefas e lembretes da casa ficam centralizados e rastreáveis.

---

### US-9.2: Ser avisado na hora do lembrete
**As a** membro da família
**I want to** receber push e WhatsApp quando o lembrete vencer
**So that** eu seja lembrado mesmo sem abrir o app

**Acceptance Criteria:**
- [ ] O scheduler verifica a cada minuto e dispara `reminder_due` para o criador do lembrete, em até 1 minuto após a data/hora.
- [ ] O canal segue as preferências (US-3.3): push e/ou WhatsApp e in-app.
- [ ] Cada ocorrência notifica uma única vez (idempotente).
- [ ] Em lembrete recorrente, concluir ou passar do horário gera a próxima ocorrência pela regra.
- [ ] Um lembrete concluído antes do horário não notifica.

**Expected Result:** O usuário é avisado exatamente uma vez por ocorrência.

---

## 10. Comandos de voz

### US-10.1: Acionar o Jarvis por voz
**As a** membro da família
**I want to** falar um comando pelo botão de microfone, pela Siri (App Intent) ou pelo Assistant (App Actions)
**So that** eu use o Jarvis sem digitar

**Acceptance Criteria:**
- [ ] O app tem um botão de microfone que usa o speech-to-text nativo (Speech no iOS, SpeechRecognizer no Android).
- [ ] O iOS expõe App Intents acionáveis por "Siri, …" para os comandos do MVP.
- [ ] O Android expõe App Actions equivalentes.
- [ ] Sem permissão de microfone, o app explica como conceder e não quebra.

**Expected Result:** O usuário dispara comandos falando, por qualquer gatilho suportado.

---

### US-10.2: Interpretar comando local ou remoto
**As a** membro da família
**I want to** escolher se o comando é interpretado pela IA do celular ou pela IA remota
**So that** eu equilibre privacidade, custo e precisão

**Acceptance Criteria:**
- [ ] A preferência do app é "local", "remoto" ou "automático" (local se disponível, senão remoto).
- [ ] O modo local usa Apple Foundation Models / Gemini Nano e produz a mesma estrutura de ação.
- [ ] O modo remoto chama `POST /api/v1/voice/commands` com `{ "text": "..." }`, e um agente `laravel/ai` com tools mapeia para as Actions.
- [ ] Os dois modos executam a mesma Action da API, e a voz nunca chama lógica paralela.
- [ ] Remoto sem provedor configurado (US-2.3) retorna erro amigável.

**Expected Result:** O mesmo comando produz o mesmo efeito nos dois modos.

---

### US-10.3: Comandos de voz do MVP
**As a** membro da família
**I want to** adicionar e marcar itens de lista, criar e concluir lembretes e enviar WhatsApp por voz
**So that** as tarefas rotineiras saiam mais rápido

**Acceptance Criteria:**
- [ ] "Adicione leite na lista de compras dessa semana" resolve a lista pelo nome (fuzzy) e adiciona o item.
- [ ] Também funcionam marcar item, "criar lembrete X amanhã às 9h" e "concluir lembrete X".
- [ ] "Envie a mensagem X para o contato/grupo Y" resolve o target entre os contatos e grupos sincronizados das contas do usuário.
- [ ] Envio de WhatsApp **sempre** pede confirmação explícita antes de enviar.
- [ ] Com ambiguidade (2+ listas, contatos ou lembretes candidatos), o app pergunta qual antes de executar.
- [ ] Comandos de módulos desligados para o usuário são recusados com mensagem clara.
- [ ] A resposta é mostrada e falada (TTS) no idioma do usuário.

**Expected Result:** Os comandos do MVP funcionam ponta a ponta com confirmação nos casos sensíveis.

---

## 11. Apps nativos

### US-11.1: Navegação do app por módulos
**As a** membro da família
**I want to** ver no app só as abas dos módulos que tenho
**So that** o app reflita meu acesso

**Acceptance Criteria:**
- [ ] O app monta as abas a partir de `GET /api/v1/me` com os módulos de uso diário (shopping, reminders, whatsapp) e as notificações.
- [ ] Um módulo desligado some na próxima abertura ou refresh. Uma chamada que retorna 403 leva a uma tela de "sem acesso".
- [ ] O visual segue o padrão iOS 27 (Liquid Glass refinado) no iOS; no Android, Material 3 (Material You).
- [ ] Idioma do app segue a preferência do usuário (`en` / `pt_BR`).
- [ ] Invoices, admin, conexão de conta WhatsApp e templates de sistema não existem nos apps.

**Expected Result:** O app mostra apenas o uso diário permitido ao usuário.

---

## 12. Plataforma

### US-12.1: Recorrência compartilhada
**As a** membro da família
**I want to** configurar repetições do mesmo jeito em invoices, WhatsApp e lembretes
**So that** eu aprenda uma vez e use em todo lugar

**Acceptance Criteria:**
- [ ] Os presets são diário, semanal (1+ dias da semana), mensal (dia N, ou último dia se o mês for menor) e cron customizado (expressão validada).
- [ ] A recorrência guarda horário, fuso (o do dono), início e fim opcionais.
- [ ] A próxima execução é calculada no fuso do dono e guardada em UTC.
- [ ] Execuções perdidas por indisponibilidade rodam uma única vez na retomada, sem acumular N disparos.

**Expected Result:** Um único componente e um único cálculo de recorrência atendem os três módulos.

---

### US-12.2: Interface padrão iOS 27 bilíngue
**As a** membro da família
**I want to** usar uma web com visual iOS 27 em inglês ou português
**So that** web e apps tenham a mesma cara

**Acceptance Criteria:**
- [ ] A web é feita com Livewire 4 + TailwindCSS 4 + TallStackUI, com tema iOS 27 (vidro translúcido de baixa transparência, cantos arredondados, tipografia de sistema).
- [ ] Funciona em largura de celular (≥ 360px) sem scroll horizontal.
- [ ] Todo texto visível passa por tradução. `en` é o padrão e `pt_BR` está completo para as telas do MVP.

**Expected Result:** A web é responsiva, bilíngue e visualmente alinhada aos apps.

---

### US-12.3: API v1 sobre as mesmas Actions
**As a** membro da família
**I want to** que app e web façam exatamente as mesmas operações
**So that** não haja comportamento diferente entre clientes

**Acceptance Criteria:**
- [ ] Toda regra de negócio fica em `app/Actions/<Module>/*`, chamada tanto pelo Livewire quanto pelo controller da API.
- [ ] Endpoints em `/api/v1`, com respostas via Eloquent API Resources.
- [ ] Erros de validação retornam 422 com o formato padrão do Laravel.
- [ ] Testes de feature cobrem cada Action pelo menos por um dos clientes.

**Expected Result:** Web e apps compartilham a mesma lógica e as mesmas validações.

---

### US-12.4: Deploy no Dokploy
**As a** super admin
**I want to** publicar o Jarvis no Dokploy com web, Horizon, Reverb, scheduler e Evolution API
**So that** tudo rode em produção no meu servidor

**Acceptance Criteria:**
- [ ] Os artefatos são adaptados de um `.deploy` de projeto anterior: `Dockerfile`, `docker-compose.prod.yml` com `web`, `horizon`, `reverb` e `scheduler`, `entrypoint.sh` e `php/prod.ini`.
- [ ] Usa PostgreSQL, Redis e Evolution API acessíveis na rede do Dokploy.
- [ ] O `entrypoint` roda migrations e caches (config, route, view) no boot do `web`.
- [ ] O scheduler executa `schedule:run` a cada minuto e o Horizon processa as filas `default`, `notifications`, `invoices` e `whatsapp`.
- [ ] O Reverb fica exposto via WebSocket com TLS no domínio do app.

**Expected Result:** Um deploy no Dokploy sobe todos os serviços funcionando.

---

## Appendix: User Story Status

| ID | Story | Priority | Status |
|----|-------|----------|--------|
| US-1.1 | Convidar um familiar | High | Pending |
| US-1.2 | Aceitar convite com senha | High | Pending |
| US-1.3 | Entrar com Google | High | Pending |
| US-1.4 | Login, logout e recuperação de senha na web | High | Pending |
| US-1.5 | Login nos apps com token por dispositivo | High | Pending |
| US-2.1 | Ligar e desligar módulos por usuário | High | Pending |
| US-3.2 | Idioma e fuso horário | High | Pending |
| US-4.1 | Escrever templates com variáveis e condições | High | Pending |
| US-5.1 | Receber notificações in-app em tempo real | High | Pending |
| US-5.2 | Receber push no celular | High | Pending |
| US-5.3 | Entrega por canal com fila e retry | High | Pending |
| US-5.4 | Tipos de notificação do MVP | High | Pending |
| US-6.1 | Cadastrar payers | High | Pending |
| US-6.2 | Cadastrar issuers | High | Pending |
| US-6.3 | Configurar recorrência de invoice | High | Pending |
| US-6.5 | Gerar e enviar automaticamente pela recorrência | High | Pending |
| US-7.1 | Conectar conta de WhatsApp (web) | High | Pending |
| US-7.3 | Agendar mensagem única ou recorrente | High | Pending |
| US-7.4 | Envio pelo WhatsApp service genérico | High | Pending |
| US-8.1 | Gerenciar listas e itens | High | Pending |
| US-8.2 | Marcar itens no mercado em tempo real | High | Pending |
| US-9.1 | Criar e concluir lembretes/tarefas | High | Pending |
| US-9.2 | Ser avisado na hora do lembrete | High | Pending |
| US-11.1 | Navegação do app por módulos | High | Pending |
| US-12.1 | Recorrência compartilhada | High | Pending |
| US-12.3 | API v1 sobre as mesmas Actions | High | Pending |
| US-12.4 | Deploy no Dokploy | High | Pending |
| US-3.3 | Preferências de notificação | Medium | Pending |
| US-4.2 | Pré-visualizar template | Medium | Pending |
| US-5.5 | Administrar tipos, canais e templates de notificação | Medium | Pending |
| US-6.4 | Pré-visualizar invoice e email da próxima execução | Medium | Pending |
| US-6.6 | Revisar invoice antes do envio | Medium | Pending |
| US-6.7 | Envio manual e reenvio | Medium | Pending |
| US-6.8 | Histórico de invoices | Medium | Pending |
| US-7.2 | Acompanhar e remover contas | Medium | Pending |
| US-7.5 | Enviar agora e ver histórico | Medium | Pending |
| US-10.1 | Acionar o Jarvis por voz | Medium | Pending |
| US-10.2 | Interpretar comando local ou remoto | Medium | Pending |
| US-10.3 | Comandos de voz do MVP | Medium | Pending |
| US-12.2 | Interface padrão iOS 27 bilíngue | Medium | Pending |
| US-2.2 | Gerenciar usuários | Medium | Pending |
| US-2.3 | Configurar o provedor de IA | Medium | Pending |
| US-3.1 | Editar perfil | Low | Pending |
| US-3.4 | Gerenciar dispositivos | Low | Pending |
