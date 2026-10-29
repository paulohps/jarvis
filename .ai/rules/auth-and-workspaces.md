---
paths:
  - 'app/Actions/Auth/**'
  - 'app/Actions/Invitations/**'
  - 'app/Actions/Workspaces/**'
  - 'app/Actions/Users/**'
  - 'app/Actions/Profile/**'
  - 'app/Http/Controllers/Auth/**'
  - 'app/Http/Controllers/Api/V1/Auth/**'
  - 'app/Http/Controllers/Api/V1/Admin/**'
  - 'app/Http/Controllers/Api/V1/MeController.php'
  - 'app/Http/Middleware/**'
  - 'app/Livewire/Auth/**'
  - 'app/Livewire/Admin/**'
  - 'app/Policies/**'
  - 'app/Models/User.php'
  - 'app/Models/Workspace.php'
  - 'app/Models/WorkspaceMembership.php'
  - 'app/Models/Invitation.php'
  - 'app/Models/Module.php'
  - 'app/Models/SocialAccount.php'
  - 'app/Console/Commands/InstallJarvis.php'
  - 'app/Support/Navigation/**'
---

# Auth, invitations, workspaces and the super admin

## No sign-up: accounts come only from invitations
There is no registration route. A user exists because the super admin ran `jarvis:install` (the super admin) or because someone accepted an invitation (`AcceptInvitation`) — by password or by Google. Do not add a public sign-up or let Google sign-in create accounts without a pending invitation (`GoogleSignInRefused::invitationRequired()`).

## One super admin, a flag on users
`users.is_super_admin` (not fillable; `forceFill`) marks the single super admin, created by `app/Console/Commands/InstallJarvis.php` with a first workspace "Home". There are no roles or fine-grained permissions: the gate `super-admin` guards the admin area (`/admin` web routes and `/api/v1/admin/*`, both with `can:super-admin`), and inside a module everyone with the module can do everything. The super admin cannot be deactivated (`DeactivateUser`) and never has module rows (`SetMemberModules` refuses).

## Workspaces replaced the single household
Shared data (shopping, agenda/reminders, invoices) belongs to a `Workspace`; a user is a member of one or more through `WorkspaceMembership` (`workspace_user`) and sees the data of all of them together, each in its color. Personal data (WhatsApp/Slack accounts, chats, mail services, Google calendars, devices) stays per user. Only the super admin creates, renames, deletes workspaces and manages members (`app/Actions/Workspaces/*`, `app/Livewire/Admin/Workspaces/WorkspaceDetail.php`).

## Module access is per membership
A module is on for a user in a workspace when `module_workspace_user` has a row for that membership (`SetMemberModules`, `AddWorkspaceMember`); `module_user` no longer exists. `User::hasModule($slug)` = on in any workspace (route access); `workspaceIdsWithModule($slug)` = whose data they see; `defaultWorkspaceFor($slug)` = where new records go. The super admin passes every `hasModule()` but still sees only workspaces they are a member of.

## Three layers check access
1) Route middleware `module:<slug>` (`EnsureModuleAccess`, alias in `bootstrap/app.php`) → `ModuleAccessDenied` 403; it is also a Livewire persistent middleware, so an open screen stops on the next update. 2) The Action repeats `Gate::authorize('access-module', ...)`. 3) Records are filtered with `visibleTo($user)` / `isVisibleTo($user)`. Never rely on only one of them, and never cache access: it is read fresh each request.

## `is_active` on a module is not access control
`Module::active()` filters what navigation, `/me`, the admin matrix and validation offer, but `User::hasModule()` reads membership rows without looking at `is_active`. To really switch a module off, remove its rows or add the check deliberately.

## Default workspace and colors
Each user has at most one default membership (partial unique index `workspace_user_single_default_unique`). The first membership becomes default (`AddWorkspaceMember`, `AcceptInvitation`, `UserFactory::joinWorkspace`); removing it promotes another (`RemoveWorkspaceMember::promoteAnotherDefault()`). Colors are keys of `Workspace::COLORS`; a member may override theirs (`WorkspaceMembership::effectiveColor()`, `PATCH /api/v1/me/workspaces/{workspace}`).

