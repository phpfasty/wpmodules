<?php

/**
 * Reviews UI for the shop templates (slot product.after): rating summary, approved reviews, buyer form.
 *
 * @package ModulY\Reviews
 */

declare(strict_types=1);

namespace PhpFasty\Module\Reviews;

final class Slots
{
    public static function t(string $en, string $ru): string
    {
        $locale = function_exists('determine_locale') ? (string) determine_locale() : 'en_US';
        return str_starts_with($locale, 'ru') ? $ru : $en;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public static function viewModel(array $context): ?array
    {
        $page = (array) ($context['page'] ?? []);
        $product = (array) ($page['product'] ?? []);
        if (empty($product['reviews_available'])) {
            return null;
        }
        $fields = (array) ($page['fields'] ?? []);
        $list = [];
        foreach ((array) ($product['reviews_list'] ?? []) as $r) {
            $rating = max(0, min(5, (int) ($r['rating'] ?? 0)));
            $list[] = [
                'name' => (string) ($r['author'] ?? ''),
                'date' => (string) ($r['date'] ?? ''),
                'text' => (string) ($r['text'] ?? ''),
                'rating' => $rating,
                'stars' => str_repeat('★', $rating) . str_repeat('☆', 5 - $rating),
                'verified' => !empty($r['verified']),
            ];
        }
        // Stored aggregate (meta) first; fall back to the approved list so the summary never contradicts it.
        $agg = ['rating' => (float) ($fields['rating'] ?? 0), 'count' => (int) ($fields['reviews'] ?? 0)];
        if ($agg['count'] === 0 && $list !== []) {
            $a = Reviews::aggregate(array_column($list, 'rating'));
            $agg = ['rating' => $a['avg'], 'count' => $a['count']];
        }

        return [
            'post_id' => (int) ($page['id'] ?? 0),
            'action' => (string) ($product['review_action'] ?? ''),
            'rating' => number_format($agg['rating'], 1),
            'count' => $agg['count'],
            'reviews' => $list,
            'rating_options' => array_map(static fn (int $n): array => ['value' => (string) $n, 'stars' => str_repeat('★', $n)], [5, 4, 3, 2, 1]),
            'labels' => [
                'title' => self::t('Reviews', 'Отзывы'),
                'none' => self::t('No reviews yet.', 'Отзывов пока нет.'),
                'verified' => self::t('Verified buyer', 'Подтверждённый покупатель'),
                'write' => self::t('Write a review', 'Написать отзыв'),
                'note' => self::t(
                    'Only buyers can review: use the email of your paid order.',
                    'Отзыв могут оставить только покупатели: укажите email оплаченного заказа.'
                ),
                'name' => self::t('Your name', 'Ваше имя'),
                'email' => self::t('Order email', 'Email заказа'),
                'rating' => self::t('Rating', 'Оценка'),
                'text' => self::t('Your review', 'Ваш отзыв'),
                'submit' => self::t('Send review', 'Отправить отзыв'),
            ],
        ];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $slots
     * @param array<string, mixed> $context
     * @return array<string, list<array<string, mixed>>>
     */
    public static function slots(array $slots, array $context): array
    {
        $vm = self::viewModel($context);
        if ($vm !== null) {
            $slots['product.after'][] = ['view' => 'moduly/reviews/reviews', 'data' => $vm];
        }
        return $slots;
    }
}
