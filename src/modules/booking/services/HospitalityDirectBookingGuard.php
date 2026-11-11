<?php

namespace App\modules\booking\services;

use App\Database\Db;
use PDO;

/**
 * A resource cannot have BOTH direct booking and hospitality (operator 2026-11-11; "just direct
 * booking": timeslot booking, bb_resource.simple_booking, never counts).
 *
 * DEFINITIONS
 *   direct booking   bb_resource.direct_booking is non-null and non-zero. A FUTURE start date
 *                    counts: the rule is about the configuration, not about today's eligibility.
 *   linked           the hospitality is ACTIVE and the resource is either its own resource_id or
 *                    one of its ACTIVE bb_hospitality_remote_location rows. The serving flags
 *                    (remote_serving_enabled, allow_on_site_hospitality) are deliberately ignored:
 *                    they can be flipped by a plain PUT, and getActiveByResourceIds() treats remote
 *                    rows as served under allow_on_site, so a flag is not a reliable "dormant" mark.
 *                    Active-only because hospitality is soft-deleted (active = 0): counting inactive
 *                    rows would block direct booking for ever on a resource a deleted hospitality
 *                    once used.
 *
 * THE RULE (pe-pm ruling R-T): only a TRANSITION INTO a conflict is refused.
 *   - resource side: direct_booking goes from unset to set while the resource is linked;
 *   - link side:     a link becomes effective (a hospitality is created active, re-activated, moved
 *                    to another own resource, or a remote row is added/re-activated) while the
 *                    resource has direct booking.
 * A resource that is already in conflict and stays in conflict is NOT refused, so the existing
 * conflicts are warned about, never frozen, and an unrelated edit of them still saves.
 *
 * Split in two so the rule can be proven hermetically (tests/hospitality-direct-booking-guard):
 *   - the static methods under "RULE" are pure — plain arrays in, plain arrays/bools out;
 *   - the instance methods read bb_resource / bb_hospitality* and put the pure rule to use.
 *
 * Bound: the check is read-then-write, not atomic (two admins at once can slip a conflict through).
 */
final class HospitalityDirectBookingGuard
{
    /** The resource has direct booking, so it may not be linked to hospitality (hospitality side). */
    public const CODE_RESOURCE_HAS_DIRECT_BOOKING = 'resource_has_direct_booking';
    /** The resource is linked to hospitality, so it may not get direct booking (resource side). */
    public const CODE_RESOURCE_HAS_HOSPITALITY = 'resource_has_hospitality';

    private Db $db;

    public function __construct(?Db $db = null)
    {
        // Lazy: the pure rule must be usable (and testable) with no database at all.
        $this->db = $db ?? Db::getInstance();
    }

    // ═══════════════════════════════════════════════════════════════════
    // RULE — pure
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Does this resource row have direct booking? Reads direct_booking ONLY (never simple_booking).
     * null, '', false, 0 and '0' are "not set"; anything else is.
     */
    public static function hasDirectBooking(array $resource): bool
    {
        $value = $resource['direct_booking'] ?? null;
        if ($value === null || $value === '' || $value === false) {
            return false;
        }
        return !is_numeric($value) || (int)$value !== 0;
    }

    /**
     * The hospitalities a resource is linked to right now, one entry per hospitality.
     *
     * @param array<int, array{hospitality_id: int|string, name?: string, hospitality_active: int|string,
     *     kind: string, link_active?: int|string}> $linkRows kind is 'own' (the hospitality's own
     *     resource_id) or 'remote' (a bb_hospitality_remote_location row, whose own `active` is link_active)
     * @return array<int, array{hospitality_id: int, name: string}>
     */
    public static function effectiveLinks(array $linkRows): array
    {
        $links = [];
        foreach ($linkRows as $row) {
            if ((int)($row['hospitality_active'] ?? 0) !== 1) {
                continue;
            }
            if (($row['kind'] ?? '') === 'remote' && (int)($row['link_active'] ?? 0) !== 1) {
                continue;
            }
            $id = (int)$row['hospitality_id'];
            $links[$id] = ['hospitality_id' => $id, 'name' => (string)($row['name'] ?? '')];
        }
        return array_values($links);
    }