## Deleting a workspace
`DeleteWorkspace` refuses when `Workspace::hasData()` finds rows in `Workspace::SCOPED_TABLES` (soft-deleted included). A new workspace-scoped table must be added to that list.

## Invitations
`InviteUser` requires an e-mail without an account (trashed included), a `workspace` friendly id, optional `locale` and `modules`. Only `token_hash` (sha256 of a 64-char random token) is stored; the plain token travels only in the e-mail. Valid `Invitation::VALIDITY_DAYS` (7); inviting the same e-mail revokes the earlier pending one; `ResendInvitation` rotates the token. Status is derived (`accepted_at`, `revoked_at`, `expires_at`). Policy: `app/Policies/InvitationPolicy.php` (super admin only).

## Accepting is race-safe and verifies the e-mail
`AcceptInvitation::createAccount()` locks the invitation row, re-checks it is pending and the e-mail free, creates the user with `email_verified_at = now()` and the invitation's locale, creates the default membership with exactly the invitation's modules (`enabled_by_id` = inviter), then marks it accepted. Reuse `createAccount()` for any new acceptance path (Google does).

## Password sign-in
`AuthenticateUser` signs in to the `web` guard; `verify()` checks the same credentials for the API using `Auth::guard('web')->once()`. Deactivated users are excluded in the credential query, every refusal is `auth.failed`, and `MAX_ATTEMPTS` (5) per `DECAY_SECONDS` (60) per e-mail+IP lock out. The API adds the `api-auth` limiter (10/min per IP). Production passwords use `Password::defaults()` (12+ chars, mixed case, symbols, uncompromised).

## Google sign-in (web and app)
`AuthenticateWithGoogle` resolves, in order: linked `SocialAccount`, same e-mail user (links it), pending invitation (from the link in session `google_invitation_token`, which must match the e-mail, or the latest pending one for the e-mail), else refuses. Only a Google-verified e-mail counts; deactivated/trashed users are refused. The web callback is `GoogleCallbackController`; the apps send a native ID token to `POST /api/v1/auth/google`, checked by `App\Services\Auth\GoogleIdTokenVerifier` against `tokeninfo` with `aud` in the web, iOS or Android client ids (`config/services.php` `google.*`).

## Sanctum: one token per device
`IssueDeviceToken` creates a token named after `device_name`; tokens never expire (`sanctum.expiration` null). `RevokeCurrentToken` deletes only the current token and its `Device` (push registration). `DeactivateUser` deletes all tokens, database sessions and rotates `remember_token`; `EnsureUserIsActive` (web group) logs out any surviving session. Reactivation does not restore tokens.

## Social accounts
`SocialAccount` links a user to a `SocialProvider` (lookup, only `google`). `DisconnectSocialAccount` refuses unless the user has a password, so nobody loses every way in. A Google-only user has `password = null`.

## Admin area structure
Admin Livewire screens live in `app/Livewire/Admin/<Area>/` behind `can:super-admin`; their navigation comes from `AppNavigation::adminGroups()` / `adminSections()` (also used by `AdminHome`). Add a new admin screen there, not hard-coded in the layout. `/admin/modules` is only a redirect to `/admin/workspaces`: modules are switched per member on the workspace page.

## The API mirrors the admin
Every admin action has an API twin under `/api/v1/admin` (`WorkspaceController`, `WorkspaceMemberController`, `UserController`, `InvitationController`, `ModuleController`) that calls the same Actions; members are addressed by the user's friendly id. `tests/Feature/ApiSurfaceTest.php` fails if an admin route lacks `can:super-admin` or any route is missing from `docs/api/v1.yaml`.

## `/me` is the apps' contract
`UserResource` (`GET /api/v1/me`) returns modules (`slug`, translated `name`, `is_available_on_mobile`), workspaces with their modules and colors, and `broadcast_channel`. The iOS/Android apps build tabs from it (`apps/*/…/AppTab`); changing its shape breaks shipped apps.

## Locale and timezone
`users.locale_id` (lookup `locales`: `en`, `pt_BR`) drives `SetLocale` and notifications (`User::preferredLocale()`); guests get `Accept-Language` on the API, else English. Store times in UTC and convert with `users.timezone`.
