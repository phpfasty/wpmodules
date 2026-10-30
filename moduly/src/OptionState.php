<?php

declare(strict_types=1);

namespace PhpFasty\Moduly\Wp;

use PhpFasty\Moduly\Kernel\ModuleRecord;
use PhpFasty\Moduly\Kernel\StateStoreInterface;

/**
 * Module records in one small autoloaded option `moduly_modules` (read on every request).
 * Extra install facts (signed, key, installed at) are kept in `moduly_modules_meta` (not autoloaded).
 */
final class OptionState implements StateStoreInterface
{
    public const OPTION = 'moduly_modules';
    public const META = 'moduly_modules_meta';

    public function load(): array
    {
        $raw = get_option(self::OPTION, []);
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $id => $rec) {
                if (is_string($id) && is_array($rec)) {
                    $out[$id] = ModuleRecord::fromArray($id, $rec);
                }
            }
        }
        return $out;
    }

    public function save(array $records): void
    {
        $data = [];
        foreach ($records as $id => $rec) {
            $data[$id] = $rec->toArray();
        }
        ksort($data);
        update_option(self::OPTION, $data, true);
    }

    /** @return array<string, array{signed: bool, key: string, installed: int}> */
    public static function meta(): array
    {
        $raw = get_option(self::META, []);
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $id => $m) {
                if (is_string($id) && is_array($m)) {
                    $out[$id] = [
                        'signed' => (bool) ($m['signed'] ?? false),
                        'key' => is_string($m['key'] ?? null) ? $m['key'] : '',
                        'installed' => is_int($m['installed'] ?? null) ? $m['installed'] : 0,
                    ];
                }
            }
        }
        return $out;
    }

    public static function setMeta(string $id, bool $signed, string $key): void
    {
        $meta = self::meta();
        $meta[$id] = ['signed' => $signed, 'key' => $key, 'installed' => time()];
        update_option(self::META, $meta, false);
    }
}
