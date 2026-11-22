<?php

namespace S39Packstation\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Order\Events\OrderCreated;
use S39Packstation\Listeners\OrderCreatedListener;
use S39Packstation\Crons\PackstationCron;
use Plenty\Modules\Cron\Services\CronContainer;

class S39PackstationServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->getApplication()->register(S39PackstationRouteServiceProvider::class);
    }

    public function boot(Dispatcher $dispatcher, CronContainer $cron)
    {
        $dispatcher->listen(OrderCreated::class, OrderCreatedListener::class);
        $cron->add(CronContainer::EVERY_FIFTEEN_MINUTES, PackstationCron::class);
    }
}
