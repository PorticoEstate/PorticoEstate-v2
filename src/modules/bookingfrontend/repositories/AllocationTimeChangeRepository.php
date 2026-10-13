<?php

namespace App\modules\bookingfrontend\repositories;

use App\Database\Db;
use PDO;

/**
 * What a change of one allocation's time from Min side reads and writes besides
 * the allocation row itself (AllocationTimeChangeService): the bookings made
 * inside it, and the allocation's own purchase order, whose lines priced per
 * hour follow the new time.
 */
class AllocationTimeChangeRepository
{
	private Db $db;

	public function __construct()
	{
		$this->db = Db::getInstance();
	}

	/**
	 * An active booking made inside the allocation that does not fit inside the
	 * new time, or null. Any part of a booking outside [from, to] counts: a
	 * booking lying wholly in the removed part as much as one crossing the new
	 * boundary.
	 */
	public function findBookingOutside(int $allocationId, string $from, string $to): ?int
	{
		$stmt = $this->db->prepare(
			"SELECT id FROM bb_booking
			 WHERE allocation_id = :id AND active = 1 AND (from_ < :from OR to_ > :to)
			 ORDER BY from_, id
			 LIMIT 1"
		);
		$stmt->execute([':id' => $allocationId, ':from' => $from, ':to' => $to]);
		$id = $stmt->fetchColumn();
		return $id === false ? null : (int) $id;
	}

	/**
	 * The purchase orders written for this allocation - (reservation_type,
	 * reservation_id) names at most one in practice; a caller treats more than
	 * one as a price it cannot attribute.
	 *
	 * @return array<array{id:int, parent_id:?int}>
	 */
	public function findOwnOrders(int $allocationId): array
	{
		$stmt = $this->db->prepare(
			"SELECT id, parent_id FROM bb_purchase_order
			 WHERE reservation_type = 'allocation' AND reservation_id = :id
			 ORDER BY id"
		);
		$stmt->execute([':id' => $allocationId]);

		return array_map(fn(array $row) => [
			'id' => (int) $row['id'],
			'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
		], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	/**
	 * Whether money for the order has already left the building: a payment on
	 * it, or the allocation's invoicing row already exported. The same guard as
	 * sopurchase_order::cascade_order_to_allocation.
	 */
	public function isBilled(int $orderId, int $allocationId): bool
	{
		$stmt = $this->db->prepare(
			"SELECT EXISTS (SELECT 1 FROM bb_payment WHERE order_id = :order_id)
			     OR EXISTS (SELECT 1 FROM bb_completed_reservation
			                WHERE reservation_type = 'allocation' AND reservation_id = :allocation_id
			                  AND export_file_id IS NOT NULL)"
		);
		$stmt->execute([':order_id' => $orderId, ':allocation_id' => $allocationId]);
		return (bool) $stmt->fetchColumn();
	}

	/**
	 * The order's lines with their article unit and tax percent.
	 *
	 * @return array<array{id:int, quantity:string, unit_price:string, unit:?string, percent:int}>
	 */
	public function fetchOrderLines(int $orderId): array
	{
		$stmt = $this->db->prepare(
			"SELECT l.id, l.quantity, l.unit_price, m.unit, v.percent_
			 FROM bb_purchase_order_line l
			 LEFT JOIN bb_article_mapping m ON m.id = l.article_mapping_id
			 LEFT JOIN fm_ecomva v ON v.id = l.tax_code
			 WHERE l.order_id = :id
			 ORDER BY l.id"
		);
		$stmt->execute([':id' => $orderId]);

		return array_map(fn(array $row) => [
			'id' => (int) $row['id'],
			'quantity' => (string) $row['quantity'],
			'unit_price' => (string) $row['unit_price'],
			'unit' => $row['unit'],
			// Whole percent, as every writer of the order lines reads fm_ecomva.percent_
			'percent' => (int) $row['percent_'],
		], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	public function updateOrderLine(int $lineId, float $quantity, float $amount, float $tax): void
	{
		$stmt = $this->db->prepare(
			"UPDATE bb_purchase_order_line SET quantity = :quantity, amount = :amount, tax = :tax WHERE id = :id"
		);
		$stmt->execute([
			':quantity' => sprintf('%.2F', $quantity),
			':amount' => sprintf('%.2F', $amount),
			':tax' => sprintf('%.2F', $tax),
			':id' => $lineId,
		]);
	}

	/**
	 * Mark the price as one the series must not overwrite: after the applicant
	 * shortened this occurrence its price differs from the rest of the group on
	 * purpose, so a later group price change skips it unless the case officer
	 * chooses to overwrite it ("Overskriv alle").
	 */
	public function lockPrice(int $allocationId): void
	{
		$stmt = $this->db->prepare("UPDATE bb_allocation SET price_locked = 1 WHERE id = :id");
		$stmt->execute([':id' => $allocationId]);
	}
}
