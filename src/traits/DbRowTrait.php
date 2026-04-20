<?php
/**
 * DbRowTrait — row-level helpers for soentity cursor replacement.
 *
 * Replicates the decode chain used by Db::unmarshal('string') so that
 * code migrated away from $this->db->f($col, true) (strip-slashes mode)
 * can call $this->dbStrip($row[$col]) instead.
 *
 * @package property
 */

namespace App\traits;

trait DbRowTrait
{
    /**
     * Apply the same decode chain as Db::unmarshal($value, 'string').
     *
     * Equivalent to:
     *   htmlspecialchars_decode(
     *       stripslashes(str_replace([entity subs], $value)),
     *       ENT_QUOTES
     *   )
     *
     * Returns null for null input (mirrors Db::f() returning '' for missing
     * fields, but null for an absent resultSet — callers should guard accordingly).
     */
    protected function dbStrip(mixed $value): ?string
    {
        if ($value === null)
        {
            return null;
        }

        return htmlspecialchars_decode(
            stripslashes(
                str_replace(
                    ['&amp;', '&#40;', '&#41;', '&#61;', '&#8722;&#8722;', '&#59;'],
                    ['&',     '(',     ')',     '=',     '--',              ';'    ],
                    (string)$value
                )
            ),
            ENT_QUOTES
        );
    }
}
