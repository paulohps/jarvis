<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
        // The native Google Sign-In clients of the apps (US-1.5): an ID token
        // sent to the API must be issued to one of them or to the web client.
        'ios_client_id' => env('GOOGLE_IOS_CLIENT_ID'),
        'android_client_id' => env('GOOGLE_ANDROID_CLIENT_ID'),
        'tokeninfo_url' => 'https://oauth2.googleapis.com/tokeninfo',
    ],

    /*
     * The self-hosted Evolution API (WhatsApp, US-7.1). The webhook URL is
     * where the instances post their QR codes and connection changes,
     * reachable from the Evolution API container; empty means this app's
     * own `/api/v1/webhooks/evolution`. The secret goes in its header.
     * `sync_timeout` is for listing contacts and groups, which WhatsApp
     * answers slowly on a number with many groups (17s with ~85).
     */
    'evolution' => [
        'url' => env('EVOLUTION_API_URL', 'http://evolution-api:8080'),
        'key' => env('EVOLUTION_API_KEY'),
        'webhook_url' => env('EVOLUTION_WEBHOOK_URL'),
        'webhook_secret' => env('EVOLUTION_WEBHOOK_SECRET'),
        'timeout' => (int) env('EVOLUTION_API_TIMEOUT', 10),
        'sync_timeout' => (int) env('EVOLUTION_API_SYNC_TIMEOUT', 50),
        // Chat files nobody opened for this many days are removed from storage (whatsapp:prune-media).
        'media_keep_days' => (int) env('WHATSAPP_MEDIA_KEEP_DAYS', 30),
    ],

    /*
     * Firebase Cloud Messaging HTTP v1, the push channel for iOS and Android
     * (US-5.2). The credentials are the service account JSON itself or the
     * path to its file; the project defaults to the one in the JSON.
     */
    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'timeout' => (int) env('FIREBASE_TIMEOUT', 10),

        /*
         * The Firebase web app of the same project, so the browser (the PWA)
         * registers for push too: its config from the console and the VAPID
         * key of "Web Push certificates". Public values, written into the
         * page; without them the web offers no push.
         */
        'web' => [
            'api_key' => env('FIREBASE_WEB_API_KEY'),
            'auth_domain' => env('FIREBASE_WEB_AUTH_DOMAIN'),
            'messaging_sender_id' => env('FIREBASE_WEB_MESSAGING_SENDER_ID'),
            'app_id' => env('FIREBASE_WEB_APP_ID'),
            'vapid_key' => env('FIREBASE_WEB_VAPID_KEY'),
        ],
    ],

    /*
     * The Slack app of the chat layer (Socialite's `slack` driver takes
     * `client_id`, `client_secret` and `redirect`): each user connects their
     * Slack workspaces with a user token. The signing secret verifies the
     * Events API requests posted to `/api/v1/webhooks/slack`.
     */
    'slack' => [
        'client_id' => env('SLACK_CLIENT_ID'),
        'client_secret' => env('SLACK_CLIENT_SECRET'),
        'redirect' => env('SLACK_REDIRECT_URI', '/chats/slack/callback'),
        'signing_secret' => env('SLACK_SIGNING_SECRET'),
        'timeout' => (int) env('SLACK_API_TIMEOUT', 10),
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
