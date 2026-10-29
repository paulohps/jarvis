<?php

use App\Support\FriendlyId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tables whose rows belong to a workspace: the data the household
     * used to share, now shared by the members of its workspace.
     *
     * @var list<string>
     */
    private const array SCOPED_TABLES = [
        'shopping_lists',
        'shopping_list_schedules',
        'reminders',
        'payers',
        'issuers',
        'invoice_recurrences',
        'invoices',
        'invoice_number_sequences',
    ];

    /**
     * Workspaces group the shared data (shopping, agenda, invoices) so each
     * family sees only its own. A user belongs to one or more workspaces
     * (`workspace_user`) and the modules are switched on per membership
     * (`module_workspace_user`), replacing `module_user`.
     *
     * Existing data and users move into a first workspace: every user joins
     * it with the modules they had, and it is their default.
     */
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->friendlyId();
            $table->string('name', 60);
            $table->string('color', 16);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('color', 16)->nullable();
            $table->boolean('is_default')->default(false);
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        DB::statement('CREATE UNIQUE INDEX workspace_user_single_default_unique ON workspace_user (user_id) WHERE is_default');

        Schema::create('module_workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_user_id')->constrained('workspace_user')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enabled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_user_id', 'module_id']);
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('locale_id')->constrained()->nullOnDelete();
        });

        foreach (self::SCOPED_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('workspace_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            });
        }

        $workspaceId = $this->moveExistingDataIntoAFirstWorkspace();

        foreach (self::SCOPED_TABLES as $tableName) {
            if ($workspaceId !== null) {
                DB::table($tableName)->whereNull('workspace_id')->update(['workspace_id' => $workspaceId]);
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('workspace_id')->nullable(false)->change();
            });
        }

        // A default shopping list per workspace instead of one in the system.
        DB::statement('DROP INDEX IF EXISTS shopping_lists_single_default_unique');
        DB::statement('CREATE UNIQUE INDEX shopping_lists_single_default_unique ON shopping_lists (workspace_id) WHERE is_default');

        // Invoice numbers count per workspace.
        Schema::table('invoice_number_sequences', function (Blueprint $table) {
            $table->dropUnique(['year_month']);
            $table->unique(['workspace_id', 'year_month']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropUnique(['number_year_month', 'number_sequence']);
            $table->unique(['workspace_id', 'number']);
            $table->unique(['workspace_id', 'number_year_month', 'number_sequence']);
        });

        Schema::dropIfExists('module_user');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('module_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enabled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['module_id', 'user_id']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO module_user (module_id, user_id, enabled_by_id, created_at, updated_at)
            SELECT DISTINCT ON (mwu.module_id, wu.user_id) mwu.module_id, wu.user_id, mwu.enabled_by_id, mwu.created_at, mwu.updated_at
            FROM module_workspace_user mwu
            JOIN workspace_user wu ON wu.id = mwu.workspace_user_id
            ORDER BY mwu.module_id, wu.user_id, mwu.id
            SQL);

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'number']);
            $table->dropUnique(['workspace_id', 'number_year_month', 'number_sequence']);
            $table->unique(['number']);
            $table->unique(['number_year_month', 'number_sequence']);
        });

        Schema::table('invoice_number_sequences', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'year_month']);
            $table->unique(['year_month']);
        });

        DB::statement('DROP INDEX IF EXISTS shopping_lists_single_default_unique');

        foreach (self::SCOPED_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('workspace_id');
            });
        }

        DB::statement('CREATE UNIQUE INDEX shopping_lists_single_default_unique ON shopping_lists (is_default) WHERE is_default');

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workspace_id');
        });

        Schema::dropIfExists('module_workspace_user');
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
    }

    /**
     * Creates the first workspace when there are users, makes every user a
     * member — their default workspace — with the modules they had, and
     * points the pending invitations at it.
     *
     * @return int|null the workspace's id, null on an empty database
     */
    private function moveExistingDataIntoAFirstWorkspace(): ?int
    {
        if (! DB::table('users')->exists()) {
            return null;
        }

        $superAdminId = DB::table('users')->where('is_super_admin', true)->orderBy('id')->value('id');
        $now = now();

        $workspaceId = DB::table('workspaces')->insertGetId([
            'friendly_id' => FriendlyId::generate('wsp'),
            'name' => 'Home',
            'color' => 'blue',
            'created_by_id' => $superAdminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (DB::table('users')->orderBy('id')->pluck('id') as $userId) {
            $membershipId = DB::table('workspace_user')->insertGetId([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'is_default' => true,
                'added_by_id' => $superAdminId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $modules = DB::table('module_user')->where('user_id', $userId)->get();

            foreach ($modules as $module) {
                DB::table('module_workspace_user')->insert([
                    'workspace_user_id' => $membershipId,
                    'module_id' => $module->module_id,
                    'enabled_by_id' => $module->enabled_by_id,
                    'created_at' => $module->created_at ?? $now,
                    'updated_at' => $module->updated_at ?? $now,
                ]);
            }
        }

        DB::table('invitations')->whereNull('accepted_at')->update(['workspace_id' => $workspaceId]);

        return $workspaceId;
    }
};
