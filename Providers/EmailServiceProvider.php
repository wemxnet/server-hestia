<?php

namespace Extensions\Servers\Hestia\Providers;

use Illuminate\Support\ServiceProvider;

class EmailServiceProvider extends ServiceProvider
{
    /**
     * Register the email templates of this extension so admins can edit them under Emails.
     */
    public function boot(): void
    {
        config(['email_events' => array_merge(config('email_events', []), [
            'server.hestia.account_created' => [
                'name' => 'Hestia account created',
                'group' => 'Servers',
                'description' => 'Sent when a Hestia hosting account is created for an order.',
                'subject' => 'Hestia Account',
                'body' => <<<'BODY'
Your Hestia Account details:
Username: {{username}}
Password: {{password}}
BODY,
                'button_text' => 'Hestia Panel',
                'placeholders' => [
                    'username' => 'Hestia username',
                    'password' => 'Hestia password',
                    'panel_url' => 'Hestia panel URL',
                ],
            ],
        ])]);
    }
}
