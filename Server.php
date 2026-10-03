<?php

namespace Extensions\Servers\Hestia;

use App\Extensions\Foundation\ServerExtension;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackagePrice;
use App\Models\ServerAccount;
use App\Models\ServerConnection;
use Exception;
use Extensions\Servers\Hestia\Actions\HestiaAccountActions;
use Extensions\Servers\Hestia\Providers\EmailServiceProvider;
use Extensions\Servers\Hestia\Support\HestiaApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Server extends ServerExtension
{
    protected string $id = 'server-hestia';

    protected string $name = 'Hestia';

    protected string $description = 'Sell HestiaCP hosting accounts with client tools for domains, FTP, mail, databases, and backups.';

    protected string $type = 'Server';

    protected string $version = '1.0.0';

    protected array $wemxVersions = ['*'];

    protected array $authors = [
        [
            'name' => 'WemX',
            'email' => 'mubeen@wemx.net',
        ],
    ];

    public function providers(): array
    {
        return [
            EmailServiceProvider::class,
        ];
    }

    public function elements(): array
    {
        return [
            [
                'element' => 'client-order-top-view',
                'view' => 'server-hestia::client_area.default.orders.widgets.hestia-panel',
            ],
            [
                'element' => 'admin-order-sidebar-view',
                'view' => 'server-hestia::admin_area.default.orders.widgets.hestia-sidebar',
            ],
        ];
    }

    public function setConfig(): array
    {
        $doesNotEndWithSlash = function ($attribute, $value, $fail) {
            if (is_string($value) && preg_match('/\/$/', $value)) {
                $fail('Hostname must not end with a slash "/". It should be like https://panel.example.com');
            }
        };

        return [
            [
                'key' => 'hostname',
                'name' => 'Hostname',
                'description' => 'The hostname of your Hestia Panel i.e https://panel.example.com (without the port)',
                'type' => 'url',
                'default_value' => 'https://panel.example.com',
                'rules' => ['required', 'url', $doesNotEndWithSlash],
            ],
            [
                'key' => 'port',
                'name' => 'Port',
                'description' => "The port of your Hestia Panel i.e 8083, set to 443 if you don't use any port",
                'type' => 'number',
                'default_value' => 8083,
                'rules' => ['required', 'numeric', 'min:1', 'max:65535'],
            ],
            [
                'key' => 'username',
                'name' => 'Hestia Username',
                'description' => 'Username of an administrator on Hestia',
                'type' => 'text',
                'default_value' => 'admin',
                'rules' => ['required', 'string'],
            ],
            [
                'key' => 'password',
                'name' => 'Hestia Password',
                'description' => 'Password of an administrator on Hestia. Make sure API access is allowed for this IP under Server Settings → Security → API.',
                'type' => 'password',
                'rules' => ['required', 'string'],
            ],
            [
                'key' => 'verify_ssl',
                'name' => 'Verify SSL',
                'description' => 'Keep disabled when Hestia uses a self-signed certificate.',
                'type' => 'select',
                'options' => [
                    '0' => 'Disabled',
                    '1' => 'Enabled',
                ],
                'default_value' => '0',
                'rules' => ['required', 'in:0,1'],
            ],
        ];
    }

    public function setPackageConfig(Package $package, ServerConnection $connection): array
    {
        $packages = $this->cachedPackageOptions($connection);

        $packageField = $packages === []
            ? [
                'col' => 'col-12',
                'key' => 'package',
                'name' => 'Hestia Package',
                'description' => 'Name of the Hestia package that belongs to this package. The connection could not be reached, so enter the name manually.',
                'type' => 'text',
                'rules' => ['required', 'string'],
                'is_configurable' => false,
            ]
            : [
                'col' => 'col-12',
                'key' => 'package',
                'name' => 'Hestia Package',
                'description' => 'Select the Hestia Package that belongs to this Package',
                'type' => 'select',
                'options' => $packages,
                'default_value' => array_key_first($packages),
                'rules' => ['required', 'string'],
                'is_configurable' => false,
            ];

        return [
            $packageField,
            [
                'col' => 'col-12',
                'key' => 'domain',
                'name' => 'Domain',
                'description' => 'Optional web domain created on Hestia together with the account. Make this a configurable option to let customers enter their domain at checkout.',
                'type' => 'text',
                'rules' => ['nullable', 'string', 'max:191', 'regex:/^(?=.{1,253}$)(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+$/'],
                'is_configurable' => true,
            ],
        ];
    }

    public static function testConnection(array $credentials): string
    {
        HestiaApi::make($credentials)->listUserPackages();

        return 'Successfully connected with Hestia';
    }

    public function create(Order $order, ServerConnection $connection)
    {
        $user = $order->user;
        $api = HestiaApi::fromConnection($connection);

        $username = self::generateUsername($user->username);
        $password = Str::password(16, symbols: false);
        $fullName = trim($user->first_name.' '.$user->last_name) ?: $user->username;

        $api->addUser($username, $password, $user->email, $order->package->data('package'), $fullName);

        $order->update([
            'external_id' => $username,
            'data' => array_merge($order->data ?? [], ['username' => $username]),
        ]);

        $order->createExternalUser([
            'external_id' => $username,
            'username' => $username,
            'password' => $password,
        ]);

        $domain = $order->option('domain');

        if (is_string($domain) && $domain !== '') {
            $api->addWebDomain($username, strtolower($domain));
        }

        $this->emailDetails($order, $connection, $username, $password);
    }

    public function suspend(Order $order, ServerConnection $connection)
    {
        HestiaApi::fromConnection($connection)->suspendUser(self::username($order));
    }

    public function unsuspend(Order $order, ServerConnection $connection)
    {
        HestiaApi::fromConnection($connection)->unsuspendUser(self::username($order));
    }

    public function terminate(Order $order, ServerConnection $connection)
    {
        HestiaApi::fromConnection($connection)->deleteUser(self::username($order));
    }

    public function upgradeOrDowngrade(Order $order, PackagePrice $oldPackagePrice, PackagePrice $newPackagePrice, ServerConnection $connection)
    {
        HestiaApi::fromConnection($connection)->changeUserPackage(
            self::username($order),
            (string) $newPackagePrice->package->data('package')
        );

        Cache::forget("hestia:user:{$order->id}");
    }

    public function changePassword(Order $order, string $newPassword)
    {
        HestiaApi::fromConnection($order->package->serverConnection)->changeUserPassword(self::username($order), $newPassword);

        $order->updateExternalPassword($newPassword);
    }

    public static function actions(): HestiaAccountActions
    {
        return new HestiaAccountActions;
    }

    public static function usesHestia(?Order $order): bool
    {
        return $order?->package?->serverConnection?->extension_identifier === 'server-hestia';
    }

    /**
     * Hestia username that belongs to the order.
     */
    public static function username(Order $order): string
    {
        $username = $order->external_id ?: ($order->data['username'] ?? null);

        if (! $username) {
            $username = ServerAccount::query()
                ->where('order_id', $order->id)
                ->where('server', 'server-hestia')
                ->value('username');
        }

        if (! $username) {
            throw new Exception('This order does not have a Hestia account yet.');
        }

        return (string) $username;
    }

    /**
     * Hestia usernames may only contain lowercase letters and numbers, and must start with a letter.
     * Three random digits prevent duplicate usernames.
     */
    public static function generateUsername(string $base): string
    {
        $base = Str::lower(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($base)) ?? '');

        if ($base === '' || ! ctype_alpha($base[0])) {
            $base = 'u'.$base;
        }

        return Str::limit($base, 20, '').random_int(100, 999);
    }

    protected function emailDetails(Order $order, ServerConnection $connection, string $username, string $password): void
    {
        $panelUrl = HestiaApi::fromConnection($connection)->panelUrl();

        $order->user->email([
            'identifier' => 'server.hestia.account_created',
            'mailable_type' => Order::class,
            'mailable_id' => $order->id,
            'subject' => 'Hestia Account',
            'lines' => [
                'Your Hestia Account details:',
                "Username: {$username}",
                "Password: {$password}",
            ],
            'variables' => [
                'username' => $username,
                'password' => $password,
                'panel_url' => $panelUrl,
            ],
            'button' => [
                'text' => 'Hestia Panel',
                'url' => $panelUrl,
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function cachedPackageOptions(ServerConnection $connection): array
    {
        $connectionId = $connection->id ?? 'new';

        try {
            return Cache::remember("hestia:packages:{$connectionId}", now()->addHour(), function () use ($connection) {
                return collect(HestiaApi::fromConnection($connection)->listUserPackages())
                    ->keys()
                    ->mapWithKeys(fn ($name) => [(string) $name => (string) $name])
                    ->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }
}
