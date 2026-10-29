---
paths:
  - 'app/Actions/**'
---

# Actions

## One business operation per class
Every rule of the domain lives in an Action under `app/Actions/<Area>/`, called by Livewire, the API v1 controllers, jobs and commands alike. Controllers and components only gather input, call the Action and render the result. Example: `app/Actions/Shopping/CreateShoppingList.php`, called by `app/Livewire/Shopping/ShoppingListIndex.php` and `app/Http/Controllers/Api/V1/Shopping/ShoppingListController.php`.

## Shape: `final readonly class` with `__invoke`
Declare `final readonly class Foo` with a single public `__invoke(...)`; dependencies go in the constructor (`public function __construct(private SetMemberModules $setMemberModules) {}`). Static helpers are fine for shared rules (`AuthenticateUser::credentialRules()`, `IssueDeviceToken::deviceNameRules()`). A few Slack Actions (`HandleSlackEvent`, `RecordSlackMessages`) are only `final` because they keep state; do not copy that without a reason.

## The actor comes first
Signature is `__invoke(User $actor, <models>, array $input = [])`. The actor is passed explicitly, never read from `Auth::user()` inside the Action, so queues, the API and tests call it the same way. Exceptions are the guest flows (`AcceptInvitation`, `AuthenticateUser`), which take a token or credentials.

## Authorize inside the Action
Start with `Gate::forUser($actor)->authorize(...)`, even when the route already has `module:` or `can:super-admin` middleware: the check is repeated so web and API cannot drift. Abilities in use: `super-admin`, `access-module` (gates in `AppServiceProvider::configureGates()`), and policy abilities (`create`, `use`, `update`, `delete`…). Example: `app/Actions/Workspaces/SetMemberModules.php`.

## Per-module `Authorizes*` traits
Each module has a trait bundling its module gate and record checks: `AuthorizesShoppingModule`, `AuthorizesRemindersModule`, `AuthorizesInvoicesModule`, `AuthorizesWhatsappModule`, `AuthorizesChats`. A new Action of that module uses the trait instead of calling the gate by hand.

## Validate with `Validator::make()->validate()`
Input arrives as a plain `array $input` (typed in the docblock as `array{...: mixed}`) and is validated in the Action with `Validator::make($input, [...])->validate()`, never with FormRequests. Annotate the result: `/** @var array{name: string} $validated */`. Domain refusals are `ValidationException::withMessages(['field' => __('...')])` so Livewire shows them under the field and the API answers 422.

## Shared rules live in traits
Field rules reused by several Actions are traits next to them: `ValidatesWorkspace` (`colorRules()`, `moduleRules()`, `moduleIds()`), `ValidatesShoppingListName`, `InvoiceFieldRules`, `PayerRules`, `WhatsappMessageFields`. Add to these instead of copying rules.

## Workspace data: `ResolvesWorkspace`
For shared data (shopping, agenda, invoices) use `App\Actions\Workspaces\ResolvesWorkspace`: `workspaceFor($actor, $input, Module::X)` picks the `workspace` friendly id from input or the actor's default for the module; `authorizeWorkspaceRecord($actor, $record)` refuses records of other workspaces. Never accept a numeric `workspace_id` from input.

## Invisible records are 404, not 403
A record the actor cannot see throws `ModelNotFoundException` (via `authorizeWorkspaceRecord()`), so its existence never leaks. The module itself being off is a 403 (`AuthorizationException` / `ModuleAccessDenied`).

## Transactions and locks
Wrap multi-row writes in `DB::transaction()`. Where double-submits matter, lock the row: `AcceptInvitation::createAccount()` uses `lockForUpdate()` so one invitation creates one account. Dispatch events, notifications and e-mails after the writes, outside the transaction closure (`InviteUser` sends the invitation after its transaction; `CreateShoppingList` dispatches `ShoppingListChanged` after the insert).

## Forget cached memberships after changing them
`User::workspaceIdsWithModule()` reads loaded `memberships`. After changing memberships or module rows call `$user->forgetMemberships()` (and `$membership->unsetRelation('modules')`), or the same request keeps the old access. See `SetMemberModules`, `AddWorkspaceMember`.

## Lookups by slug constant
Reference categorical values with model constants and `Lookup::idFor()` / `bySlug()` (`Module::idFor(Module::SHOPPING)`, `SocialProvider::bySlug(SocialProvider::GOOGLE)`), never hard-coded ids. Validate incoming slugs with `Rule::exists('modules', 'slug')->where('is_active', true)`.

## Emails are normalized
Lower-case and trim e-mails before validating or querying (`Str::lower(trim(...))`) and compare with `whereRaw('lower(email) = ?', ...)` over `User::withTrashed()`: a deleted user's e-mail stays taken. See `InviteUser`, `AuthenticateWithGoogle`.

## Generic auth refusals
Sign-in and reset Actions never reveal whether an e-mail has an account: `AuthenticateUser` gives `auth.failed` for unknown/wrong/deactivated/deleted, `SendPasswordResetLink` answers the same in every case. Keep that when touching them.

## Notifications go through `NotificationCenter`
Send user notifications with `App\Services\Notifications\NotificationCenter::dispatch(new <Type>(...), $user)` (types in `app/Services/Notifications/Types/`), not `$user->notify()`. Example: `ModuleEnabled` in `SetMemberModules`.

## Document the behaviour
Each Action has a class docblock saying what it does in product terms and every refusal (the `US-x.y` ids in older docblocks point to planning docs no longer in the repo; don't add new ones); methods list `@throws AuthorizationException` / `ValidationException`. Keep that style.
