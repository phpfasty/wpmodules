<?php

/**
 * Downloads UI for the shop templates: product details of the deliverable (version, compatibility, changelog,
 * live preview — slot product.after) and the signed download links of a paid order (slot checkout.order).
 *
 * @package ModulY\Downloads
 */

declare(strict_types=1);

namespace PhpFasty\Module\Downloads;

final class Slots
{
    public static function t(string $en, string $ru): string
    {
        $locale = function_exists('determine_locale') ? (string) determine_locale() : 'en_US';
        return str_starts_with($locale, 'ru') ? $ru : $en;
    }

    /**
     * Product fields of the deliverable, declared on the latte-shop product type.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function fields(array $fields): array
    {
        return $fields + [
            'version' => ['type' => 'semver', 'label' => self::t('Version', 'Версия')],
            'requires_wp' => ['type' => 'semver', 'label' => self::t('Requires WordPress', 'Требуется WordPress')],
            'requires_php' => ['type' => 'semver', 'label' => self::t('Requires PHP', 'Требуется PHP')],
            'changelog' => [
                'type' => 'text',
                'label' => self::t('Changelog (version|date|notes per line)', 'Изменения (версия|дата|описание, по строке)'),
            ],
            'preview_url' => ['type' => 'url', 'label' => self::t('Live preview URL', 'Ссылка на демо')],
            'download_file' => [
                'type' => 'file',
                'label' => self::t('Download file (attachment ID)', 'Файл для скачивания (ID вложения)'),
                'show_in_rest' => false,
                'private' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return list<array{version: string, date: string, notes: string}>
     */
    public static function changelog(array $fields): array
    {
        $out = [];
        foreach (preg_split('/\R/', (string) ($fields['changelog'] ?? '')) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 3));
            if (count($parts) === 3 && $parts[0] !== '') {
                $out[] = ['version' => $parts[0], 'date' => $parts[1], 'notes' => $parts[2]];
            }
        }
        return $out;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     * @param array<string, mixed> $context
     * @return array<string, list<array<string, mixed>>>
     */
    public static function slots(array $slots, array $context): array
    {
        $page = (array) ($context['page'] ?? []);
        $fields = (array) ($page['fields'] ?? []);
        if (isset($page['product']) && is_array($page['product'])) {
            $rows = [];
            foreach (
                [
                [self::t('Version', 'Версия'), (string) ($fields['version'] ?? '')],
                ['WordPress', ($fields['requires_wp'] ?? '') !== '' ? $fields['requires_wp'] . '+' : ''],
                ['PHP', ($fields['requires_php'] ?? '') !== '' ? $fields['requires_php'] . '+' : ''],
                ] as [$k, $v]
            ) {
                if ((string) $v !== '') {
                    $rows[] = ['label' => $k, 'value' => (string) $v];
                }
            }
            $log = self::changelog($fields);
            $preview = (string) ($fields['preview_url'] ?? '');
            if ($rows !== [] || $log !== [] || $preview !== '') {
                $slots['product.after'][] = [
                    'view' => 'moduly/downloads/details',
                    'data' => [
                        'rows' => $rows,
                        'changelog' => $log,
                        'preview_url' => $preview,
                        'labels' => [
                            'title' => self::t('Compatibility', 'Совместимость'),
                            'changelog' => self::t('Changelog', 'История изменений'),
                            'preview' => self::t('Live preview', 'Живое демо'),
                        ],
                    ],
                ];
            }
        }
        $order = $context['order'] ?? null;
        if (is_array($order) && !empty($order['downloads_available'])) {
            $slots['checkout.order'][] = [
                'view' => 'moduly/downloads/links',
                'data' => [
                    'links' => array_values((array) ($order['downloads'] ?? [])),
                    'labels' => [
                        'title' => self::t('Your downloads', 'Ваши файлы'),
                        'download' => self::t('Download', 'Скачать'),
                        'left' => self::t('downloads left', 'скачиваний осталось'),
                        'until' => self::t('link valid until', 'ссылка действует до'),
                        'pending' => self::t(
                            'Links appear here and in your email as soon as the payment is confirmed.',
                            'Ссылки появятся здесь и в письме сразу после подтверждения оплаты.'
                        ),
                    ],
                ],
            ];
        }
        return $slots;
    }
}
