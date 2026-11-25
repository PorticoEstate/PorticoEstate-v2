<?php

namespace App\modules\booking\services;

/**
 * Puts a catering change by the applicant into words (GH #1425): the ONE formatter behind the
 * «Endringslogg» on the admin order page, the line in the application's «Historikk» and the body
 * of the mail to the case officer, so the three can never word the same change differently.
 *
 * Input is what HospitalityOrderDiff stores in a case entry (bb_hospitality_order_changelog
 * old_value / new_value): flat maps of language-neutral strings. Entries written before this
 * formatter existed are read the same way (parseLine / parseTotal accept both stored forms).
 *
 * Words come from the booking .lang keys through the global lang(), in whatever language is in
 * force: the officer's own session on the admin pages, the officer's forced language in the mail
 * (CateringMailRenderer). $lang is passed in only for the date and number formats, so they follow
 * the same language as the words.
 *
 * PRICES are shown incl. VAT, worked out PER LINE from that line's own stored rate (operator D2,
 * pe-pm 46234: never an assumed 25 %), and marked «inkl. mva». A line whose rate is unknown (a tax
 * code with no fm_ecomva row, or an entry from before the rate was stored) is shown ex VAT and
 * marked «ekskl. mva» — never as if it were incl.
 *
 * Output is PLAIN TEXT. Escaping is the caller's job, exactly once: Twig autoescape in the mail,
 * esc() in the admin JS.
 */
final class CateringChangeText
{
    public const KIND_ADDED = 'added';
    public const KIND_CHANGED = 'changed';
    public const KIND_CANCELLED = 'cancelled';

    /** The case-entry comments recordApplicantChange() has always written, by kind. */
    public const ENTRY_COMMENTS = [
        self::KIND_ADDED => 'Order added by applicant via Min side',
        self::KIND_CHANGED => 'Order changed by applicant via Min side',
        self::KIND_CANCELLED => 'Order cancelled by applicant via Min side',
    ];

    private const VENUE_TIMEZONE = 'Europe/Oslo';
    private const CONTEXT_KEYS = ['hospitality', 'serving_time', 'location'];
    private const TEXT_KEYS = ['comment', 'special_requirements'];
    private const KNOWN_UNITS = ['each', 'kg', 'm', 'm2', 'minute', 'hour', 'day'];
    private const LOCALES = ['no' => 'nb_NO', 'nn' => 'nn_NO', 'en' => 'en_GB'];

    /**
     * Which of the three a stored applicant_change entry is. The comment decides; an entry whose
     * comment is not one of the three is judged by its values.
     */
    public static function kindOf(?string $comment, array $old, array $new): string
    {
        $kind = array_search((string)$comment, self::ENTRY_COMMENTS, true);
        if (is_string($kind)) {
            return $kind;
        }
        if (($new['status'] ?? null) === 'cancelled') {
            return self::KIND_CANCELLED;
        }
        return $old === [] ? self::KIND_ADDED : self::KIND_CHANGED;
    }

