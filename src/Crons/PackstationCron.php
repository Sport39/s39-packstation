<?php

namespace S39Packstation\Crons;

use Plenty\Modules\Cron\Contracts\CronHandler;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;
use S39Packstation\Services\PackstationService;

/**
 * Alle 15 Minuten: offene Auftraege der letzten 3 Tage pruefen.
 * Faengt Faelle ab, in denen die Lieferadresse beim Ereignis "Auftrag angelegt"
 * noch nicht verknuepft war (z. B. Marktplatz-Import).
 */
class PackstationCron extends CronHandler
{
    const HINT = '/pack\s*-?\s*station|post\s*-?\s*filiale|post\s*-?\s*(id|nummer|nr)|postnr/i';

    public function handle()
    {
        /** @var PackstationService $service */
        $service = pluginApp(PackstationService::class);
        if (!$service->active()) {
            return [];
        }
        try {
            /** @var AuthHelper $auth */
            $auth = pluginApp(AuthHelper::class);
            $candidates = $auth->processUnguarded(function () {
                /** @var OrderRepositoryContract $repo */
                $repo = pluginApp(OrderRepositoryContract::class);
                $repo->setFilters([
                    'orderType' => 1,
                    'createdAtFrom' => date('c', time() - 3 * 86400),
                    'statusTo' => 6.99,
                ]);
                $found = [];
                for ($page = 1; $page <= 10; $page++) {
                    $result = $repo->searchOrders($page, 100, ['addresses']);
                    $rows = $result->getResult();
                    foreach ($rows as $o) {
                        $o = is_array($o) ? $o : $o->toArray();
                        $text = '';
                        foreach (($o['addresses'] ?? []) as $a) {
                            $text .= ' ' . ($a['name1'] ?? '') . ' ' . ($a['name4'] ?? '') . ' ' . ($a['address1'] ?? '') . ' ' . ($a['address2'] ?? '') . ' ' . ($a['address3'] ?? '') . ' ' . ($a['address4'] ?? '');
                        }
                        if (preg_match(self::HINT, $text)) {
                            $found[] = ['id' => (int)$o['id'], 'createdAt' => (string)($o['createdAt'] ?? '')];
                        }
                    }
                    if ($result->isLastPage()) {
                        break;
                    }
                }
                return $found;
            });
            $out = [];
            foreach ($candidates as $c) {
                // "manuell pruefen"-Notiz nur beim ersten Durchlauf nach Auftragseingang
                $created = strtotime($c['createdAt']);
                $fresh = $created && $created > time() - 20 * 60;
                $r = $service->handleOrder($c['id'], false, false, !$fresh);
                $out[] = $c['id'] . ':' . ($r['status'] ?? '');
            }
            $service->note('cron', ['checked' => $out]);
            return $out;
        } catch (\Throwable $e) {
            $service->logError('cron', $e);
            return ['error: ' . $e->getMessage() . ' Zeile ' . $e->getLine()];
        }
    }
}
