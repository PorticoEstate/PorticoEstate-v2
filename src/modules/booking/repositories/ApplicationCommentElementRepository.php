<?php

namespace App\modules\booking\repositories;

use App\Database\Db;
use PDO;

/**
 * The element of an application a comment is about (bb_application_comment.element_type /
 * element_id): an allocation, event or booking of that same application. Ids overlap across
 * the three tables, so an element is always the pair.
 *
 * Shared by the applicant (bookingfrontend) and case officer (booking) comment endpoints, so
 * both accept a reference on the same terms and show the same element.
 */
class ApplicationCommentElementRepository
{
	/** element_type => the table element_id points into */
	public const TABLES = [
		'allocation' => 'bb_allocation',
		'event'      => 'bb_event',
		'booking'    => 'bb_booking',
	];

	private Db $db;

	public function __construct()
	{
		$this->db = Db::getInstance();
	}

	public static function isValidType(?string $type): bool
	{
		return $type !== null && isset(self::TABLES[$type]);
	}

	/**
	 * Whether the element exists and belongs to the application. An inactive element
	 * counts: a withdrawn allocation is deactivated before its comment is written, and
	 * the case officer may answer about an element that has been deactivated.
	 */
	public function belongsToApplication(int $applicationId, string $type, int $id): bool
	{
		if (!self::isValidType($type) || $id <= 0) {
			return false;
		}

		$table = self::TABLES[$type];
		$stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE id = :id AND application_id = :application_id");
		$stmt->execute([':id' => $id, ':application_id' => $applicationId]);
		return (bool) $stmt->fetchColumn();
	}

	/**
	 * Add 'element' to every comment row that references one: the element as it is now
	 * (time, resources, active), or exists=false when it has been deleted or no longer
	 * belongs to the comment's application. Rows without a reference are returned unchanged.
	 *
	 * from_/to_ stay naive local, as the element tables store them, in ISO form without an
	 * offset, so a client shows them as the wall-clock time they are.
	 */
	public function attachElements(array $rows): array
	{
		$idsByType = [];
		foreach ($rows as $row) {
			$type = $row['element_type'] ?? null;
			$id = (int) ($row['element_id'] ?? 0);
			if (self::isValidType($type) && $id > 0) {
				$idsByType[$type][$id] = $id;
			}
		}
		if (!$idsByType) {
			return $rows;
		}

		$found = [];
		foreach ($idsByType as $type => $ids) {
			$found[$type] = $this->fetchElements($type, array_values($ids));
		}

		foreach ($rows as &$row) {
			$type = $row['element_type'] ?? null;
			$id = (int) ($row['element_id'] ?? 0);
			if (!self::isValidType($type) || $id <= 0) {
				continue;
			}

			$element = $found[$type][$id] ?? null;
			if ($element === null || (int) $element['application_id'] !== (int) $row['application_id']) {
				$row['element'] = ['type' => $type, 'id' => $id, 'exists' => false];
				continue;
			}

			$row['element'] = [
				'type'           => $type,
				'id'             => $id,
				'exists'         => true,
				'from_'          => $this->isoLocal($element['from_']),
				'to_'            => $this->isoLocal($element['to_']),
				'active'         => (int) $element['active'] === 1,
				'resource_names' => $this->decodeName((string) ($element['resource_names'] ?? '')),
			];
		}
		unset($row);

		return $rows;
	}

	/** @return array<int, array> keyed by element id */
	private function fetchElements(string $type, array $ids): array
	{
		$table = self::TABLES[$type];
		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		$stmt = $this->db->prepare(
			"SELECT e.id, e.application_id, e.from_, e.to_, e.active,
			        string_agg(r.name, ', ' ORDER BY r.name) AS resource_names
			 FROM {$table} e
			 LEFT JOIN {$table}_resource er ON er.{$type}_id = e.id
			 LEFT JOIN bb_resource r ON r.id = er.resource_id
			 WHERE e.id IN ({$placeholders})
			 GROUP BY e.id, e.application_id, e.from_, e.to_, e.active"
		);
		$stmt->execute(array_map('intval', $ids));

		$elements = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $element) {
			$elements[(int) $element['id']] = $element;
		}
		return $elements;
	}

	// "2026-12-04 17:00:00" → "2026-12-04T17:00:00": the same wall-clock time, no offset.
	private function isoLocal(?string $timestamp): ?string
	{
		if ($timestamp === null || $timestamp === '') {
			return null;
		}
		return str_replace(' ', 'T', substr($timestamp, 0, 19));
	}

	// Resource names are stored entity-encoded, some twice (&amp;#40;); the clients show text.
	private function decodeName(string $name): string
	{
		$decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if (strpos($decoded, '&') !== false) {
			$decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		return $decoded;
	}
}
