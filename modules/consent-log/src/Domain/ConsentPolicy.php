<?php

/**
 * Consent domain (no WordPress): missing required ticks, the proof record and its anonymisation.
 */

declare(strict_types=1);

namespace PhpFasty\Module\ConsentLog\Domain;

final class ConsentPolicy
{
    /** Personal order fields erased on request (the email stays the lookup key until the order is gone). */
    public const PERSONAL = ['name', 'phone', 'address', 'city', 'postcode', 'notes'];

    /**
     * Names of required ticks that are not ticked (they are the error codes).
     *
     * @param list<array{name: string, required: bool, page: string}> $definitions
     * @param array<string, bool>                                     $ticked
     *
     * @return list<string>
     */
    public static function missing(array $definitions, array $ticked): array
    {
        $out = [];
        foreach ($definitions as $d) {
            if ($d['required'] && empty($ticked[$d['name']])) {
                $out[] = $d['name'];
            }
        }

        return $out;
    }

    /**
     * Proof record stored on the order.
     *
     * @param array<string, bool> $ticked
     *
     * @return array{time: string, ip: string, version: string, offer: bool, personal_data: bool, newsletter: bool}
     */
    public static function record(array $ticked, string $ip, string $time, string $version): array
    {
        return [
            'time' => $time,
            'ip' => $ip,
            'version' => $version,
            'offer' => !empty($ticked['consent_offer']),
            'personal_data' => !empty($ticked['consent_pd']),
            'newsletter' => !empty($ticked['consent_news']),
        ];
    }

    public static function anonymous(string $field): string
    {
        return 'name' === $field ? '[deleted]' : '';
    }

    /**
     * @param array<string, mixed> $log
     *
     * @return array<string, mixed>
     */
    public static function anonymizeLog(array $log): array
    {
        $log['ip'] = '';
        $log['anonymized'] = true;

        return $log;
    }
}
