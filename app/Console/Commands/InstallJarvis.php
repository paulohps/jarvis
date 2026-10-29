<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Database\Seeders\LookupSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Prepares a new installation: the application key when there is none, the
 * migrations, the lookups and the notification types, and then the super
 * admin, asked for (or given as options) with their first workspace.
 *
 * Running it again on an installed database only migrates and refreshes the
 * lookups: an existing super admin is never touched.
 */
#[Signature('jarvis:install
    {--name= : The super admin\'s name}
    {--email= : The super admin\'s e-mail}
    {--password= : The super admin\'s password}')]
#[Description('Prepare the database and create the first user, the super admin')]
class InstallJarvis extends Command
{
    public const string WORKSPACE_NAME = 'Home';

    public function handle(): int
    {
        if (blank(config('app.key'))) {
            $this->call('key:generate', ['--force' => true]);
        }

        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => LookupSeeder::class, '--force' => true]);
        $this->call('notifications:provision');

        if (User::withTrashed()->where('is_super_admin', true)->exists()) {
            $this->components->info('The super admin already exists: nothing else to do.');

            return self::SUCCESS;
        }

        return $this->createSuperAdmin();
    }

    private function createSuperAdmin(): int
    {
        $this->components->info('Create the super admin, the first user.');

        $input = [
            'name' => $this->option('name') ?? $this->prompted(fn (): string => text('Name', required: true)),
            'email' => $this->option('email') ?? $this->prompted(fn (): string => text('E-mail', required: true)),
            'password' => $this->option('password') ?? $this->prompted(fn (): string => password('Password', required: true)),
        ];
        $input['email'] = Str::lower(trim((string) $input['email']));

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        $validator->after(function ($validator) use ($input): void {
            if (User::withTrashed()->whereRaw('lower(email) = ?', [$input['email']])->exists()) {
                $validator->errors()->add('email', 'A user with this e-mail already exists.');
            }
        });

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        /** @var array{name: string, email: string, password: string} $validated */
        $validated = $validator->validated();

        $superAdmin = DB::transaction(function () use ($validated): User {
            $superAdmin = new User([
                'name' => trim($validated['name']),
                'email' => $validated['email'],
                'password' => $validated['password'],
                'locale_id' => DB::table('locales')->where('slug', 'en')->value('id'),
            ]);
            $superAdmin->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();

            $workspace = Workspace::query()->create([
                'name' => self::WORKSPACE_NAME,
                'color' => 'blue',
                'created_by_id' => $superAdmin->id,
            ]);

            WorkspaceMembership::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $superAdmin->id,
                'is_default' => true,
                'added_by_id' => $superAdmin->id,
            ]);

            return $superAdmin;
        });

        $this->components->info("Super admin {$superAdmin->email} created. Sign in at ".config('app.url').'.');

        return self::SUCCESS;
    }

    /**
     * The value of a question: asked when the command is interactive, null
     * otherwise, so a missing option fails the validation instead of hanging.
     *
     * @param  callable(): string  $prompt
     */
    private function prompted(callable $prompt): ?string
    {
        return $this->input->isInteractive() ? $prompt() : null;
    }
}