    /**
     * @param array{kind: string, actor?: ?string, changed_at?: ?string, old?: array, new?: array} $entry
     *        `changed_at` is an instant (ISO-8601 with offset, as HospitalityOrderRepository::
     *        utcToVenueIso gives it); for an added or cancelled order `new` holds what the order
     *        contains, for a change `old` / `new` hold what changed
     * @param array{hospitality?: ?string, serving_time?: ?string, location?: ?string} $context the
     *        order as it is now, for what the entry itself does not say; serving_time is the
     *        stored "d.m.Y H:i" venue form
     * @return array{title: string, byline: string, heading: string, context: string, lines: list<string>}
     *         title «Lagt til av søker»; byline «Kari Nordmann, 4. juni 2029 kl. 15:00»; heading
     *         the two joined; context «Kantina · 4. juni 2029 kl. 15:00 · Storsal»; lines one per
     *         field or article line
     */
    public static function describe(array $entry, array $context, string $lang): array
    {
        $kind = $entry['kind'];
        if (!isset(self::ENTRY_COMMENTS[$kind])) {
            throw new \InvalidArgumentException("Unknown catering change kind '{$kind}'");
        }
        $old = self::strings($entry['old'] ?? []);
        $new = self::strings($entry['new'] ?? []);

        $title = lang("booking.catering_log_{$kind}_by_applicant");
        $byline = implode(', ', array_filter([
            CateringText::decode($entry['actor'] ?? ''),
            self::instantText($entry['changed_at'] ?? null, $lang),
        ], static fn(string $s): bool => $s !== ''));

        // What the order is: the entry's own values where it has them (an addition records them),
        // else the order as it is now.
        $own = $kind === self::KIND_CHANGED ? [] : $new;
        $contextParts = [];
        foreach (self::CONTEXT_KEYS as $key) {
            $value = $own[$key] ?? (isset($context[$key]) ? (string)$context[$key] : '');
            if ($value !== '') {
                $contextParts[] = self::valueText($key, $value, $lang);
            }
        }

        $lines = [];
        if ($kind === self::KIND_CHANGED) {
            foreach (self::orderedKeys($old, $new) as $key) {
                $lines[] = lang(
                    'booking.catering_log_from_to',
                    self::label($key),
                    self::valueText($key, $old[$key] ?? '', $lang),
                    self::valueText($key, $new[$key] ?? '', $lang)
                );
            }
        } else {
            foreach (self::orderedKeys([], $new) as $key) {
                if (in_array($key, self::CONTEXT_KEYS, true) || $key === 'status') {
                    continue;
                }
                $lines[] = self::label($key) . ': ' . self::valueText($key, $new[$key], $lang);
            }
        }

        return [
            'title' => $title,
            'byline' => $byline,
            'heading' => $byline === '' ? $title : "{$title} — {$byline}",
            'context' => implode(' · ', $contextParts),
            'lines' => $lines,
        ];
    }

    /**
     * The one line the application's «Historikk» shows for an entry:
     * «Bevertning lagt til — Kantina, 4. juni 2029 kl. 15:00. Endret av søker.»
     *
     * @param array $entry   as describe()
     * @param array $context as describe()
     */
    public static function historyLine(array $entry, array $context, string $lang): string
    {
        $kind = $entry['kind'];
        if (!isset(self::ENTRY_COMMENTS[$kind])) {
            throw new \InvalidArgumentException("Unknown catering change kind '{$kind}'");
        }
        $new = self::strings($entry['new'] ?? []);
        $own = $kind === self::KIND_CHANGED ? [] : $new;
        $hospitality = $own['hospitality'] ?? (string)($context['hospitality'] ?? '');
        $serving = $own['serving_time'] ?? (string)($context['serving_time'] ?? '');

        return lang(
            "booking.catering_history_{$kind}",
            $hospitality,
            $serving === '' ? lang('booking.catering_mail_nothing') : self::valueText('serving_time', $serving, $lang)
        );
    }

    /**
     * The order as it is now (a getOrderWithLines() snapshot), in the stored value forms describe()
     * reads, for what an entry does not say itself: a change records only what changed.
     *
     * @return array{hospitality: string, serving_time: string, location: string}
     */
    public static function contextOf(array $order): array
    {
        $values = [];
        foreach (HospitalityOrderDiff::summary($order) as $row) {
            $values[$row['key']] = $row['value'];
        }
        return [
            'hospitality' => $values['hospitality'] ?? '',
            'serving_time' => $values['serving_time'] ?? '',
            'location' => $values['location'] ?? '',
        ];
    }

