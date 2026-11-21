<?php

namespace S39Packstation\Services;

/**
 * Reine Text-Logik (ohne Plenty-Abhaengigkeiten), damit sie testbar bleibt.
 *
 * analyse() bekommt die Adressfelder und liefert:
 *  - status 'none'    : keine Packstation/Postfiliale erkannt
 *  - status 'ok'      : bereits im richtigen Format
 *  - status 'fix'     : eindeutig erkannt, 'data' enthaelt die neuen Felder
 *  - status 'manual'  : Packstation erkannt, aber Nummer oder Postnummer nicht eindeutig
 */
class PackstationParser
{
    const FIELDS = ['name1', 'name4', 'address1', 'address2', 'address3', 'address4'];

    public function analyse(array $a, string $existingPostnummer = ''): array
    {
        $parts = [];
        foreach (self::FIELDS as $f) {
            $v = trim((string)($a[$f] ?? ''));
            if ($v !== '') {
                $parts[] = $v;
            }
        }
        $text = implode(' | ', $parts);
        $lower = mb_strtolower($text, 'UTF-8');

        $kind = '';
        if (preg_match('/pack\s*-?\s*station/u', $lower)) {
            $kind = 'PACKSTATION';
        } elseif (preg_match('/post\s*-?\s*filiale/u', $lower)) {
            $kind = 'POSTFILIALE';
        }
        $hasPostHint = (bool)preg_match('/post\s*-?\s*(id|nummer|nr)|postnr|dhl\s*-?\s*kunden/u', $lower);
        if ($kind === '') {
            return $hasPostHint
                ? ['status' => 'manual', 'reason' => 'Postnummer-Hinweis ohne Packstation/Postfiliale', 'text' => $text]
                : ['status' => 'none'];
        }

        $addr1 = mb_strtoupper(trim((string)($a['address1'] ?? '')), 'UTF-8');
        $addr2 = trim((string)($a['address2'] ?? ''));
        $name1 = preg_replace('/\s+/', '', (string)($a['name1'] ?? ''));

        // Nummer der Packstation/Filiale
        $number = '';
        $numbers = [];
        if (preg_match_all('/(?:pack\s*-?\s*station|post\s*-?\s*filiale)\s*(?:nr\.?|nummer)?\s*[:#]?\s*(\d{1,4})(?!\d)(?!\s\d{3,})/u', $lower, $m)) {
            $numbers = array_values(array_unique($m[1]));
        }
        if (count($numbers) === 1) {
            $number = $numbers[0];
        } elseif (count($numbers) === 0 && in_array($addr1, ['PACKSTATION', 'POSTFILIALE'], true) && preg_match('/^\d{1,4}$/', $addr2)) {
            $number = $addr2;
        }

        // Postnummer (DHL-Kundennummer, 6-10 Ziffern)
        $post = '';
        $posts = [];
        if (preg_match_all('/(?:post\s*-?\s*(?:id|nummer|nr\.?)|postnr\.?|dhl\s*-?\s*kunden\s*-?\s*(?:nummer|nr\.?))\s*[:#]?\s*(\d[\d\s]{4,14}\d)/u', $lower, $m)) {
            foreach ($m[1] as $raw) {
                $d = preg_replace('/\s+/', '', $raw);
                if (strlen($d) >= 6 && strlen($d) <= 10) {
                    $posts[$d] = true;
                }
            }
        }
        $posts = array_keys($posts);
        if (count($posts) === 1) {
            $post = (string)$posts[0];
        } elseif (count($posts) === 0) {
            // ohne Stichwort: alleinstehende 6-10-stellige Zahl, die nicht die PLZ ist
            $plz = preg_replace('/\D/', '', (string)($a['postalCode'] ?? ''));
            $cand = [];
            if (preg_match_all('/(?<!\d)(\d{6,10})(?!\d)/', $text, $m)) {
                foreach ($m[1] as $d) {
                    if ($d !== $plz) {
                        $cand[$d] = true;
                    }
                }
            }
            if (count($cand) === 1) {
                $post = (string)array_keys($cand)[0];
            } elseif (count($cand) === 0 && preg_match('/^\d{6,10}$/', (string)$existingPostnummer)) {
                $post = (string)$existingPostnummer;
            }
        }

        if ($number === '' || $post === '') {
            return [
                'status' => 'manual',
                'reason' => $number === '' ? 'Nummer der ' . ucfirst(strtolower($kind)) . ' nicht eindeutig' : 'Postnummer nicht eindeutig',
                'text' => $text,
                'kind' => $kind,
                'number' => $number,
                'postnummer' => $post,
            ];
        }

        // Zusatzfelder nur leeren, wenn dort Packstation-/Postnummer-Angaben stehen
        $clear = [];
        foreach (['name4', 'address3', 'address4'] as $f) {
            $v = mb_strtolower(trim((string)($a[$f] ?? '')), 'UTF-8');
            if ($v !== '' && $this->isHint($v, $number, $post)) {
                $clear[] = $f;
            }
        }

        $isOk = $addr1 === $kind
            && $addr2 === $number
            && $name1 === $post
            && count($clear) === 0
            && (string)$existingPostnummer === $post;
        if ($isOk) {
            return ['status' => 'ok', 'kind' => $kind, 'number' => $number, 'postnummer' => $post];
        }

        return [
            'status' => 'fix',
            'kind' => $kind,
            'number' => $number,
            'postnummer' => $post,
            'text' => $text,
            'data' => array_merge([
                'name1' => $post,
                'address1' => $kind,
                'address2' => $number,
            ], array_fill_keys($clear, '')),
        ];
    }

    private function isHint(string $v, string $number, string $post): bool
    {
        if (preg_match('/pack\s*-?\s*station|post\s*-?\s*filiale|post\s*-?\s*(id|nummer|nr)|postnr|dhl/u', $v)) {
            return true;
        }
        $digits = preg_replace('/\s+/', '', $v);
        return $digits === $post || $digits === $number;
    }
}
