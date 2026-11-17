<?php

namespace App\modules\booking\services;

/**
 * What changed between two snapshots of a catering order (GH #1425).
 *
 * The same result feeds two consumers, so they can never disagree about what the applicant did:
 *   - the case entry (bb_hospitality_order_changelog.old_value / new_value, JSONB), and
 *   - the FROM → TO table in the mail to the case officer.
 *
 * Pure: snapshots in, plain arrays out. A snapshot is the shape of
 * HospitalityOrderRepository::getOrderWithLines() — an order row plus `lines` (each with
 * article_name, unit, quantity, unit_price, amount, comment) and `total_amount`.
 *
 * Every text value is CateringText::decode()d to plain text here and nowhere later: the mail
 * body escapes it once at the HTML boundary, and the admin changelog renderer escapes it once
 * with esc().
 *
 * Values are language-neutral strings ("3 stk × 45.00 = 135.00", "27.10.2026 14:00"); the
 * human labels for the fixed keys live in the mail's translation keys, and an article line is
 * labelled with the article's own name.
 */
final class HospitalityOrderDiff
{
    private const VENUE_TIMEZONE = 'Europe/Oslo';

    /**
     * @param array|null $before the order before the change; null when the order was just added
     * @param array      $after  the order after the change (for a cancel: the cancelled order)
     * @return array{
     *   rows: list<array{key: string, label: ?string, from: string, to: string}>,
     *   old: array<string, string>,
     *   new: array<string, string>
     * } `rows` drives the mail table; `old`/`new` are the flat maps stored in the changelog and
     *   contain ONLY the keys that changed. All three are empty when nothing changed.
     */
    public static function between(?array $before, array $after): array
    {
        $b = $before === null ? [] : self::flatten($before);
        $a = self::flatten($after);

        $rows = [];
        $old = [];
        $new = [];

        foreach (self::orderedKeys($b, $a) as $key) {
            // A new order is always 'pending'; that is not something the applicant changed.
            if ($before === null && $key === 'status') {
                continue;
            }
            $from = $b[$key]['value'] ?? '';
            $to = $a[$key]['value'] ?? '';
            if ($from === $to) {
                continue;
            }
            $label = $a[$key]['label'] ?? $b[$key]['label'] ?? null;
            $rows[] = ['key' => $key, 'label' => $label, 'from' => $from, 'to' => $to];
            if ($from !== '') {
                $old[$key] = $from;
            }
            if ($to !== '') {
                $new[$key] = $to;
            }
        }

        return ['rows' => $rows, 'old' => $old, 'new' => $new];
    }

    public static function isEmpty(array $diff): bool
    {
        return $diff['rows'] === [];
    }

    /**
     * One order as label/value rows (non-empty values only) — what a CANCELLED order contained,
     * for the mail, since a cancel's diff alone is just "status: pending → cancelled".
     *
     * @return list<array{key: string, label: ?string, value: string}>
     */
    public static function summary(array $order): array
    {
        $rows = [];
        foreach (self::flatten($order) as $key => $entry) {
            if ($key === 'status' || $entry['value'] === '') {
                continue;
            }
            $rows[] = ['key' => $key, 'label' => $entry['label'], 'value' => $entry['value']];
        }
        return $rows;
    }

    /**
     * @return array<string, array{value: string, label: ?string}>
     */
    private static function flatten(array $order): array
    {
        $out = [];

        $out['hospitality'] = ['value' => CateringText::decode($order['hospitality_name'] ?? ''), 'label' => null];
        $out['status'] = ['value' => (string)($order['status'] ?? ''), 'label' => null];
        $out['serving_time'] = ['value' => self::formatServingTime($order['serving_time_iso'] ?? null), 'label' => null];
        $out['location'] = ['value' => CateringText::decode($order['location_name'] ?? ''), 'label' => null];
        $out['comment'] = ['value' => CateringText::decode($order['comment'] ?? ''), 'label' => null];
        $out['special_requirements'] = ['value' => CateringText::decode($order['special_requirements'] ?? ''), 'label' => null];

        $seen = [];
        foreach (($order['lines'] ?? []) as $line) {
            $name = CateringText::decode($line['article_name'] ?? '');
            $n = ($seen[$name] = ($seen[$name] ?? 0) + 1);
            $label = $n > 1 ? "{$name} #{$n}" : $name;
            $out['line:' . $label] = ['value' => self::describeLine($line), 'label' => $label];
        }

        $out['total'] = ['value' => self::money($order['total_amount'] ?? 0), 'label' => null];

        return $out;
    }

    /**
     * Fixed keys first in a stable order, then the article lines in the order they first appear.
     *
     * @param array<string, mixed> $b
     * @param array<string, mixed> $a
     * @return list<string>
     */
    private static function orderedKeys(array $b, array $a): array
    {
        $fixed = ['hospitality', 'status', 'serving_time', 'location', 'comment', 'special_requirements'];
        $keys = $fixed;
        foreach ([$b, $a] as $side) {
            foreach (array_keys($side) as $k) {
                if (str_starts_with($k, 'line:') && !in_array($k, $keys, true)) {
                    $keys[] = $k;
                }
            }
        }
        $keys[] = 'total';
        return $keys;
    }

    private static function describeLine(array $line): string
    {
        $qty = self::trimNumber((string)($line['quantity'] ?? '0'));
        $unit = CateringText::decode($line['unit'] ?? '');
        $price = self::money($line['unit_price'] ?? 0);
        $amount = self::money($line['amount'] ?? ((float)($line['quantity'] ?? 0) * (float)($line['unit_price'] ?? 0)));

        $text = trim("{$qty} {$unit}") . " × {$price} = {$amount}";

        $comment = CateringText::decode($line['comment'] ?? '');
        if ($comment !== '') {
            $text .= ' — ' . $comment;
        }
        return $text;
    }

    /** "3.00" → "3", "2.50" → "2.5", so a quantity reads the way the applicant typed it. */
    private static function trimNumber(string $n): string
    {
        if (!str_contains($n, '.')) {
            return $n;
        }
        return rtrim(rtrim($n, '0'), '.');
    }

    private static function money(mixed $amount): string
    {
        return number_format((float)$amount, 2, '.', '');
    }

    /**
     * serving_time_iso is held as naive UTC; officers read venue wall-clock.
     */
    private static function formatServingTime(?string $naiveUtc): string
    {
        if ($naiveUtc === null || $naiveUtc === '') {
            return '';
        }
        try {
            $instant = new \DateTimeImmutable($naiveUtc, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return $naiveUtc;
        }
        return $instant->setTimezone(new \DateTimeZone(self::VENUE_TIMEZONE))->format('d.m.Y H:i');
    }
}
