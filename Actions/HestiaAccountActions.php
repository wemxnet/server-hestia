<?php

namespace Extensions\Servers\Hestia\Actions;

use App\Actions\Action;
use App\Models\Order;
use App\Models\User;
use Extensions\Servers\Hestia\Server;
use Extensions\Servers\Hestia\Support\HestiaApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HestiaAccountActions extends Action
{
    public const DOMAIN_REGEX = '/^(?=.{1,253}$)(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+$/';

    /**
     * Account details and usage, cached for a few minutes.
     *
     * @return array<string, mixed>
     */
    public function summary(Order $order): array
    {
        return Cache::remember("hestia:user:{$order->id}", now()->addMinutes(5), function () use ($order) {
            $user = $this->api($order)->user(Server::username($order));

            $quota = self::limit($user['DISK_QUOTA'] ?? 'unlimited');
            $bandwidth = self::limit($user['BANDWIDTH'] ?? 'unlimited');
            $diskUsed = (float) ($user['U_DISK'] ?? 0);
            $bandwidthUsed = (float) ($user['U_BANDWIDTH'] ?? 0);

            return [
                'username' => Server::username($order),
                'package' => $user['PACKAGE'] ?? null,
                'suspended' => ($user['SUSPENDED'] ?? 'no') === 'yes',
                'disk_used' => $diskUsed,
                'disk_quota' => $user['DISK_QUOTA'] ?? 'unlimited',
                'disk_percent' => $quota ? min(100, round($diskUsed / $quota * 100, 2)) : 0,
                'bandwidth_used' => $bandwidthUsed,
                'bandwidth_quota' => $user['BANDWIDTH'] ?? 'unlimited',
                'bandwidth_percent' => $bandwidth ? min(100, round($bandwidthUsed / $bandwidth * 100, 2)) : 0,
                'web_domains' => $user['U_WEB_DOMAINS'] ?? null,
                'mail_domains' => $user['U_MAIL_DOMAINS'] ?? null,
                'databases' => $user['U_DATABASES'] ?? null,
            ];
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function webDomains(Order $order): array
    {
        return $this->api($order)->webDomains(Server::username($order));
    }

    /**
     * @return array{domains: array<int, string>, accounts: array<string, array<string, mixed>>}
     */
    public function mail(Order $order): array
    {
        $api = $this->api($order);
        $username = Server::username($order);
        $domains = array_map('strval', array_keys($api->mailDomains($username)));

        $accounts = [];

        foreach ($domains as $domain) {
            $accounts[$domain] = $api->mailAccounts($username, $domain);
        }

        return [
            'domains' => $domains,
            'accounts' => $accounts,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function databases(Order $order): array
    {
        return $this->api($order)->databases(Server::username($order));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function backups(Order $order): array
    {
        return $this->api($order)->backups(Server::username($order));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createDomainAsClient(array $input): Order
    {
        $validated = Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
            'domain' => ['required', 'string', 'max:191', 'regex:'.self::DOMAIN_REGEX],
        ])->validate();

        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id']);

        $this->api($order)->addWebDomain(Server::username($order), strtolower($validated['domain']));
        $this->forgetSummary($order);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createFtpAsClient(array $input): Order
    {
        $validated = Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
            'domain' => ['required', 'string', 'max:191', 'regex:'.self::DOMAIN_REGEX],
            'username' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:64'],
        ])->validate();

        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id']);

        $this->api($order)->addWebDomainFtp(Server::username($order), $validated['domain'], $validated['username'], $validated['password']);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createMailDomainAsClient(array $input): Order
    {
        $validated = Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
            'domain' => ['required', 'string', 'max:191', 'regex:'.self::DOMAIN_REGEX],
        ])->validate();

        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id']);

        $this->api($order)->addMailDomain(Server::username($order), strtolower($validated['domain']));
        $this->forgetSummary($order);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createMailAccountAsClient(array $input): Order
    {
        $validated = Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
            'domain' => ['required', 'string', 'max:191', 'regex:'.self::DOMAIN_REGEX],
            'username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:64'],
        ])->validate();

        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id']);

        $this->api($order)->addMailAccount(Server::username($order), $validated['domain'], $validated['username'], $validated['password']);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createDatabaseAsClient(array $input): Order
    {
        $validated = Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
            'type' => ['required', 'in:mysql,pgsql'],
            'database' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/'],
            'username' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/'],
            'password' => ['required', 'string', 'min:8', 'max:64'],
        ])->validate();

        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id']);

        $this->api($order)->addDatabase(
            Server::username($order),
            $validated['database'],
            $validated['username'],
            $validated['password'],
            $validated['type'],
        );

        $this->forgetSummary($order);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createBackupAsClient(array $input): Order
    {
        $validated = Validator::make($input, [
            'order_id' => ['required', 'exists:orders,id'],
            'user_id' => ['required', 'exists:users,id'],
        ])->validate();

        $order = $this->authorizedOrder($validated['order_id'], $validated['user_id']);

        $this->api($order)->backupUser(Server::username($order));

        return $order;
    }

    public function forgetSummary(Order $order): void
    {
        Cache::forget("hestia:user:{$order->id}");
    }

    public function api(Order $order): HestiaApi
    {
        return HestiaApi::fromConnection($order->package->serverConnection);
    }

    public function authorizedOrder(int|string $orderId, int|string $userId): Order
    {
        $order = Order::query()->with(['package.serverConnection', 'members', 'user'])->find($orderId);
        $user = User::query()->find($userId);

        if (! $order || ! Server::usesHestia($order)) {
            throw ValidationException::withMessages([
                'order_id' => 'This order is not provisioned on Hestia.',
            ]);
        }

        if (! $user) {
            throw ValidationException::withMessages([
                'user_id' => 'User not found.',
            ]);
        }

        $isOwner = (int) $order->user_id === (int) $user->id;
        $isMember = $order->members()
            ->where('status', 'active')
            ->where('user_id', $user->id)
            ->exists();

        if (! $isOwner && ! $isMember) {
            throw ValidationException::withMessages([
                'order_id' => 'You do not have access to this order.',
            ]);
        }

        if ($order->status !== 'active') {
            throw ValidationException::withMessages([
                'order_id' => 'This action is only available while the account is active.',
            ]);
        }

        if (! $order->external_id) {
            throw ValidationException::withMessages([
                'order_id' => 'This Hestia account has not finished provisioning yet.',
            ]);
        }

        return $order;
    }

    /**
     * Limit in MB, or 0 when the quota is unlimited.
     */
    protected static function limit(mixed $value): float
    {
        return $value === 'unlimited' ? 0.0 : (float) $value;
    }
}
