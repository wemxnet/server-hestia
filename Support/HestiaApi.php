<?php

namespace Extensions\Servers\Hestia\Support;

use App\Models\ServerConnection;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for the HestiaCP API (https://hestiacp.com/docs/server-administration/rest-api.html).
 *
 * Every call is a POST to /api/ with the command in "cmd" and its arguments in arg1..argN.
 */
class HestiaApi
{
    /**
     * Human readable messages for Hestia return codes.
     *
     * @var array<int, string>
     */
    protected const RETURN_CODES = [
        1 => 'Not enough arguments provided',
        2 => 'Object or argument is not valid',
        3 => "Object doesn't exist",
        4 => 'Object already exists',
        5 => 'Object is suspended',
        6 => 'Object is already unsuspended',
        7 => "Object can't be deleted because it is used by another object",
        8 => 'Object cannot be created because of hosting package limits',
        9 => 'Wrong password',
        10 => 'Object cannot be accessed by the user',
        11 => 'Subsystem is disabled',
        12 => 'Configuration is broken',
        13 => 'Not enough disk space to complete the action',
        14 => 'Server is too busy to complete the action',
        15 => 'Connection failed. Host is unreachable',
        16 => 'FTP server is not responding',
        17 => 'Database server is not responding',
        18 => 'RRDtool failed to update the database',
        19 => 'Update operation failed',
        20 => 'Service restart failed',
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(array $config): self
    {
        return new self($config);
    }

    public static function fromConnection(ServerConnection $connection): self
    {
        return new self($connection->config ?? []);
    }

    /**
     * Base URL of the Hestia panel, including the port when it is not 443.
     */
    public function panelUrl(): string
    {
        return self::buildPanelUrl($this->config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function buildPanelUrl(array $config): string
    {
        $hostname = rtrim((string) ($config['hostname'] ?? ''), '/');
        $port = (int) ($config['port'] ?? 8083);

        if ($port === 0 || $port === 443) {
            return $hostname;
        }

        return "{$hostname}:{$port}";
    }

    /**
     * Run a command that returns data. Hestia returns JSON keyed by object name.
     *
     * @param  array<int, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function list(string $command, array $arguments = []): array
    {
        $arguments[] = 'json';
        $body = trim($this->send($command, $arguments, false));

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new Exception("[Hestia] {$command} failed: {$body}");
        }

        return $decoded;
    }

    /**
     * Run a command that changes something and throw when Hestia returns a non-zero code.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function run(string $command, array $arguments = []): bool
    {
        $body = trim($this->send($command, $arguments, true));

        if (! preg_match('/^\d+$/', $body)) {
            throw new Exception("[Hestia] {$command} failed: {$body}");
        }

        $code = (int) $body;

        if ($code !== 0) {
            throw new Exception("[Hestia] {$command} failed: ".(self::RETURN_CODES[$code] ?? "return code {$code}"));
        }

        return true;
    }

    /**
     * Run a command without checking its output (used for long running commands such as backups).
     *
     * @param  array<int, mixed>  $arguments
     */
    public function fire(string $command, array $arguments = []): string
    {
        return $this->send($command, $arguments, true);
    }

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public function listUserPackages(): array
    {
        return $this->list('v-list-user-packages');
    }

    /**
     * @return array<string, mixed>
     */
    public function user(string $username): array
    {
        $user = $this->list('v-list-user', [$username]);

        return is_array(reset($user)) ? reset($user) : [];
    }

    public function addUser(string $username, string $password, string $email, ?string $package = null, ?string $name = null): bool
    {
        return $this->run('v-add-user', [$username, $password, $email, $package, $name]);
    }

    public function changeUserPassword(string $username, string $password): bool
    {
        return $this->run('v-change-user-password', [$username, $password]);
    }

    public function changeUserPackage(string $username, string $package): bool
    {
        return $this->run('v-change-user-package', [$username, $package, 'no']);
    }

    public function suspendUser(string $username): bool
    {
        return $this->run('v-suspend-user', [$username, 'yes']);
    }

    public function unsuspendUser(string $username): bool
    {
        return $this->run('v-unsuspend-user', [$username, 'yes']);
    }

    public function deleteUser(string $username): bool
    {
        return $this->run('v-delete-user', [$username]);
    }

    /*
    |--------------------------------------------------------------------------
    | Web domains & FTP
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public function webDomains(string $username): array
    {
        return $this->list('v-list-web-domains', [$username]);
    }

    public function addWebDomain(string $username, string $domain): bool
    {
        return $this->run('v-add-web-domain', [$username, $domain, '', 'yes', 'none', '']);
    }

    public function addWebDomainFtp(string $username, string $domain, string $ftpUser, string $ftpPassword, ?string $path = null): bool
    {
        return $this->run('v-add-web-domain-ftp', [$username, $domain, $ftpUser, $ftpPassword, $path ?? '']);
    }

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public function mailDomains(string $username): array
    {
        return $this->list('v-list-mail-domains', [$username]);
    }

    /**
     * @return array<string, mixed>
     */
    public function mailAccounts(string $username, string $domain): array
    {
        return $this->list('v-list-mail-accounts', [$username, $domain]);
    }

    public function addMailDomain(string $username, string $domain): bool
    {
        return $this->run('v-add-mail-domain', [$username, $domain, 'yes', 'yes', 'yes']);
    }

    public function addMailAccount(string $username, string $domain, string $account, string $password): bool
    {
        return $this->run('v-add-mail-account', [$username, $domain, $account, $password]);
    }

    /*
    |--------------------------------------------------------------------------
    | Databases
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public function databases(string $username): array
    {
        return $this->list('v-list-databases', [$username]);
    }

    public function addDatabase(string $username, string $database, string $dbUser, string $dbPassword, string $type = 'mysql'): bool
    {
        return $this->run('v-add-database', [$username, $database, $dbUser, $dbPassword, $type]);
    }

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    public function backups(string $username): array
    {
        return $this->list('v-list-user-backups', [$username]);
    }

    /**
     * Start a full backup of the user. Hestia emails the user when it finishes.
     */
    public function backupUser(string $username): void
    {
        try {
            $this->fire('v-backup-user', [$username, 'yes']);
        } catch (ConnectionException) {
            // Backups can take longer than the request timeout. Hestia keeps running it.
        }
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    protected function send(string $command, array $arguments, bool $returnCode): string
    {
        $params = [
            'user' => $this->config['username'] ?? '',
            'password' => $this->config['password'] ?? '',
            'returncode' => $returnCode ? 'yes' : 'no',
            'cmd' => $command,
        ];

        foreach (array_values($arguments) as $index => $argument) {
            $params['arg'.($index + 1)] = $argument ?? '';
        }

        $request = Http::asForm()->timeout($command === 'v-backup-user' ? 15 : 30);

        if ((string) ($this->config['verify_ssl'] ?? '0') !== '1') {
            $request = $request->withoutVerifying();
        }

        $response = $request->post($this->panelUrl().'/api/', $params);
        $body = (string) $response->body();

        if (str_contains($body, 'Error: authentication failed') || $response->status() === 401) {
            throw new Exception('[Hestia] Authentication failed. Check the username and password of the connection.');
        }

        if ($response->serverError() && ! preg_match('/^\s*\d+\s*$/', $body)) {
            throw new Exception("[Hestia] {$command} failed with status {$response->status()}: {$body}");
        }

        return $body;
    }
}
