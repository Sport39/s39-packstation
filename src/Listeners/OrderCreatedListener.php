<?php

namespace S39Packstation\Listeners;

use Plenty\Modules\Order\Events\OrderCreated;
use S39Packstation\Services\PackstationService;

/**
 * Jeder neue Auftrag (Shop, Kaufland, Amazon, Decathlon ...): Lieferadresse pruefen
 * und Packstation-/Postfiliale-Adressen ins DHL-Format bringen.
 */
class OrderCreatedListener
{
    public function handle(OrderCreated $event)
    {
        /** @var PackstationService $service */
        $service = pluginApp(PackstationService::class);
        if (!$service->active()) {
            return;
        }
        try {
            $order = $event->getOrder();
            // Gutschriften, Retouren usw. auslassen: nur Auftraege (typeId 1)
            if ((int)$order->typeId !== 1) {
                return;
            }
            $res = $service->handleOrder((int)$order->id);
            $service->note('event', ['orderId' => (int)$order->id, 'status' => $res['status'] ?? '']);
        } catch (\Throwable $e) {
            $service->logError('orderCreated', $e);
        }
    }
}
