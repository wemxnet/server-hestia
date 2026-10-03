@if(isset($order) && \Extensions\Servers\Hestia\Server::usesHestia($order))
    @livewire('client_area.default.orders.livewire.hestia-panel', ['order_id' => $order->id])
@endif
