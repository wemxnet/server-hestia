<?php

use App\Models\Order;
use Extensions\Servers\Hestia\Server;
use Extensions\Servers\Hestia\Support\HestiaApi;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    #[Locked]
    public int $order_id;

    public string $tab = 'overview';

    public bool $showPassword = false;

    public string $domain = '';

    public string $ftp_domain = '';

    public string $ftp_username = '';

    public string $ftp_password = '';

    public string $mail_domain = '';

    public string $mail_account_domain = '';

    public string $mail_username = '';

    public string $mail_password = '';

    public string $database_type = 'mysql';

    public string $database_name = '';

    public string $database_user = '';

    public string $database_password = '';

    #[Computed]
    public function order(): ?Order
    {
        return Order::query()->with(['package.serverConnection', 'user'])->find($this->order_id);
    }

    #[Computed]
    public function panelUrl(): string
    {
        return HestiaApi::buildPanelUrl($this->order?->package?->serverConnection?->config ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function summary(): ?array
    {
        if (! $this->isReady()) {
            return null;
        }

        try {
            return Server::actions()->summary($this->order);
        } catch (Throwable) {
            return ['error' => true];
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function webDomains(): array
    {
        return $this->safely(fn (Order $order) => Server::actions()->webDomains($order));
    }

    /**
     * @return array{domains: array<int, string>, accounts: array<string, array<string, mixed>>}
     */
    #[Computed]
    public function mail(): array
    {
        return $this->safely(fn (Order $order) => Server::actions()->mail($order)) ?: ['domains' => [], 'accounts' => []];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function databases(): array
    {
        return $this->safely(fn (Order $order) => Server::actions()->databases($order));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function backups(): array
    {
        return $this->safely(fn (Order $order) => Server::actions()->backups($order));
    }

    public function createDomain(): void
    {
        Server::actions()->createDomainAsClient($this->input(['domain' => $this->domain]));

        $this->reset('domain');
        unset($this->webDomains, $this->summary);
        $this->dispatch('toast', type: 'success', message: 'Domain has been created.', title: 'Success');
    }

    public function createFtp(): void
    {
        Server::actions()->createFtpAsClient($this->input([
            'domain' => $this->ftp_domain,
            'username' => $this->ftp_username,
            'password' => $this->ftp_password,
        ]));

        $this->reset(['ftp_username', 'ftp_password']);
        unset($this->webDomains);
        $this->dispatch('toast', type: 'success', message: 'FTP user has been created.', title: 'Success');
    }

    public function createMailDomain(): void
    {
        Server::actions()->createMailDomainAsClient($this->input(['domain' => $this->mail_domain]));

        $this->reset('mail_domain');
        unset($this->mail, $this->summary);
        $this->dispatch('toast', type: 'success', message: 'Mail domain has been created.', title: 'Success');
    }

    public function createMailAccount(): void
    {
        Server::actions()->createMailAccountAsClient($this->input([
            'domain' => $this->mail_account_domain,
            'username' => $this->mail_username,
            'password' => $this->mail_password,
        ]));

        $this->reset(['mail_username', 'mail_password']);
        unset($this->mail);
        $this->dispatch('toast', type: 'success', message: 'Email address has been created.', title: 'Success');
    }

    public function createDatabase(): void
    {
        Server::actions()->createDatabaseAsClient($this->input([
            'type' => $this->database_type,
            'database' => $this->database_name,
            'username' => $this->database_user,
            'password' => $this->database_password,
        ]));

        $this->reset(['database_name', 'database_user', 'database_password']);
        unset($this->databases, $this->summary);
        $this->dispatch('toast', type: 'success', message: 'Database has been created.', title: 'Success');
    }

    public function createBackup(): void
    {
        Server::actions()->createBackupAsClient($this->input([]));

        unset($this->backups);
        $this->dispatch('toast', type: 'success', message: 'Your backup has been added to the queue. You will receive an email once it is done.', title: 'Success');
    }

    public function refreshPanel(): void
    {
        if ($this->order) {
            Server::actions()->forgetSummary($this->order);
        }

        unset($this->order, $this->summary, $this->webDomains, $this->mail, $this->databases, $this->backups);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function input(array $input): array
    {
        return array_merge([
            'order_id' => $this->order_id,
            'user_id' => auth()->id(),
        ], $input);
    }

    protected function isReady(): bool
    {
        $order = $this->order;

        return $order && Server::usesHestia($order) && $order->external_id;
    }

    protected function safely(callable $callback): array
    {
        if (! $this->isReady() || $this->order->status !== 'active') {
            return [];
        }

        try {
            return $callback($this->order);
        } catch (Throwable) {
            return [];
        }
    }
}

?>

<div>
    @php
        $order = $this->order;
        $summary = $this->summary;
        $panelUrl = $this->panelUrl;
        $canManage = $order && $order->status === 'active' && $order->external_id;
        $account = $order?->getExternalUser();
        $password = $account?->password;
        $inputClass = 'bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-primary-500 focus:border-primary-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white';
        $shortcuts = [
            ['label' => 'File Manager', 'href' => $panelUrl.'/fm/#/', 'external' => true],
            ['label' => 'Domains', 'tab' => 'domains'],
            ['label' => 'Mail', 'tab' => 'mail'],
            ['label' => 'Databases', 'tab' => 'databases'],
            ['label' => 'DNS Manager', 'href' => $panelUrl.'/list/dns/', 'external' => true],
            ['label' => 'phpMyAdmin', 'href' => rtrim($order?->package?->serverConnection?->config['hostname'] ?? '', '/').'/phpmyadmin', 'external' => true],
            ['label' => 'Statistics', 'href' => $panelUrl.'/list/stats/', 'external' => true],
            ['label' => 'Cron Jobs', 'href' => $panelUrl.'/list/cron/', 'external' => true],
            ['label' => 'Backups', 'tab' => 'backups'],
        ];
    @endphp

    @if($order)
        <x-theme::card class="mb-4">
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">Hestia account</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ $order->option('domain') ?: $order->package->name }}
                        @if($order->external_id)
                            · {{ $order->external_id }}
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if($order->status === 'suspended' || ($summary['suspended'] ?? false))
                        <x-theme::badge.warning text="Suspended" />
                    @elseif($summary && empty($summary['error']))
                        <x-theme::badge.success text="Active" />
                    @else
                        <x-theme::badge.primary text="Unknown" />
                    @endif

                    @if($canManage)
                        <x-theme::button.primary :href="$panelUrl" target="_blank" text="Login to Hestia" />
                    @endif
                </div>
            </div>

            @if($order->status === 'suspended')
                <x-theme::alert.warning class="mb-4" text="This account is suspended. Hestia tools are unavailable until the order is reactivated." />
            @endif

            @if(! $order->external_id)
                <x-theme::alert.primary text="Your hosting account is still being created. Details will appear here once it is ready." />
            @elseif(($summary['error'] ?? false) === true)
                <x-theme::alert.warning text="Live account details could not be loaded from Hestia. Refresh the page or contact support if this continues." />
            @else
                <x-theme::datagrid.grid :cols="3" :gap="4">
                    <x-theme::datagrid.item>
                        <x-slot:label>Username</x-slot:label>
                        {{ $account->username ?? $order->external_id }}
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>Password</x-slot:label>
                        @if($password)
                            <span class="inline-flex items-center gap-2">
                                <span>{{ $showPassword ? $password : str_repeat('•', 10) }}</span>
                                <button type="button" wire:click="$toggle('showPassword')" class="text-xs text-primary-700 hover:underline dark:text-primary-400">
                                    {{ $showPassword ? 'Hide' : 'Show' }}
                                </button>
                            </span>
                        @else
                            —
                        @endif
                    </x-theme::datagrid.item>
                    <x-theme::datagrid.item>
                        <x-slot:label>Package</x-slot:label>
                        {{ $summary['package'] ?? $order->package->data('package', '—') }}
                    </x-theme::datagrid.item>
                </x-theme::datagrid.grid>

                @if($summary)
                    <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                        @foreach([
                            ['label' => 'Disk Space', 'used' => $summary['disk_used'], 'quota' => $summary['disk_quota'], 'percent' => $summary['disk_percent']],
                            ['label' => 'Bandwidth', 'used' => $summary['bandwidth_used'], 'quota' => $summary['bandwidth_quota'], 'percent' => $summary['bandwidth_percent']],
                        ] as $meter)
                            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $meter['label'] }}</p>
                                <p class="mt-2 text-sm text-gray-900 dark:text-white">
                                    {{ $meter['used'] }} MB / {{ $meter['quota'] === 'unlimited' ? 'Unlimited' : $meter['quota'].' MB' }}
                                </p>
                                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                    <div class="h-full rounded-full bg-primary-600" style="width: {{ $meter['percent'] }}%"></div>
                                </div>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $meter['percent'] }}% used</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif

            @if($canManage)
                <div class="mt-6 flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-700">
                    @foreach(['overview' => 'Overview', 'domains' => 'Domains', 'mail' => 'Mail', 'databases' => 'Databases', 'backups' => 'Backups'] as $name => $label)
                        <button
                            type="button"
                            wire:click="$set('tab', '{{ $name }}')"
                            class="rounded-lg px-4 py-2 text-sm font-medium {{ $tab === $name ? 'bg-primary-700 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            @endif
        </x-theme::card>

        @if($canManage)
            @if($tab === 'overview')
                <x-theme::card class="mb-4">
                    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5">
                        @foreach($shortcuts as $shortcut)
                            @if(isset($shortcut['tab']))
                                <button type="button" wire:click="$set('tab', '{{ $shortcut['tab'] }}')" class="rounded-xl border border-gray-200 p-4 text-center text-sm font-semibold text-gray-900 hover:bg-gray-50 dark:border-gray-700 dark:text-white dark:hover:bg-gray-700">
                                    {{ $shortcut['label'] }}
                                </button>
                            @else
                                <a href="{{ $shortcut['href'] }}" target="_blank" rel="noopener" class="rounded-xl border border-gray-200 p-4 text-center text-sm font-semibold text-gray-900 hover:bg-gray-50 dark:border-gray-700 dark:text-white dark:hover:bg-gray-700">
                                    {{ $shortcut['label'] }} ↗
                                </a>
                            @endif
                        @endforeach
                    </div>
                </x-theme::card>
            @endif

            @if($tab === 'domains')
                <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <x-theme::card>
                        <h4 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Create Domain</h4>
                        <div class="mb-3">
                            <x-theme::form.label for="hestia-domain" text="Domain" />
                            <x-theme::form.input id="hestia-domain" type="text" wire:model="domain" placeholder="example.com" />
                            @error('domain') <x-theme::form.error :text="$message" /> @enderror
                        </div>
                        <x-theme::button.primary type="button" wire:click="createDomain" wire:loading.attr="disabled" text="Create Domain" />
                    </x-theme::card>

                    <x-theme::card>
                        <h4 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Add FTP User</h4>
                        <div class="mb-3">
                            <x-theme::form.label for="hestia-ftp-domain" text="Domain" />
                            <select id="hestia-ftp-domain" wire:model="ftp_domain" class="{{ $inputClass }}">
                                <option value="">Select a domain</option>
                                @foreach(array_keys($this->webDomains) as $webDomain)
                                    <option value="{{ $webDomain }}">{{ $webDomain }}</option>
                                @endforeach
                            </select>
                            @error('domain') <x-theme::form.error :text="$message" /> @enderror
                        </div>
                        <div class="mb-3">
                            <x-theme::form.label for="hestia-ftp-username" text="Username" />
                            <div class="flex">
                                <span class="inline-flex items-center rounded-l-lg border border-r-0 border-gray-300 bg-gray-200 px-3 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-600 dark:text-gray-400">{{ $order->external_id }}_</span>
                                <input id="hestia-ftp-username" type="text" wire:model="ftp_username" class="{{ $inputClass }} rounded-l-none" placeholder="username">
                            </div>
                            @error('username') <x-theme::form.error :text="$message" /> @enderror
                        </div>
                        <div class="mb-3">
                            <x-theme::form.label for="hestia-ftp-password" text="Password" />
                            <x-theme::form.input id="hestia-ftp-password" type="password" wire:model="ftp_password" />
                            @error('password') <x-theme::form.error :text="$message" /> @enderror
                        </div>
                        <x-theme::button.primary type="button" wire:click="createFtp" wire:loading.attr="disabled" text="Add FTP User" />
                    </x-theme::card>
                </div>

                <x-theme::card class="mb-4">
                    <h4 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Domains</h4>
                    @if(count($this->webDomains) === 0)
                        <p class="text-sm text-gray-500 dark:text-gray-400">No domains yet.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left text-sm text-gray-500 dark:text-gray-400">
                                <thead class="text-xs uppercase text-gray-700 dark:text-gray-400">
                                    <tr><th class="py-2 pr-4">Domain</th><th class="py-2 pr-4">IP Address</th><th class="py-2 pr-4">Disk</th><th class="py-2 pr-4">Bandwidth</th><th class="py-2"></th></tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($this->webDomains as $name => $webDomain)
                                        <tr wire:key="web-{{ $name }}">
                                            <td class="py-3 pr-4 font-medium text-gray-900 dark:text-white">{{ $name }}</td>
                                            <td class="py-3 pr-4">{{ $webDomain['IP'] ?? '—' }}</td>
                                            <td class="py-3 pr-4">{{ $webDomain['U_DISK'] ?? 0 }} MB</td>
                                            <td class="py-3 pr-4">{{ $webDomain['U_BANDWIDTH'] ?? 0 }} MB</td>
                                            <td class="py-3 text-right"><a href="{{ $panelUrl }}/list/web/" target="_blank" rel="noopener" class="font-medium text-primary-600 hover:underline dark:text-primary-500">View ↗</a></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-theme::card>
            @endif

            @if($tab === 'mail')
                <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <x-theme::card>
                        <h4 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Create Mail Domain</h4>
                        <div class="mb-3">
                            <x-theme::form.label for="hestia-mail-domain" text="Domain" />
                            <x-theme::form.input id="hestia-mail-domain" type="text" wire:model="mail_domain" placeholder="example.com" />
                            @error('domain') <x-theme::form.error :text="$message" /> @enderror
                        </div>
                        <x-theme::button.primary type="button" class="mb-4" wire:click="createMailDomain" wire:loading.attr="disabled" text="Create Mail Domain" />

                        @forelse($this->mail['domains'] as $mailDomain)
                            <div wire:key="mail-domain-{{ $mailDomain }}" class="mb-2 text-sm font-medium text-gray-900 dark:text-white">{{ $mailDomain }}</div>
                        @empty
                            <p class="text-sm text-gray-500 dark:text-gray-400">No mail domains yet.</p>
                        @endforelse
                    </x-theme::card>

                    <x-theme::card>
                        <h4 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Create Email Address</h4>
                        <div class="mb-3">
                            <x-theme::form.label for="hestia-mail-account-domain" text="Domain" />
                            <select id="hestia-mail-account-domain" wire:model.live="mail_account_domain" class="{{ $inputClass }}">
                                <option value="">Select a mail domain</option>
                                @foreach($this->mail['domains'] as $mailDomain)
                                    <option value="{{ $mailDomain }}">{{ $mailDomain }}</option>
                                @endforeach
                            </select>
                            @error('domain') <x-theme::form.error :text="$message" /> @enderror
                        </div>
                        <div class="mb-3">
                            <x-theme::form.label for="hestia-mail-username" text="Username" />
                            <div class="flex">
                                <input id="hestia-mail-username" type="text" wire:model="mail_username" class="{{ $inputClass }} rounded-r-none" placeholder="username">
                                <span class="inline-flex items-center rounded-r-lg border border-l-0 border-gray-300 bg-gray-200 px-3 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-600 dark:text-gray-400">{{ '@'.($mail_account_domain ?: 'domain') }}</span>
                            </div>
                            @error('username') <x-theme::form.error :text="$message" /> @enderror
                        </div>
                        <div class="mb-3">
                            <x-theme::form.label for="hestia-mail-password" text="Password" />
                            <x-theme::form.input id="hestia-mail-password" type="password" wire:model="mail_password" />
                            @error('password') <x-theme::form.error :text="$message" /> @enderror
                        </div>
                        <x-theme::button.primary type="button" wire:click="createMailAccount" wire:loading.attr="disabled" text="Create Email" />
                    </x-theme::card>
                </div>

                <x-theme::card class="mb-4">
                    <h4 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Email Addresses</h4>
                    @php($hasAccounts = collect($this->mail['accounts'])->flatten(1)->isNotEmpty())
                    @if(! $hasAccounts)
                        <p class="text-sm text-gray-500 dark:text-gray-400">No email addresses yet.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left text-sm text-gray-500 dark:text-gray-400">
                                <thead class="text-xs uppercase text-gray-700 dark:text-gray-400">
                                    <tr><th class="py-2 pr-4">Email</th><th class="py-2 pr-4">Quota</th><th class="py-2 pr-4">Disk</th><th class="py-2 pr-4">Autoreply</th><th class="py-2"></th></tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($this->mail['accounts'] as $mailDomain => $mailAccounts)
                                        @foreach($mailAccounts as $mailUser => $address)
                                            <tr wire:key="mail-{{ $mailUser }}-{{ $mailDomain }}">
                                                <td class="py-3 pr-4 font-medium text-gray-900 dark:text-white">{{ $mailUser.'@'.$mailDomain }}</td>
                                                <td class="py-3 pr-4">{{ $address['QUOTA'] ?? '—' }} {{ ($address['QUOTA'] ?? '') === 'unlimited' ? '' : 'MB' }}</td>
                                                <td class="py-3 pr-4">{{ $address['U_DISK'] ?? 0 }} MB</td>
                                                <td class="py-3 pr-4">{{ $address['AUTOREPLY'] ?? 'no' }}</td>
                                                <td class="py-3 text-right"><a href="{{ $panelUrl }}/list/mail/" target="_blank" rel="noopener" class="font-medium text-primary-600 hover:underline dark:text-primary-500">View ↗</a></td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-theme::card>
            @endif

            @if($tab === 'databases')
                <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <x-theme::card>
                        <h4 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Create Database</h4>
                        <div class="mb-3">
                            <x-theme::form.label for="hestia-db-type" text="Type" />
                            <select id="hestia-db-type" wire:model="database_type" class="{{ $inputClass }}">
                                <option value="mysql">MySQL</option>
                                <option value="pgsql">PostgreSQL</option>
                            </select>
                            @error('type') <x-theme::form.error :text="$message" /> @enderror
                        </div>
                        <div class="mb-3">
                            <x-theme::form.label for="hestia-db-name" text="Database" />
                            <div class="flex">
                                <span class="inline-flex items-center rounded-l-lg border border-r-0 border-gray-300 bg-gray-200 px-3 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-600 dark:text-gray-400">{{ $order->external_id }}_</span>
                                <input id="hestia-db-name" type="text" wire:model="database_name" class="{{ $inputClass }} rounded-l-none" placeholder="database">
                            </div>
                            @error('database') <x-theme::form.error :text="$message" /> @enderror
                        </div>
                        <div class="mb-3">
                            <x-theme::form.label for="hestia-db-user" text="Username" />
                            <div class="flex">
                                <span class="inline-flex items-center rounded-l-lg border border-r-0 border-gray-300 bg-gray-200 px-3 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-600 dark:text-gray-400">{{ $order->external_id }}_</span>
                                <input id="hestia-db-user" type="text" wire:model="database_user" class="{{ $inputClass }} rounded-l-none" placeholder="username">
                            </div>
                            @error('username') <x-theme::form.error :text="$message" /> @enderror
                        </div>
                        <div class="mb-3">
                            <x-theme::form.label for="hestia-db-password" text="Password" />
                            <x-theme::form.input id="hestia-db-password" type="password" wire:model="database_password" />
                            @error('password') <x-theme::form.error :text="$message" /> @enderror
                        </div>
                        <x-theme::button.primary type="button" wire:click="createDatabase" wire:loading.attr="disabled" text="Create Database" />
                    </x-theme::card>

                    <x-theme::card class="lg:col-span-2">
                        <h4 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Databases</h4>
                        @if(count($this->databases) === 0)
                            <p class="text-sm text-gray-500 dark:text-gray-400">No databases yet.</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-left text-sm text-gray-500 dark:text-gray-400">
                                    <thead class="text-xs uppercase text-gray-700 dark:text-gray-400">
                                        <tr><th class="py-2 pr-4">Database</th><th class="py-2 pr-4">User</th><th class="py-2 pr-4">Host</th><th class="py-2 pr-4">Type</th><th class="py-2 pr-4">Disk</th><th class="py-2"></th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        @foreach($this->databases as $name => $database)
                                            <tr wire:key="db-{{ $name }}">
                                                <td class="py-3 pr-4 font-medium text-gray-900 dark:text-white">{{ $database['DATABASE'] ?? $name }}</td>
                                                <td class="py-3 pr-4">{{ $database['DBUSER'] ?? '—' }}</td>
                                                <td class="py-3 pr-4">{{ $database['HOST'] ?? '—' }}</td>
                                                <td class="py-3 pr-4">{{ $database['TYPE'] ?? '—' }}</td>
                                                <td class="py-3 pr-4">{{ $database['U_DISK'] ?? 0 }} MB</td>
                                                <td class="py-3 text-right"><a href="{{ $panelUrl }}/list/db/" target="_blank" rel="noopener" class="font-medium text-primary-600 hover:underline dark:text-primary-500">View ↗</a></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-theme::card>
                </div>
            @endif

            @if($tab === 'backups')
                <x-theme::card class="mb-4">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h4 class="text-lg font-semibold text-gray-900 dark:text-white">Backups</h4>
                        <x-theme::button.primary type="button" wire:click="createBackup" wire:loading.attr="disabled" wire:confirm="Create a full backup of this account?" text="Create Backup" />
                    </div>
                    @if(count($this->backups) === 0)
                        <p class="text-sm text-gray-500 dark:text-gray-400">No backups yet.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left text-sm text-gray-500 dark:text-gray-400">
                                <thead class="text-xs uppercase text-gray-700 dark:text-gray-400">
                                    <tr><th class="py-2 pr-4">Backup</th><th class="py-2 pr-4">Size</th><th class="py-2 pr-4">Type</th><th class="py-2 pr-4">Runtime</th><th class="py-2 pr-4">Date</th><th class="py-2"></th></tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($this->backups as $name => $backup)
                                        <tr wire:key="backup-{{ $name }}">
                                            <td class="py-3 pr-4 font-medium text-gray-900 dark:text-white">{{ $name }}</td>
                                            <td class="py-3 pr-4">{{ $backup['SIZE'] ?? 0 }} MB</td>
                                            <td class="py-3 pr-4">{{ $backup['TYPE'] ?? '—' }}</td>
                                            <td class="py-3 pr-4">{{ $backup['RUNTIME'] ?? 0 }} minute(s)</td>
                                            <td class="py-3 pr-4">{{ $backup['DATE'] ?? '—' }}</td>
                                            <td class="py-3 text-right"><a href="{{ $panelUrl }}/list/backup/" target="_blank" rel="noopener" class="font-medium text-primary-600 hover:underline dark:text-primary-500">View ↗</a></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-theme::card>
            @endif
        @endif
    @endif
</div>