    /**
     * Resource side. The hospitalities that block this resource save; empty = allowed.
     *
     * @param array $before the resource row as stored (direct_booking read)
     * @param array $after  the resource row as it would be saved
     * @param array $linkRows see effectiveLinks()
     * @return array<int, array{hospitality_id: int, name: string}>
     */
    public static function hospitalitiesBlockingResourceSave(array $before, array $after, array $linkRows): array
    {
        if (self::hasDirectBooking($before) || !self::hasDirectBooking($after)) {
            return [];
        }
        return self::effectiveLinks($linkRows);
    }

    /**
     * Link side, hospitality create/update. The ids of the resources that block this save; empty = allowed.
     *
     * @param array|null $before the hospitality as stored (active, resource_id); null on create
     * @param array $after the hospitality as it would be saved (active, resource_id)
     * @param int[] $activeRemoteResourceIds resources of the hospitality's ACTIVE remote rows (they
     *     become effective at once when the hospitality is re-activated)
     * @param array<int, array> $resourcesById resource rows keyed by id (direct_booking read)
     * @return int[]
     */
    public static function resourcesBlockingHospitalitySave(?array $before, array $after, array $activeRemoteResourceIds, array $resourcesById): array
    {
        if ((int)($after['active'] ?? 0) !== 1) {
            return [];
        }
        $wasActive = $before !== null && (int)($before['active'] ?? 0) === 1;

        $becomingLinked = [];
        if (!$wasActive || (int)$before['resource_id'] !== (int)$after['resource_id']) {
            $becomingLinked[] = (int)$after['resource_id'];
        }
        if ($before !== null && !$wasActive) {
            foreach ($activeRemoteResourceIds as $resourceId) {
                $becomingLinked[] = (int)$resourceId;
            }
        }

        $blocking = [];
        foreach (array_unique($becomingLinked) as $resourceId) {
            if (isset($resourcesById[$resourceId]) && self::hasDirectBooking($resourcesById[$resourceId])) {
                $blocking[] = $resourceId;
            }
        }
        return $blocking;
    }

    /**
     * Link side, remote delivery location add / re-activate. True = refuse.
     *
     * @param array $hospitality the hospitality (active read)
     * @param bool $rowWasActive the remote row exists and is active
     * @param bool $rowWillBeActive the row will be active after the write
     * @param array $resource the resource row (direct_booking read)
     */
    public static function remoteLinkBlocked(array $hospitality, bool $rowWasActive, bool $rowWillBeActive, array $resource): bool
    {
        return (int)($hospitality['active'] ?? 0) === 1
            && !$rowWasActive
            && $rowWillBeActive
            && self::hasDirectBooking($resource);
    }

    // ═══════════════════════════════════════════════════════════════════
    // CHECKS — read the database, apply the rule, return the refusal
    // ═══════════════════════════════════════════════════════════════════

    /**
     * POST /booking/hospitality
     *
     * @return array{code: string, error: string, resource_ids: int[]}|null null = allowed
     */
    public function checkHospitalityCreate(array $body): ?array
    {
        $after = [
            'active' => self::truthy($body['active'] ?? 1) ? 1 : 0,
            'resource_id' => (int)($body['resource_id'] ?? 0),
        ];
        return $this->hospitalitySaveRefusal(null, $after, []);
    }

    /**
     * PUT /booking/hospitality/{id}
     *
     * @param array $existing the stored hospitality row (HospitalityRepository::getById)
     * @return array{code: string, error: string, resource_ids: int[]}|null
     */
    public function checkHospitalityUpdate(array $existing, array $body): ?array
    {
        $after = [
            'active' => array_key_exists('active', $body) ? (self::truthy($body['active']) ? 1 : 0) : (int)$existing['active'],
            'resource_id' => array_key_exists('resource_id', $body) ? (int)$body['resource_id'] : (int)$existing['resource_id'],
        ];
        $before = ['active' => (int)$existing['active'], 'resource_id' => (int)$existing['resource_id']];
        $remote = ($after['active'] === 1 && $before['active'] !== 1)
            ? $this->activeRemoteResourceIds((int)$existing['id'])
            : [];
        return $this->hospitalitySaveRefusal($before, $after, $remote);
    }

