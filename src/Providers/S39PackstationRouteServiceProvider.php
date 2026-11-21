<?php

namespace S39Packstation\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;
use Plenty\Plugin\Routing\ApiRouter;

class S39PackstationRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router, ApiRouter $api)
    {
        $api->version(['v1'], ['namespace' => 'S39Packstation\Controllers', 'middleware' => 'oauth'], function ($api) {
            $api->get('s39packstation/fix', 'FixController@run');
        });
    }
}