    /**
     * The application's «Historikk» items for the applicant's catering entries (S4), shaped like the
     * serialized ApplicationComment rows they are shown among — time, author, comment, type — plus
     * `type_label` («Bevertning») and the `changelog_id` they come from. No comment row is written
     * for them: that table is listed on the applicant's legacy page and counted by
     * getCommentStats, and these are the officer's view of the case entries.
     *
     * @param list<array> $rows HospitalityOrderRepository::getApplicantChangesByApplication()
     * @return list<array{id: null, changelog_id: int, time: ?string, author: string, comment: string, type: string, type_label: string}>
     */
    public static function historyItems(array $rows, string $lang): array
    {
        $items = [];
        foreach ($rows as $row) {
            $old = is_array($row['old_value'] ?? null) ? $row['old_value'] : [];
            $new = is_array($row['new_value'] ?? null) ? $row['new_value'] : [];
            $items[] = [
                'id' => null,
                'changelog_id' => (int)$row['id'],
                'time' => $row['changed_at'] ?? null,
                'author' => CateringText::decode($row['booking_user_name'] ?? ''),
                'comment' => self::historyLine(
                    ['kind' => self::kindOf($row['comment'] ?? null, $old, $new), 'new' => $new],
                    self::contextOf($row),
                    $lang
                ),
                'type' => 'catering',
                'type_label' => lang('booking.catering_history_tag'),
            ];
        }
        return $items;
    }

    /**
     * Comments and catering items as one list, newest first — the order the Historikk shows. The
     * sort is stable: at the same instant a comment stays ahead of a catering item.
     */
    public static function mergeHistory(array $comments, array $items): array
    {
        $all = array_merge($comments, $items);
        $at = static function (array $row): int {
            try {
                return (new \DateTimeImmutable((string)($row['time'] ?? '')))->getTimestamp();
            } catch (\Exception $e) {
                return 0;
            }
        };
        usort($all, static fn(array $a, array $b): int => $at($b) <=> $at($a));
        return $all;
    }

    /** «Avbestillingsfrist: 3. juni 2029 kl. 15:00» for an instant. */
    public static function deadlineLine(\DateTimeInterface $until, string $lang): string
    {
        return lang('booking.catering_mail_cancel_deadline', self::dateTimeText($until, $lang));
    }

    // ---------------------------------------------------------------------------------------------

    /** Fixed keys first in a stable order, then the article lines, then the total. */
    private static function orderedKeys(array $old, array $new): array
    {
        $fixed = ['hospitality', 'status', 'serving_time', 'location', 'comment', 'special_requirements'];
        $all = array_keys($old + $new);
        $keys = array_values(array_filter($fixed, static fn(string $k): bool => in_array($k, $all, true)));
        foreach ($all as $k) {
            if (str_starts_with($k, 'line:') && !in_array($k, $keys, true)) {
                $keys[] = $k;
            }
        }
        foreach ($all as $k) {
            if (!in_array($k, $keys, true) && $k !== 'total') {
                $keys[] = $k;  // a key this formatter does not know: shown, not dropped
            }
        }
        if (in_array('total', $all, true)) {
            $keys[] = 'total';
        }
        return $keys;
    }

    private static function label(string $key): string
    {
        if (str_starts_with($key, 'line:')) {
            return substr($key, 5);   // an article line is labelled with the article's own name
        }
        if (in_array($key, ['hospitality', 'serving_time', 'location', 'comment', 'special_requirements', 'total'], true)) {
            return lang('booking.catering_field_' . $key);
        }
        if ($key === 'status') {
            return lang('booking.status');
        }
        return $key;
    }

    private static function valueText(string $key, string $value, string $lang): string
    {
        if ($value === '') {
            return lang('booking.catering_mail_nothing');
        }
        if (str_starts_with($key, 'line:')) {
            return self::lineText($value, $lang);
        }
        switch ($key) {
            case 'serving_time':
                $at = \DateTimeImmutable::createFromFormat('!d.m.Y H:i', $value, new \DateTimeZone(self::VENUE_TIMEZONE));
                return $at === false ? $value : self::dateTimeText($at, $lang);
            case 'total':
                $total = HospitalityOrderDiff::parseTotal($value);
                if ($total === null) {
                    return $value;
                }
                return $total['incl'] !== null
                    ? self::amountText((int)round((float)$total['incl'] * 100), $lang) . ' ' . lang('booking.catering_log_incl_vat')
                    : self::amountText((int)round((float)$total['amount'] * 100), $lang) . ' ' . lang('booking.catering_log_excl_vat');
            case 'status':
                $map = ['pending' => 'pending_status', 'confirmed' => 'confirmed', 'delivered' => 'delivered', 'cancelled' => 'cancelled'];
                return isset($map[$value]) ? lang('booking.' . $map[$value]) : $value;
            default:
                return $value;
        }
    }

