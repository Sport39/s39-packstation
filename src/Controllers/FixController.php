<?php

namespace S39Packstation\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use S39Packstation\Services\PackstationService;

/**
 * Manuell (nur mit Backend-Login / REST-Token):
 *   GET /rest/s39packstation/fix?orderId=123            -> korrigieren
 *   GET /rest/s39packstation/fix?orderId=123&dryRun=1   -> nur anzeigen
 *   &force=1 auch fuer Auftraege ab Status 7
 */
class FixController extends Controller
{
    public function run(Request $request, Response $response, PackstationService $service)
    {
        $orderId = (int)$request->get('orderId', 0);
        if ($orderId <= 0) {
            return $response->json(['error' => 'orderId fehlt'], 400);
        }
        $dry = (string)$request->get('dryRun', '0') === '1';
        $force = (string)$request->get('force', '0') === '1';
        return $response->json($service->handleOrder($orderId, $dry, $force));
    }
}
