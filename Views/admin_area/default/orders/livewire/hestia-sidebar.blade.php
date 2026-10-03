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

    #[Computed]
    public function order(): ?Order
    {
        return Order::query()->with('package.serverConnection')->find($this->order_id);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function summary(): ?array
    {
        $order = $this->order;

        if (! $order || ! Server::usesHestia($order) || ! $order->external_id) {
            return null;
        }

        try {
            return Server::actions()->summary($order);
        } catch (Throwable) {
            return null;
        }
    }
}

?>

@php
    $order = $this->order;
    $summary = $this->summary;
@endphp

@if($order)
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">Hestia</h3>
        </div>
        <div class="card-body">
            <div class="datagrid">
                <div class="datagrid-item">
                    <div class="datagrid-title">Status</div>
                    <div class="datagrid-content">
                        @if($order->status === 'suspended' || ($summary['suspended'] ?? false))
                            <span class="badge bg-yellow">Suspended</span>
                        @elseif($summary)
                            <span class="badge bg-green">Active</span>
                        @else
                            <span class="badge bg-secondary">{{ $order->external_id ? 'Unknown' : 'Not provisioned' }}</span>
                        @endif
                    </div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">Hestia user</div>
                    <div class="datagrid-content">{{ $order->external_id ?? '—' }}</div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">Package</div>
                    <div class="datagrid-content">{{ $summary['package'] ?? $order->package->data('package', '—') }}</div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">Disk</div>
                    <div class="datagrid-content">{{ $summary['disk_used'] ?? '—' }} MB / {{ $summary['disk_quota'] ?? '—' }}</div>
                </div>
                <div class="datagrid-item">
                    <div class="datagrid-title">Bandwidth</div>
                    <div class="datagrid-content">{{ $summary['bandwidth_used'] ?? '—' }} MB / {{ $summary['bandwidth_quota'] ?? '—' }}</div>
                </div>
            </div>

            <div class="mt-3">
                <a href="{{ HestiaApi::buildPanelUrl($order->package->serverConnection->config ?? []) }}/edit/user/?user={{ $order->external_id }}" target="_blank" class="btn btn-primary btn-sm">
                    Open in Hestia
                </a>
            </div>
        </div>
    </div>
@endif