    /**
     * «2 stk × 12,50 kr = 25 kr inkl. mva — uten nøtter». The unit price incl. VAT is rounded to the
     * øre for showing; the line amount incl. VAT is the one stored with the entry (the ex amount
     * plus its VAT rounded once, HospitalityOrderDiff::lineInclOre), never the rounded unit price
     * multiplied.
     */
    private static function lineText(string $value, string $lang): string
    {
        $p = HospitalityOrderDiff::parseLine($value);
        if ($p === null) {
            return $value;
        }
        $unit = $p['unit'];
        $unitKey = $unit === 'dag' ? 'day' : $unit;
        if (in_array($unitKey, self::KNOWN_UNITS, true)) {
            $unit = lang('booking.unit_' . $unitKey);
        }

        if ($p['vat'] !== null) {
            $vat = (float)$p['vat'];
            $unitOre = (int)round(round((float)$p['unit_price'] * 100) * (100 + $vat) / 100);
            // The incl. amount the entry stored when it was written (what was shown then); worked
            // out the same way only for an entry that has the rate but not the amount.
            $amountOre = $p['incl'] !== null
                ? (int)round((float)$p['incl'] * 100)
                : HospitalityOrderDiff::lineInclOre((float)$p['amount'], $vat);
            $basis = lang('booking.catering_log_incl_vat');
        } else {
            $unitOre = (int)round((float)$p['unit_price'] * 100);
            $amountOre = (int)round((float)$p['amount'] * 100);
            $basis = lang('booking.catering_log_excl_vat');
        }

        $text = lang(
            'booking.catering_log_line',
            self::numberText($p['quantity'], $lang),
            $unit,
            self::amountText($unitOre, $lang),
            self::amountText($amountOre, $lang)
        );
        $text = preg_replace('/ {2,}/', ' ', trim($text)) . ' ' . $basis;
        if ($p['comment'] !== '') {
            $text .= ' — ' . $p['comment'];
        }
        return $text;
    }

    /** «1 600 kr», «12,50 kr» (no/nn); «NOK 1,600», «NOK 12.50» (en). Whole kroner without decimals. */
    private static function amountText(int $ore, string $lang): string
    {
        $decimals = $ore % 100 === 0 ? 0 : 2;
        $number = $lang === 'en'
            ? number_format($ore / 100, $decimals, '.', ',')
            : number_format($ore / 100, $decimals, ',', "\u{00A0}");
        return lang('booking.catering_log_amount', $number);
    }

    /** "2.5" → «2,5» in Norwegian; whole numbers as they are. */
    private static function numberText(string $n, string $lang): string
    {
        if (str_contains($n, '.')) {
            $n = rtrim(rtrim($n, '0'), '.');
        }
        return $lang === 'en' ? $n : str_replace('.', ',', $n);
    }

    /** An ISO instant as «4. juni 2029 kl. 15:00» in venue time; '' when absent or unreadable. */
    private static function instantText(?string $iso, string $lang): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }
        try {
            return self::dateTimeText(new \DateTimeImmutable($iso), $lang);
        } catch (\Exception $e) {
            return $iso;
        }
    }

    private static function dateTimeText(\DateTimeInterface $at, string $lang): string
    {
        $locale = self::LOCALES[$lang] ?? self::LOCALES['no'];
        $pattern = $lang === 'en' ? 'd MMMM y' : 'd. MMMM y';
        $date = (new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, self::VENUE_TIMEZONE, \IntlDateFormatter::GREGORIAN, $pattern))->format($at);
        $time = (new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, self::VENUE_TIMEZONE, \IntlDateFormatter::GREGORIAN, 'HH:mm'))->format($at);
        return lang('booking.catering_log_at', (string)$date, (string)$time);
    }

    /** JSONB maps may carry non-strings; everything is compared and shown as a string. */
    private static function strings(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            $out[(string)$k] = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return $out;
    }
}
