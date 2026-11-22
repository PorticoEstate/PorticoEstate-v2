<?php

namespace App\modules\phpgwapi\services;

use App\Database\Db;

/**
 * Reads another account's EFFECTIVE preferences without becoming that account.
 *
 * WHY THIS EXISTS. CreateObject('phpgwapi.preferences', $accountId) returns the Preferences
 * SINGLETON and calls setAccountId() on it (LegacyObjectHandler, case 'preferences' →
 * Preferences.php setAccountId), which rewrites Settings['user'] — the account id AND the
 * preferences — for the rest of the request. A citizen's request that only wanted to know a case
 * officer's address then runs, from that line on, as the officer: the deferred group mail and bell,
 * and every legacy read that authorises on the current user (boactivity / boorganization
 * read_single), act on the officer's identity. This reader asks the same question of the same table
 * and touches no singleton and no Settings.
 *
 * WHAT IT RETURNS. The same array as Preferences::read() for that account, resolved the way
 * Preferences::Readpreferences() resolves it (Preferences.php Readpreferences):
 *   1. the account's own value;
 *   2. a default (owner -2) fills in where the own value is unset or '';
 *   3. a forced value (owner -1) overwrites whatever is there, even an own value, even with ''.
 * Every stored string is unquoted (stripslashes) first, as Preferences::unquote() does, and a row
 * whose JSON is not an object is skipped.
 *
 * NOT MIRRORED, and measured not to matter for the callers that exist (see the commit message):
 *   - the `$$name$$` placeholder substitution of Preferences::standard_substitutes(). A stored value
 *     that still carries a placeholder comes back as stored;
 *   - the derived `common.tz_offset`: Readpreferences() overwrites whatever is stored with an offset it
 *     computes from common.timezone; this reader returns the stored value as it is;
 *   - the side effects Readpreferences() has on the Translation singleton.
 * A caller that needs one of those wants the real Preferences object, and must then accept that it
 * becomes the current user.
 */
final class PreferencesReader
{
    /**
     * The effective preferences of one account, keyed by app ('common', 'booking', …).
     *
     * @param int               $accountId a real account (> 0); an unknown id yields the forced and
     *                                     default rows only
     * @param list<string>|null $apps      limit the read to these apps; null reads every app
     * @return array<string, array<string, mixed>>
     */
    public static function read(int $accountId, ?array $apps = null): array
    {
        return self::resolve(self::fetchRows($accountId, $apps), $apps);
    }

    /**
     * Resolve stored rows to effective preferences keyed by app. Pure: no database, no singleton.
     *
     * @param list<array{preference_owner: int|string, preference_app: string, preference_json: string}> $rows
     *        the forced (-1), default (-2) and the account's own row of each app
     * @param list<string>|null $apps limit the result to these apps; null keeps every app
     * @return array<string, array<string, mixed>>
     */
    public static function resolve(array $rows, ?array $apps = null): array
    {
        $effective = self::merge($rows, null);
        if ($apps !== null) {
            $effective = array_intersect_key($effective, array_flip($apps));
        }
        return $effective;
    }

    /**
     * Resolve the stored rows of ONE app to that app's effective preferences. Pure.
     *
     * The rows need no `preference_app`; every row is taken to belong to the one app being resolved.
     *
     * @param list<array{preference_owner: int|string, preference_json: string}> $rows
     * @return array<string, mixed>
     */
    public static function resolveApp(array $rows): array
    {
        return self::merge($rows, '')[''] ?? [];
    }

    /**
     * The stored rows that decide an account's effective preferences: the forced rows (owner -1),
     * the default rows (owner -2) and the account's own. The same select as
     * Preferences::Readpreferences() (Preferences.php Readpreferences), optionally narrowed to some apps.
     *
     * @param list<string>|null $apps
     * @return list<array{preference_owner: int|string, preference_app: string, preference_json: string}>
     */
    private static function fetchRows(int $accountId, ?array $apps): array
    {
        $sql = 'SELECT preference_owner, preference_app, preference_json FROM phpgw_preferences'
            . ' WHERE preference_owner IN (-1, -2, ?)';
        $params = [$accountId];

        if ($apps !== null) {
            if ($apps === []) {
                return [];
            }
            $sql .= " AND REPLACE(preference_app, ' ', '') IN (" . implode(', ', array_fill(0, count($apps), '?')) . ')';
            array_push($params, ...array_values($apps));
        }

        $stmt = Db::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Readpreferences()'s merge, app by app.
     *
     * @param string|null $onlyApp when set, every row is filed under this key instead of its own
     *                             preference_app
     * @return array<string, array<string, mixed>>
     */
    private static function merge(array $rows, ?string $onlyApp): array
    {
        $forced = $default = $own = [];
        foreach ($rows as $row) {
            $app = $onlyApp ?? str_replace(' ', '', (string)($row['preference_app'] ?? ''));
            $value = self::unquote(json_decode((string)$row['preference_json'], true));
            if (!is_array($value)) {
                continue;
            }
            switch ((int)$row['preference_owner']) {
                case -1:
                    $forced[$app] = $value;
                    break;
                case -2:
                    $default[$app] = $value;
                    break;
                default:
                    $own[$app] = $value;
                    break;
            }
        }

        $effective = $own;
        foreach ($default as $app => $values) {
            foreach ($values as $name => $value) {
                if (!isset($effective[$app][$name]) || $effective[$app][$name] === '') {
                    $effective[$app][$name] = $value;
                }
            }
        }
        foreach ($forced as $app => $values) {
            foreach ($values as $name => $value) {
                $effective[$app][$name] = $value;
            }
        }
        return $effective;
    }

    /** Preferences::unquote() (Preferences.php): stripslashes on every scalar, recursively. */
    private static function unquote(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::unquote(...), $value);
        }
        return stripslashes((string)$value);
    }
}
