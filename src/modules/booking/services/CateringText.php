<?php

namespace App\modules\booking\services;

/**
 * Text handling for the catering-change mail and its case entry (GH #1425).
 *
 * Names stored in the database are frequently entity-encoded — sometimes twice, e.g. a resource
 * called "Hall (A)" is held as "Hall &amp;#40;A&amp;#41;". REST decodes them
 * (SerializableTrait::sanitizeString), the legacy Twig mail path never did, which is how staff
 * came to read a literal "&#40;" in mails.
 *
 * The rule for everything this feature writes:
 *   1. decode() every stored value to PLAIN TEXT first;
 *   2. escape exactly ONCE, at the HTML boundary — the Twig environment's autoescape does that for
 *      the body, so no value is ever passed through |raw or |safe_html;
 *   3. the mail SUBJECT is plain text, never HTML-escaped: subject() decodes it and strips CR/LF
 *      (header safety), nothing else.
 *
 * Deliberately NOT done: decoding in the shared render layer, in a shared template or in storage.
 * The encoded building name is load-bearing for an unrelated lookup (get_tilsyn_email() matches
 * bb_application.building_name against bb_building.name, both encoded), so a decode anywhere
 * those strings are shared would turn a cosmetic defect into a dropped recipient.
 */
final class CateringText
{
    /**
     * The same three stages REST applies (SerializableTrait::sanitizeString): decode entities,
     * turn any remaining numeric entity into its character, decode once more if "&amp;" survived.
     * mb_chr rather than chr so a code point above 255 is not truncated.
     */
    public static function decode(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $decoded = preg_replace_callback(
            '/&#(\d+);/',
            static fn(array $m): string => mb_chr((int)$m[1], 'UTF-8') ?: '',
            $decoded
        ) ?? $decoded;

        if (strpos($decoded, '&amp;') !== false) {
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $decoded;
    }

    /**
     * A mail subject built from one stored value: decoded plain text, one line, NOT HTML-escaped.
     */
    public static function subject(?string $value): string
    {
        return self::singleLine(self::decode($value));
    }

    /**
     * One line of plain text for a mail header: CR/LF (which would let a value inject headers)
     * collapse to a space. Does NOT decode — callers decode the individual stored values before
     * they are put into a translated sentence, so this never double-decodes the sentence.
     */
    public static function singleLine(string $text): string
    {
        $text = preg_replace('/[\r\n]+/', ' ', $text) ?? $text;
        return trim($text);
    }
}
