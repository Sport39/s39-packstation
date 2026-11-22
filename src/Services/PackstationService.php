<?php

namespace S39Packstation\Services;

use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\Comment\Contracts\CommentRepositoryContract;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;

/**
 * Prueft die Lieferadresse eines Auftrags und schreibt Packstation-/Postfiliale-Adressen
 * ins DHL-Format um (Name 1 = Postnummer, Strasse = PACKSTATION, Hausnummer = Nummer,
 * Adressoption 6 = Postnummer).
 */
class PackstationService
{
    use Loggable;

    const OPTION_POSTNUMMER = 6;
    const RELATION_DELIVERY = 2;

    /** @var ConfigRepository */
    private $config;

    public function __construct(ConfigRepository $config)
    {
        $this->config = $config;
    }

    public function active(): bool
    {
        return (string)$this->config->get('S39Packstation.active', '1') !== '0';
    }

    private function comments(): bool
    {
        return (string)$this->config->get('S39Packstation.comment', '1') !== '0';
    }

    /**
     * @return array Ergebnis mit status none|ok|fix|fixed|manual|skip|error
     */
    public function handleOrder(int $orderId, bool $dryRun = false, bool $force = false): array
    {
        /** @var AuthHelper $auth */
        $auth = pluginApp(AuthHelper::class);
        try {
            return $auth->processUnguarded(function () use ($orderId, $dryRun, $force) {
                return $this->run($orderId, $dryRun, $force);
            });
        } catch (\Throwable $e) {
            $this->logError('handleOrder', $e, ['orderId' => $orderId]);
            if (!$dryRun) {
                $this->comment($orderId, 'S39Packstation: Fehler beim Pruefen der Lieferadresse (' . $e->getMessage() . ', Zeile ' . $e->getLine() . '). Bitte Adresse vor dem Labeldruck pruefen.');
            }
            return ['status' => 'error', 'orderId' => $orderId, 'message' => $e->getMessage(), 'line' => $e->getLine()];
        }
    }

    private function run(int $orderId, bool $dryRun, bool $force): array
    {
        /** @var OrderRepositoryContract $orders */
        $orders = pluginApp(OrderRepositoryContract::class);
        $order = $orders->findOrderById($orderId, ['addresses']);
        if (!$order) {
            return ['status' => 'error', 'orderId' => $orderId, 'message' => 'Auftrag nicht gefunden'];
        }
        if (!$force && (float)$order->statusId >= 7) {
            return ['status' => 'skip', 'orderId' => $orderId, 'message' => 'Auftrag schon im Warenausgang (Status ' . $order->statusId . ')'];
        }

        $addressId = 0;
        foreach ($order->addressRelations as $rel) {
            if ((int)$rel->typeId === self::RELATION_DELIVERY) {
                $addressId = (int)$rel->addressId;
            }
        }
        if ($addressId <= 0) {
            return ['status' => 'none', 'orderId' => $orderId, 'message' => 'keine Lieferadresse'];
        }

        /** @var AddressRepositoryContract $addresses */
        $addresses = pluginApp(AddressRepositoryContract::class);
        $address = $addresses->findAddressById($addressId);
        $raw = $address->toArray();
        $fields = [];
        foreach (['name1', 'name2', 'name3', 'name4', 'address1', 'address2', 'address3', 'address4', 'postalCode', 'town', 'countryId'] as $f) {
            $fields[$f] = $raw[$f] ?? '';
        }

        $optionId = 0;
        $existing = '';
        foreach ($addresses->findAddressOptions($addressId, self::OPTION_POSTNUMMER) as $opt) {
            if ((int)$opt->typeId === self::OPTION_POSTNUMMER) {
                $optionId = (int)$opt->id;
                $existing = preg_replace('/\s+/', '', (string)$opt->value);
            }
        }

        /** @var PackstationParser $parser */
        $parser = pluginApp(PackstationParser::class);
        $res = $parser->analyse($fields, $existing);
        $res['orderId'] = $orderId;
        $res['addressId'] = $addressId;
        $res['before'] = $fields;

        if ($res['status'] === 'manual') {
            if (!$dryRun) {
                $this->note('manual', $res);
                $this->comment($orderId, 'S39Packstation: Lieferadresse sieht nach Packstation/Postfiliale aus, konnte aber nicht automatisch korrigiert werden (' . $res['reason'] . '). Bitte vor dem Labeldruck pruefen: Name 1 = Postnummer, Strasse = PACKSTATION, Hausnummer = Nummer der Packstation.');
            }
            return $res;
        }
        if ($res['status'] !== 'fix' || $dryRun) {
            return $res;
        }

        // Vollstaendigen Datensatz schicken (Teil-Updates werden sonst abgelehnt)
        $data = array_merge($fields, $res['data']);
        $addresses->updateAddress($data, $addressId);

        if ($optionId > 0) {
            if ($existing !== $res['postnummer']) {
                $addresses->updateAddressOption(['typeId' => self::OPTION_POSTNUMMER, 'value' => $res['postnummer']], $optionId);
            }
        } else {
            $addresses->createAddressOptions([['typeId' => self::OPTION_POSTNUMMER, 'value' => $res['postnummer']]], $addressId);
        }

        $res['status'] = 'fixed';
        $res['after'] = $data;
        $this->note('fixed', $res);
        $this->comment(
            $orderId,
            'S39Packstation: Lieferadresse fuer DHL umgeschrieben. Vorher: "' . $res['text'] . '". Jetzt: Name 1 = ' . $res['postnummer']
            . ', ' . $res['kind'] . ' ' . $res['number'] . ', Postnummer-Option gesetzt.'
        );
        return $res;
    }

    private function comment(int $orderId, string $text)
    {
        if (!$this->comments()) {
            return;
        }
        try {
            /** @var CommentRepositoryContract $repo */
            $repo = pluginApp(CommentRepositoryContract::class);
            $repo->createComment([
                'referenceType' => 'order',
                'referenceValue' => $orderId,
                'text' => $text,
                'isVisibleForContact' => false,
                'userId' => (int)$this->config->get('S39Packstation.commentUserId', '6'),
            ]);
        } catch (\Throwable $e) {
            $this->logError('comment', $e, ['orderId' => $orderId]);
        }
    }

    public function note(string $key, array $data)
    {
        try {
            $this->getLogger('S39Packstation')->info('S39Packstation::log.' . $key, $data);
        } catch (\Throwable $x) {
        }
    }

    public function logError(string $where, \Throwable $e, array $ctx = [])
    {
        try {
            $this->getLogger('S39Packstation')->error('S39Packstation::log.error', array_merge($ctx, [
                'where' => $where,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]));
        } catch (\Throwable $x) {
        }
    }
}
