<?php

namespace App\modules\booking\repositories;

use App\Database\Db;
use App\modules\booking\models\ApplicationComment;
use App\modules\booking\models\Date;
use App\modules\booking\models\Document;
use App\modules\booking\models\Order;
use PDO;

/**
 * Read-oriented repository for the admin application show page.
 *
 * Each data concern has its own fetch method so the controller
 * can compose exactly the payload it needs.
 */
class ApplicationRepository
{
	private Db $db;

	public function __construct()
	{
		$this->db = Db::getInstance();
	}

	// ── Core ────────────────────────────────────────────────────────────

	public function getById(int $id): ?array
	{
		$stmt = $this->db->prepare("SELECT * FROM bb_application WHERE id = :id");
		$stmt->execute([':id' => $id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	/**
	 * Fetch multiple applications by ID in a single query.
	 *
	 * @param int[] $ids
	 * @return array<int, array> Keyed by application ID.
	 */
	public function getByIds(array $ids): array
	{
		if (empty($ids)) {
			return [];
		}
		$placeholders = implode(',', array_map('intval', $ids));
		$stmt = $this->db->prepare(
			"SELECT * FROM bb_application WHERE id IN ({$placeholders})"
		);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$map = [];
		foreach ($rows as $row) {
			$map[(int) $row['id']] = $row;
		}
		return $map;
	}

	// ── Dates ───────────────────────────────────────────────────────────

	public function fetchDates(int $applicationId): array
	{
		$stmt = $this->db->prepare(
			"SELECT id, application_id, from_, to_
			 FROM bb_application_date
			 WHERE application_id = :id
			 ORDER BY from_"
		);
		$stmt->execute([':id' => $applicationId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	// ── Resources ───────────────────────────────────────────────────────

	/**
	 * Returns resource IDs linked to an application.
	 */
	public function fetchResourceIds(int $applicationId): array
	{
		$stmt = $this->db->prepare(
			"SELECT resource_id FROM bb_application_resource WHERE application_id = :id"
		);
		$stmt->execute([':id' => $applicationId]);
		return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'resource_id');
	}

	/**
	 * Returns full resource rows (id, name, building_id, etc.) for an application.
	 */
	public function fetchResources(int $applicationId): array
	{
		$stmt = $this->db->prepare(
			"SELECT r.id, r.name, r.active, r.simple_booking,
			        r.direct_booking, r.activity_id, br.building_id
			 FROM bb_resource r
			 JOIN bb_application_resource ar ON r.id = ar.resource_id
			 LEFT JOIN bb_building_resource br ON r.id = br.resource_id
			 WHERE ar.application_id = :id"
		);
		$stmt->execute([':id' => $applicationId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Map of resource_id => name for a list of IDs.
	 */
	public function getResourceNames(array $resourceIds): array
	{
		if (empty($resourceIds)) {
			return [];
		}
		$placeholders = implode(',', array_map('intval', $resourceIds));
		$stmt = $this->db->prepare(
			"SELECT id, name FROM bb_resource WHERE id IN ({$placeholders})"
		);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$map = [];
		foreach ($rows as $r) {
			$map[(int) $r['id']] = $r['name'];
		}
		return $map;
	}

	// ── Agegroups ───────────────────────────────────────────────────────

	/**
	 * Application's agegroup entries (with reference names joined in).
	 */
	public function fetchAgegroups(int $applicationId): array
	{
		$stmt = $this->db->prepare(
			"SELECT ag.id, ag.name, ag.sort, aag.male, aag.female
			 FROM bb_application_agegroup aag
			 JOIN bb_agegroup ag ON aag.agegroup_id = ag.id
			 WHERE aag.application_id = :id
			 ORDER BY ag.sort"
		);
		$stmt->execute([':id' => $applicationId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	// ── Audience ────────────────────────────────────────────────────────

	/**
	 * IDs of target audiences selected for this application.
	 */
	public function fetchTargetAudienceIds(int $applicationId): array
	{
		$stmt = $this->db->prepare(
			"SELECT ta.id
			 FROM bb_application_targetaudience ata
			 JOIN bb_targetaudience ta ON ata.targetaudience_id = ta.id
			 WHERE ata.application_id = :id
			 ORDER BY ta.sort"
		);
		$stmt->execute([':id' => $applicationId]);
		return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
	}

	/**
	 * All audience entries (reference list for name lookup).
	 * Optionally filtered by top-level activity.
	 */
	public function fetchAllAudiences(int $activityId = 0): array
	{
		$sql = "SELECT id, name, activity_id FROM bb_targetaudience WHERE active = 1";
		$params = [];
		if ($activityId > 0) {
			$sql .= " AND activity_id = :activity_id";
			$params[':activity_id'] = $activityId;
		}
		$sql .= " ORDER BY sort";
		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	// ── Comments ────────────────────────────────────────────────────────

	public function fetchComments(int $applicationId): array
	{
		$stmt = $this->db->prepare(
			"SELECT id, application_id, time, author, comment, type
			 FROM bb_application_comment
			 WHERE application_id = :id
			 ORDER BY time DESC"
		);
		$stmt->execute([':id' => $applicationId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	// ── Internal notes (admin only) ─────────────────────────────────────

	public function fetchInternalNotes(int $applicationId): array
	{
		try {
			$stmt = $this->db->prepare(
				"SELECT n.id, n.content, n.created, a.account_lid AS author_name
				 FROM bb_application_internal_note n
				 LEFT JOIN phpgw_accounts a ON n.author_id = a.account_id
				 WHERE n.application_id = :id
				 ORDER BY n.created DESC"
			);
			$stmt->execute([':id' => $applicationId]);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			// Table may not exist yet
			return [];
		}
	}

	// ── Associations ────────────────────────────────────────────────────

	public function countAssociations(int $applicationId): int
	{
		try {
			$stmt = $this->db->prepare(
				"SELECT COUNT(*) FROM bb_application_association WHERE application_id = :id"
			);
			$stmt->execute([':id' => $applicationId]);
			return (int) $stmt->fetchColumn();
		} catch (\Throwable $e) {
			return 0;
		}
	}

	/**
	 * Fetch linked allocations/bookings/events for this application.
	 */
	public function fetchAssociations(int $applicationId): array
	{
		try {
			$stmt = $this->db->prepare(
				"SELECT id, type, from_, to_, active
				 FROM bb_application_association
				 WHERE application_id = :id
				 ORDER BY from_ NULLS LAST"
			);
			$stmt->execute([':id' => $applicationId]);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return [];
		}
	}

	// ── Combined / related applications ─────────────────────────────────

	/**
	 * Get the group of related applications (parent + children sharing the same parent_id).
	 *
	 * @return array ['application_ids' => int[], 'parent_id' => ?int, 'total_count' => int]
	 */
	public function getRelatedApplications(int $applicationId): array
	{
		$app = $this->getById($applicationId);
		if (!$app) {
			return ['application_ids' => [$applicationId], 'parent_id' => null, 'total_count' => 1];
		}

		$parentId = !empty($app['parent_id']) ? (int) $app['parent_id'] : null;
		if (!$parentId) {
			return ['application_ids' => [$applicationId], 'parent_id' => null, 'total_count' => 1];
		}

		// All applications in this group share the same parent_id
		$stmt = $this->db->prepare(
			"SELECT id FROM bb_application WHERE parent_id = :parent_id ORDER BY id"
		);
		$stmt->execute([':parent_id' => $parentId]);
		$ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

		// Ensure the parent itself is included
		if (!in_array($parentId, $ids)) {
			array_unshift($ids, $parentId);
		}

		return [
			'application_ids' => array_map('intval', $ids),
			'parent_id'       => $parentId,
			'total_count'     => count($ids),
		];
	}

	// ── Collision detection ─────────────────────────────────────────────

	/**
	 * Check whether a timeslot has a collision (allocation, event or block).
	 */
	public function hasCollision(array $resourceIds, string $from, string $to): bool
	{
		if (empty($resourceIds)) {
			return false;
		}
		$rids = implode(',', array_map('intval', $resourceIds));

		$sql = "SELECT 1 FROM (
			SELECT id FROM bb_allocation a
			JOIN bb_allocation_resource ar ON a.id = ar.allocation_id
			WHERE ar.resource_id IN ({$rids}) AND a.active = 1
			  AND a.from_ < :to1 AND a.to_ > :from1
			UNION ALL
			SELECT id FROM bb_event e
			JOIN bb_event_resource er ON e.id = er.event_id
			WHERE er.resource_id IN ({$rids}) AND e.active = 1
			  AND e.from_ < :to2 AND e.to_ > :from2
			UNION ALL
			SELECT id FROM bb_booking b
			JOIN bb_booking_resource br ON b.id = br.booking_id
			WHERE br.resource_id IN ({$rids}) AND b.active = 1
			  AND b.from_ < :to3 AND b.to_ > :from3
		) sub LIMIT 1";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':from1' => $from, ':to1' => $to,
			':from2' => $from, ':to2' => $to,
			':from3' => $from, ':to3' => $to,
		]);
		return (bool) $stmt->fetch();
	}

	// ── Collision details ───────────────────────────────────────────────

	/**
	 * Return detail rows for every conflicting item in a time range.
	 * Similar to hasCollision() but returns type/name/times instead of bool.
	 *
	 * @return array<array{id:int, type:string, name:string, from_:string, to_:string}>
	 */
	public function getCollisionDetails(array $resourceIds, string $from, string $to): array
	{
		if (empty($resourceIds)) {
			return [];
		}
		$rids = implode(',', array_map('intval', $resourceIds));

		$sql = "
			SELECT a.id, 'allocation' AS type,
			       COALESCE(o.name, '') AS name, a.from_, a.to_
			FROM bb_allocation a
			JOIN bb_allocation_resource ar ON a.id = ar.allocation_id
			LEFT JOIN bb_organization o ON a.organization_id = o.id
			WHERE ar.resource_id IN ({$rids}) AND a.active = 1
			  AND a.from_ < :to1 AND a.to_ > :from1
			UNION ALL
			SELECT e.id, 'event' AS type,
			       COALESCE(e.name, '') AS name, e.from_, e.to_
			FROM bb_event e
			JOIN bb_event_resource er ON e.id = er.event_id
			WHERE er.resource_id IN ({$rids}) AND e.active = 1
			  AND e.from_ < :to2 AND e.to_ > :from2
			UNION ALL
			SELECT b.id, 'booking' AS type,
			       COALESCE(g.name, '') AS name, b.from_, b.to_
			FROM bb_booking b
			JOIN bb_booking_resource br ON b.id = br.booking_id
			LEFT JOIN bb_group g ON b.group_id = g.id
			WHERE br.resource_id IN ({$rids}) AND b.active = 1
			  AND b.from_ < :to3 AND b.to_ > :from3
		";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':from1' => $from, ':to1' => $to,
			':from2' => $from, ':to2' => $to,
			':from3' => $from, ':to3' => $to,
		]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	// ── Existing allocations for an application ────────────────────────

	/**
	 * Fetch active allocations already created for this application.
	 * Keyed by "Y-m-d H:i_Y-m-d H:i" for quick lookup.
	 *
	 * @return array<string, array{id:int, from_:string, to_:string}>
	 */
	public function fetchExistingAllocations(int $applicationId): array
	{
		$stmt = $this->db->prepare(
			"SELECT id, from_, to_
			 FROM bb_allocation
			 WHERE application_id = :id AND active = 1
			 ORDER BY from_"
		);
		$stmt->execute([':id' => $applicationId]);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$map = [];
		foreach ($rows as $row) {
			$key = date('Y-m-d H:i', strtotime($row['from_']))
				. '_' . date('Y-m-d H:i', strtotime($row['to_']));
			$map[$key] = $row;
		}
		return $map;
	}

	// ── Building name lookup ───────────────────────────────────────────

	public function fetchBuildingName(int $buildingId): string
	{
		$stmt = $this->db->prepare("SELECT name FROM bb_building WHERE id = :id");
		$stmt->execute([':id' => $buildingId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? $row['name'] : '';
	}

	// ── Organization ────────────────────────────────────────────────────

	public function fetchOrganizationByNumber(string $orgNumber): ?array
	{
		try {
			$stmt = $this->db->prepare(
				"SELECT id, name, organization_number, homepage, email, phone,
				        street, zip_code, city, active, show_in_portal, in_tax_register
				 FROM bb_organization
				 WHERE organization_number = :num
				 LIMIT 1"
			);
			$stmt->execute([':num' => $orgNumber]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			return $row ?: null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	// ── Activity ────────────────────────────────────────────────────────

	public function fetchActivityName(int $activityId): ?string
	{
		$stmt = $this->db->prepare("SELECT name FROM bb_activity WHERE id = :id");
		$stmt->execute([':id' => $activityId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? $row['name'] : null;
	}

	// ── Case officer ────────────────────────────────────────────────────

	public function fetchAccountName(int $accountId): ?string
	{
		$stmt = $this->db->prepare(
			"SELECT account_lastname || ', ' || account_firstname AS fullname
			 FROM phpgw_accounts WHERE account_id = :id"
		);
		$stmt->execute([':id' => $accountId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? $row['fullname'] : null;
	}

	// ── User list (for case officer assignment dropdown) ─────────────────

	public function fetchUserList(): array
	{
		try {
			// Get users who have ACL access to the booking module.
			// phpgw_acl uses location_id (FK to phpgw_locations), not direct app/location strings.
			$stmt = $this->db->prepare(
				"SELECT DISTINCT a.account_id AS id,
				        a.account_lastname || ', ' || a.account_firstname AS name
				 FROM phpgw_accounts a
				 JOIN phpgw_acl acl ON a.account_id = acl.acl_account
				 JOIN phpgw_locations loc ON acl.location_id = loc.location_id
				 WHERE loc.app_id = (SELECT app_id FROM phpgw_applications WHERE app_name = 'booking')
				   AND loc.name = 'run'
				   AND a.account_status = 'A'
				 ORDER BY name"
			);
			$stmt->execute();
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return [];
		}
	}

	// ── Documents ───────────────────────────────────────────────────────

	public function fetchDocuments(int $applicationId): array
	{
		$stmt = $this->db->prepare(
			"SELECT id, name, owner_id, category
			 FROM bb_document_application
			 WHERE owner_id = :id"
		);
		$stmt->execute([':id' => $applicationId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	// ── Orders / purchase orders ────────────────────────────────────────

	public function fetchOrders(int $applicationId): array
	{
		$stmt = $this->db->prepare(
			"SELECT po.id AS order_id, pol.id AS line_id,
			        pol.article_mapping_id, pol.quantity, pol.amount, pol.tax,
			        am.unit,
			        CASE WHEN r.name IS NULL THEN s.name ELSE r.name END AS article_name
			 FROM bb_purchase_order po
			 JOIN bb_purchase_order_line pol ON po.id = pol.order_id
			 JOIN bb_article_mapping am ON pol.article_mapping_id = am.id
			 LEFT JOIN bb_service s ON (am.article_id = s.id AND am.article_cat_id = 2)
			 LEFT JOIN bb_resource r ON (am.article_id = r.id AND am.article_cat_id = 1)
			 WHERE po.cancelled IS NULL AND po.application_id = :id
			 ORDER BY pol.id"
		);
		$stmt->execute([':id' => $applicationId]);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		// Group into orders with line items
		$orders = [];
		foreach ($rows as $row) {
			$oid = (int) $row['order_id'];
			if (!isset($orders[$oid])) {
				$orders[$oid] = ['order_id' => $oid, 'sum' => 0, 'lines' => []];
			}
			$amount = (float) ($row['amount'] ?? 0);
			$tax = (float) ($row['tax'] ?? 0);
			$orders[$oid]['lines'][] = [
				'line_id'            => (int) $row['line_id'],
				'article_mapping_id' => (int) $row['article_mapping_id'],
				'name'               => $row['article_name'],
				'unit'               => $row['unit'],
				'quantity'           => (int) ($row['quantity'] ?? 0),
				'amount'             => $amount,
				'tax'                => $tax,
			];
			$orders[$oid]['sum'] += $amount + $tax;
		}
		return array_values($orders);
	}

	// ── Season ─────────────────────────────────────────────────────────

	public function fetchSeasonInfo(int $buildingId, string $dateInRange): ?array
	{
		$appDate = date('Y-m-d', strtotime($dateInRange));
		$stmt = $this->db->prepare(
			"SELECT id, name, from_, to_
			 FROM bb_season
			 WHERE active = 1
			   AND building_id = :building_id
			   AND from_ <= :d
			   AND to_ >= :d
			 LIMIT 1"
		);
		$stmt->execute([':building_id' => $buildingId, ':d' => $appDate]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	// ── Assign / Unassign ──────────────────────────────────────────────

	public function assignCaseOfficer(int $applicationId, int $accountId): void
	{
		$stmt = $this->db->prepare(
			"UPDATE bb_application SET case_officer_id = :uid WHERE id = :id"
		);
		$stmt->execute([':uid' => $accountId, ':id' => $applicationId]);
	}

	public function unassignCaseOfficer(int $applicationId): void
	{
		$stmt = $this->db->prepare(
			"UPDATE bb_application SET case_officer_id = NULL WHERE id = :id"
		);
		$stmt->execute([':id' => $applicationId]);
	}

	public function updateStatus(int $applicationId, string $status): void
	{
		$stmt = $this->db->prepare(
			"UPDATE bb_application SET status = :status WHERE id = :id"
		);
		$stmt->execute([':status' => $status, ':id' => $applicationId]);
	}

	public function addComment(int $applicationId, string $author, string $comment, string $type = 'comment'): void
	{
		$stmt = $this->db->prepare(
			"INSERT INTO bb_application_comment (application_id, time, author, comment, type)
			 VALUES (:id, NOW(), :author, :comment, :type)"
		);
		$stmt->execute([
			':id'      => $applicationId,
			':author'  => $author,
			':comment' => $comment,
			':type'    => $type,
		]);
	}

	// ── Internal notes (write) ──────────────────────────────────────────

	public function addInternalNote(int $applicationId, int $authorId, string $content): void
	{
		$stmt = $this->db->prepare(
			"INSERT INTO bb_application_internal_note (application_id, author_id, content, created)
			 VALUES (:id, :author, :content, NOW())"
		);
		$stmt->execute([
			':id'      => $applicationId,
			':author'  => $authorId,
			':content' => $content,
		]);
	}

	// ── Associations (write) ────────────────────────────────────────────

	public function activateAssociations(int $applicationId): void
	{
		// bb_application_association is a UNION view — update underlying tables directly
		$tables = ['bb_allocation', 'bb_booking', 'bb_event'];
		foreach ($tables as $table) {
			$stmt = $this->db->prepare(
				"UPDATE {$table} SET active = 1 WHERE application_id = :id"
			);
			$stmt->execute([':id' => $applicationId]);
		}
	}

	public function deactivateAssociations(int $applicationId): void
	{
		$tables = ['bb_allocation', 'bb_booking', 'bb_event'];
		foreach ($tables as $table) {
			$stmt = $this->db->prepare(
				"UPDATE {$table} SET active = 0 WHERE application_id = :id"
			);
			$stmt->execute([':id' => $applicationId]);
		}
	}

	// ── Dashboard ───────────────────────────────────────────────────────

	public function updateDisplayInDashboard(int $applicationId, int $value): void
	{
		$stmt = $this->db->prepare(
			"UPDATE bb_application SET display_in_dashboard = :val WHERE id = :id"
		);
		$stmt->execute([':val' => $value, ':id' => $applicationId]);
	}

	// ── Config ──────────────────────────────────────────────────────────

	public function fetchBookingConfig(): array
	{
		$stmt = $this->db->prepare(
			"SELECT config_name, config_value
			 FROM phpgw_config
			 WHERE config_app = 'booking'"
		);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$config = [];
		foreach ($rows as $r) {
			$config[$r['config_name']] = $r['config_value'];
		}
		return $config;
	}

	/**
	 * Check if external archive is configured for booking.
	 * Mirrors legacy: soconfig with common_archive method check.
	 */
	public function fetchExternalArchiveMethod(): string
	{
		try {
			$stmt = $this->db->prepare(
				"SELECT v.value
				 FROM phpgw_config2_value v
				 JOIN phpgw_config2_attrib a ON v.attrib_id = a.id AND v.section_id = a.section_id
				 JOIN phpgw_config2_section s ON v.section_id = s.id
				 WHERE s.location_id = (
				     SELECT location_id FROM phpgw_locations
				     WHERE app_id = (SELECT app_id FROM phpgw_applications WHERE app_name = 'booking')
				     AND name = 'run'
				 )
				 AND s.name = 'common_archive'
				 AND a.name = 'method'
				 LIMIT 1"
			);
			$stmt->execute();
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			return $row ? (string) $row['value'] : '';
		} catch (\Throwable $e) {
			return '';
		}
	}

	/**
	 * Fetch regulation/HMS/price list documents for a building (and optionally resources).
	 * Matches legacy: booking.uidocument_view.regulations
	 */
	public function fetchRegulationDocuments(int $buildingId, array $resourceIds = []): array
	{
		try {
			$ownerConditions = ["(type = 'building' AND owner_id = :building_id)"];
			$params = [':building_id' => $buildingId];

			foreach ($resourceIds as $i => $rid) {
				$key = ':res_' . $i;
				$ownerConditions[] = "(type = 'resource' AND owner_id = $key)";
				$params[$key] = (int) $rid;
			}

			$ownerWhere = implode(' OR ', $ownerConditions);

			$stmt = $this->db->prepare(
				"SELECT id, name, description, category, type, owner_id
				 FROM bb_document_view
				 WHERE category IN ('regulation', 'HMS_document', 'price_list')
				   AND ($ownerWhere)
				 ORDER BY name"
			);
			$stmt->execute($params);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

			foreach ($rows as &$row) {
				// Use description as display name if available (matches legacy)
				$row['display_name'] = (!empty($row['description']) && trim($row['description']) !== '')
					? $row['description']
					: $row['name'];
				$row['download_url'] = '/?menuaction=booking.uidocument_view.download&id='
					. urlencode($row['type'] . '::' . $row['id']);
			}

			return $rows;
		} catch (\Throwable $e) {
			return [];
		}
	}
}
