<?php

namespace App\Support\Pwa;

/**
 * The Firebase web app the browser registers for push with (US-5.2, on the
 * web): its public config and the VAPID key, from `services.firebase.web`.
 * Without all of them the web offers no push, and the page gets no config.
 */
final class WebPush
{
    /**
     * @return array{apiKey: string, authDomain: string, projectId: string, messagingSenderId: string, appId: string, vapidKey: string}|null
     */
    public static function clientConfig(): ?array
    {
        $web = (array) config('services.firebase.web', []);
        $projectId = (string) config('services.firebase.project_id');

        $config = [
            'apiKey' => (string) ($web['api_key'] ?? ''),
            'authDomain' => (string) ($web['auth_domain'] ?? ''),
            'projectId' => $projectId,
            'messagingSenderId' => (string) ($web['messaging_sender_id'] ?? ''),
            'appId' => (string) ($web['app_id'] ?? ''),
            'vapidKey' => (string) ($web['vapid_key'] ?? ''),
        ];

        if ($config['authDomain'] === '' && $projectId !== '') {
            $config['authDomain'] = "{$projectId}.firebaseapp.com";
        }

        foreach ($config as $value) {
            if ($value === '') {
                return null;
            }
        }

        return $config;
    }

    public static function isConfigured(): bool
    {
        return self::clientConfig() !== null;
    }
}
