<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationProvisioner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Creates the rows of the registered notification types — types, channels
 * and the `en`/`pt_BR` templates — and refreshes the templates nobody edited
 * (US-5.4, US-5.5). Runs on every deploy and from the database seeder;
 * running it again changes nothing, and an edited template is never touched.
 */
#[Signature('notifications:provision')]
#[Description('Create the notification types with their channels and default templates')]
class ProvisionNotificationTypes extends Command
{
    public function handle(NotificationProvisioner $provisioner): int
    {
        $provisioned = $provisioner->provisionAll();

        if ($provisioned === []) {
            $this->components->info('Every notification type is already provisioned.');

            return self::SUCCESS;
        }

        $this->components->info('Provisioned: '.implode(', ', $provisioned).'.');

        return self::SUCCESS;
    }
}
