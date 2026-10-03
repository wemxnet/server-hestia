@if(isset($order) && \Extensions\Servers\Hestia\Server::usesHestia($order))
    @livewire('admin_area.default.orders.livewire.hestia-sidebar', ['order_id' => $order->id])
@endif