    /**
     * POST /booking/hospitality/{id}/remote-locations and PATCH …/remote-locations/{resourceId}
     *
     * @param array|null $hospitality the stored hospitality row; null (unknown id) is never refused
     * @return array{code: string, error: string, resource_ids: int[]}|null
     */
    public function checkRemoteLocation(?array $hospitality, int $resourceId, bool $willBeActive): ?array
    {
        if ($hospitality === null) {
            return null;
        }
        $resources = $this->resourcesById([$resourceId]);
        $resource = $resources[$resourceId] ?? null;
        if ($resource === null) {
            return null;
        }
        $wasActive = $this->remoteRowActive((int)$hospitality['id'], $resourceId);
        if (!self::remoteLinkBlocked($hospitality, $wasActive, $willBeActive, $resource)) {
            return null;
        }
        return $this->resourceHasDirectBookingRefusal([$resource]);
    }

    /**
     * Legacy resource save (booking_soresource::doValidate), runs for add() as well.
     *
     * @param int $resourceId 0 / absent on add: a new resource cannot be linked to anything yet
     * @param mixed $newDirectBooking the value that would be saved (a timestamp after preValidate)
     * @return string|null the translated error, null = allowed
     */
    public function checkResourceSave(int $resourceId, $newDirectBooking): ?string
    {
        if ($resourceId <= 0) {
            return null;
        }
        $after = ['direct_booking' => $newDirectBooking];
        $before = $this->resourcesById([$resourceId])[$resourceId] ?? null;
        if ($before === null) {
            return null;
        }
        if (self::hasDirectBooking($before) || !self::hasDirectBooking($after)) {
            return null; // the cheap exits first: no query for the link rows on an ordinary save
        }
        $blocking = self::hospitalitiesBlockingResourceSave($before, $after, $this->linkRowsForResource($resourceId));
        if (!$blocking) {
            return null;
        }
        // The legacy form flashes this string as HTML without escaping it, and a hospitality name
        // is admin-typed free text: escape the names here, the one place that knows the channel.
        $names = array_map(fn(string $name) => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), array_column($blocking, 'name'));
        return lang('booking.direct_booking_blocked_resource_has_hospitality', self::joinNames($names));
    }

    /**
     * Read-only, for the admin warnings: the hospitalities a resource that HAS direct booking is
     * linked to (the existing conflicts). Empty when the resource has no direct booking.
     *
     * @return array<int, array{hospitality_id: int, name: string}>
     */
    public function conflictingHospitalitiesForResource(int $resourceId): array
    {
        $resource = $this->resourcesById([$resourceId])[$resourceId] ?? null;
        if ($resource === null || !self::hasDirectBooking($resource)) {
            return [];
        }
        return self::effectiveLinks($this->linkRowsForResource($resourceId));
    }

    /**
     * Read-only, for the admin warnings on ONE hospitality: which of its links are existing
     * conflicts (an effective link to a resource that has direct booking).
     *
     * @param array $hospitality the stored hospitality row (active, resource_id)
     * @param array<int, array{resource_id: int|string, active?: int|string}> $remoteRows its remote location rows
     * @return array{main: bool, remote: array<int, bool>} remote is keyed by resource id
     */
    public function conflictFlagsForHospitality(array $hospitality, array $remoteRows): array
    {
        $hospitalityActive = (int)($hospitality['active'] ?? 0) === 1;
        $mainId = (int)$hospitality['resource_id'];
        $resources = $this->resourcesById(array_merge([$mainId], array_column($remoteRows, 'resource_id')));
        $has = static fn(int $id): bool => isset($resources[$id]) && self::hasDirectBooking($resources[$id]);

        $flags = ['main' => $hospitalityActive && $has($mainId), 'remote' => []];
        foreach ($remoteRows as $row) {
            $resourceId = (int)$row['resource_id'];
            $flags['remote'][$resourceId] = $hospitalityActive && (int)($row['active'] ?? 0) === 1 && $has($resourceId);
        }
        return $flags;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Lookups
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Every hospitality row that references the resource, active or not (effectiveLinks() decides).
     *
     * @return array<int, array<string, mixed>>
     */
    public function linkRowsForResource(int $resourceId): array
    {
        $sql = "SELECT h.id AS hospitality_id, h.name, h.active AS hospitality_active,
                       'own' AS kind, 1 AS link_active
                  FROM bb_hospitality h
                 WHERE h.resource_id = :resource_id1
                UNION ALL
                SELECT h.id, h.name, h.active, 'remote', rl.active
                  FROM bb_hospitality_remote_location rl
                  JOIN bb_hospitality h ON h.id = rl.hospitality_id
                 WHERE rl.resource_id = :resource_id2
                 ORDER BY 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':resource_id1' => $resourceId, ':resource_id2' => $resourceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param int[] $ids
     * @return array<int, array{id: int, name: string, direct_booking: mixed, simple_booking: mixed}>
     */
    public function resourcesById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', $ids); // ints only, cast above
        $stmt = $this->db->prepare(
            "SELECT id, name, direct_booking, simple_booking FROM bb_resource WHERE id IN ({$placeholders})"
        );
        $stmt->execute();
        $byId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byId[(int)$row['id']] = $row;
        }
        return $byId;
    }

    /** @return int[] */
    public function activeRemoteResourceIds(int $hospitalityId): array
    {
        $stmt = $this->db->prepare(
            "SELECT resource_id FROM bb_hospitality_remote_location WHERE hospitality_id = :id AND active = 1"
        );
        $stmt->execute([':id' => $hospitalityId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function remoteRowActive(int $hospitalityId, int $resourceId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT active FROM bb_hospitality_remote_location WHERE hospitality_id = :h AND resource_id = :r"
        );
        $stmt->execute([':h' => $hospitalityId, ':r' => $resourceId]);
        return (int)$stmt->fetchColumn() === 1;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Internals
    // ═══════════════════════════════════════════════════════════════════

    /**
     * @param array|null $before
     * @param int[] $activeRemoteResourceIds
     * @return array{code: string, error: string, resource_ids: int[]}|null
     */
    private function hospitalitySaveRefusal(?array $before, array $after, array $activeRemoteResourceIds): ?array
    {
        // No active link comes out of this save: skip the lookup altogether.
        if ((int)$after['active'] !== 1) {
            return null;
        }
        $candidateIds = array_merge([(int)$after['resource_id']], $activeRemoteResourceIds);
        $resources = $this->resourcesById($candidateIds);
        $blockingIds = self::resourcesBlockingHospitalitySave($before, $after, $activeRemoteResourceIds, $resources);
        if (!$blockingIds) {
            return null;
        }
        return $this->resourceHasDirectBookingRefusal(array_map(fn(int $id) => $resources[$id], $blockingIds));
    }

    /**
     * @param array<int, array{id: int|string, name: string}> $resources
     * @return array{code: string, error: string, resource_ids: int[]}
     */
    private function resourceHasDirectBookingRefusal(array $resources): array
    {
        $resources = array_values($resources);
        return [
            'code' => self::CODE_RESOURCE_HAS_DIRECT_BOOKING,
            'error' => lang('booking.hospitality_blocked_resource_has_direct_booking', self::joinNames(array_column($resources, 'name'))),
            'resource_ids' => array_map(fn(array $r) => (int)$r['id'], $resources),
        ];
    }

    /** @param string[] $names */
    private static function joinNames(array $names): string
    {
        return implode(', ', $names);
    }

    /** A JSON/form value as the admin UI sends it: 1/0, true/false, "1"/"0". */
    private static function truthy($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
