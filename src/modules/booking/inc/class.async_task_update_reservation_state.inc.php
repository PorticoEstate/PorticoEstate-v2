<?php

use App\Database\Db;

phpgw::import_class('booking.async_task');
phpgw::import_class('booking.socompleted_reservation');
phpgw::import_class('phpgwapi.datetime');


class booking_async_task_update_reservation_state extends booking_async_task
{

	private $soapplication, $sopurchase_order, $update_reservation_time, $activate_application_articles;

	public function __construct()
	{
		parent::__construct();
		$this->soapplication	 = CreateObject('booking.soapplication');
		$this->sopurchase_order	 = createObject('booking.sopurchase_order');
		$config					 = CreateObject('phpgwapi.config', 'booking')->read();

		$this->activate_application_articles = !empty($config['activate_application_articles']) ? true : false;

		$billing_delay = !empty($config['billing_delay']) ? (int) $config['billing_delay']  : 0;
		$this->update_reservation_time = date('Y-m-d');

		if ($billing_delay)
		{
			$_finnish_datestamp = time();
			for ($i = 1; $i < 30; $i++)
			{
				$finnish_datestamp	 = $_finnish_datestamp - (86400 * $i);
				$working_days	 = phpgwapi_datetime::get_working_days($finnish_datestamp, $_finnish_datestamp);
				if ($working_days == $billing_delay)
				{
					$this->update_reservation_time = date('Y-m-d', $finnish_datestamp) . ' 10:00:00';
					break;
				}
			}
		}
	}

	public function get_default_times()
	{
		return array('hour' => '*/1');
	}

	public function run($options = array())
	{
		$db = Db::getInstance();

		$reservation_types = array(
			//				'booking',
			'event',
			'allocation'
		);

		$completed_so = CreateObject('booking.socompleted_reservation');

		foreach ($reservation_types as $reservation_type)
		{
			$bo = CreateObject('booking.bo' . $reservation_type);

			$expired = $bo->find_expired($this->update_reservation_time);

			if (!is_array($expired) || !isset($expired['results']))
			{
				continue;
			}

			$db->transaction_begin();

			if (count($expired['results']) > 0)
			{
				foreach ($expired['results'] as $reservation)
				{
					$completed_so->create_from($reservation_type, $reservation);

					// Convert unbilled hospitality orders to purchase orders
					$application_id = (int)($reservation['application_id'] ?? 0);
					if ($application_id > 0)
					{
						$this->create_purchase_orders_from_hospitality($application_id, $reservation_type, $reservation['id']);
					}

					$orders = $completed_so->find_expired_orders($reservation_type, $reservation['id'], $this->update_reservation_time);

					/**
					 * For vipps kan det være flere krav, for etterfakturering vil det være ett
					 */
					foreach ($orders as $order_id)
					{
						$this->add_payment($order_id);
						$order = $this->sopurchase_order->get_single_purchase_order($order_id);
						$_reservation = $bo->read_single($reservation['id']);

						if ($this->activate_application_articles && (float)$_reservation['cost'] != (float)$order['sum'])
						{
							$_reservation['cost'] = $order['sum'];
							$this->add_cost_history($_reservation, 'update from order', $order['sum']);
							$bo->update($_reservation);
						}
					}
				}

				$bo->complete_expired($expired['results']);
			}

			$db->transaction_commit();
		}
	}

	/**
	 * Find unbilled hospitality orders for an application and convert them to purchase orders
	 * linked to the given reservation. This allows the existing payment flow to handle them.
	 */
	private function create_purchase_orders_from_hospitality(int $application_id, string $reservation_type, int $reservation_id): void
	{
		$db = Db::getInstance();

		// Find unbilled, non-cancelled hospitality orders with their lines.
		// Exclude orders where the hospitality is configured for checkout payment
		// AND a Vipps payment has already been made for the application.
		$sql = "SELECT ho.id AS hospitality_order_id,
					   ol.quantity, ol.unit_price, ol.tax_code, ol.amount,
					   ha.article_mapping_id
				FROM bb_hospitality_order ho
				JOIN bb_hospitality_order_line ol ON ol.order_id = ho.id
				JOIN bb_hospitality_article ha ON ol.hospitality_article_id = ha.id
				JOIN bb_hospitality h ON h.id = ho.hospitality_id
				WHERE ho.application_id = :application_id
				  AND ho.status != 'cancelled'
				  AND ho.billed = 0
				  AND NOT (
					h.include_in_checkout_payment = 1
					AND EXISTS (
						SELECT 1 FROM bb_purchase_order po
						JOIN bb_payment p ON p.order_id = po.id
						WHERE po.application_id = ho.application_id
						  AND p.payment_method_id = 1
						  AND p.status NOT IN ('voided', 'refunded')
					)
				  )
				ORDER BY ho.id, ol.id";

		$stmt = $db->prepare($sql);
		$stmt->execute([':application_id' => $application_id]);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		if (empty($rows))
		{
			return;
		}

		// Load tax percentages
		$tax_codes = [];
		$tax_sql = "SELECT id, percent_ FROM fm_ecomva";
		$tax_stmt = $db->prepare($tax_sql);
		$tax_stmt->execute();
		while ($tax_row = $tax_stmt->fetch(PDO::FETCH_ASSOC))
		{
			$tax_codes[(int)$tax_row['id']] = (int)$tax_row['percent_'];
		}

		// Group lines by hospitality order
		$orders_by_id = [];
		foreach ($rows as $row)
		{
			$ho_id = (int)$row['hospitality_order_id'];
			if (!isset($orders_by_id[$ho_id]))
			{
				$orders_by_id[$ho_id] = [];
			}
			$orders_by_id[$ho_id][] = $row;
		}

		// Create a purchase order for each hospitality order
		foreach ($orders_by_id as $ho_id => $lines)
		{
			// Create purchase order header
			$insert_sql = "INSERT INTO bb_purchase_order (application_id, status, customer_id, reservation_type, reservation_id)"
				. " VALUES (:application_id, 0, NULL, :reservation_type, :reservation_id)";
			$insert_stmt = $db->prepare($insert_sql);
			$insert_stmt->execute([
				':application_id' => $application_id,
				':reservation_type' => $reservation_type,
				':reservation_id' => $reservation_id,
			]);
			$purchase_order_id = (int)$db->lastInsertId();

			// Create purchase order lines
			$line_sql = "INSERT INTO bb_purchase_order_line"
				. " (order_id, status, parent_mapping_id, article_mapping_id, quantity, unit_price,"
				. " overridden_unit_price, currency, amount, tax_code, tax)"
				. " VALUES (:order_id, 1, 0, :article_mapping_id, :quantity, :unit_price,"
				. " :unit_price, 'NOK', :amount, :tax_code, :tax)";

			foreach ($lines as $line)
			{
				$amount = (float)$line['quantity'] * (float)$line['unit_price'];
				$percent = $tax_codes[(int)$line['tax_code']] ?? 0;
				$tax = $amount * $percent / 100;

				$line_stmt = $db->prepare($line_sql);
				$line_stmt->execute([
					':order_id' => $purchase_order_id,
					':article_mapping_id' => (int)$line['article_mapping_id'],
					':quantity' => (float)$line['quantity'],
					':unit_price' => (float)$line['unit_price'],
					':amount' => $amount,
					':tax_code' => (int)$line['tax_code'],
					':tax' => $tax,
				]);
			}

			// Mark the hospitality order as billed
			$billed_stmt = $db->prepare("UPDATE bb_hospitality_order SET billed = 1 WHERE id = :id");
			$billed_stmt->execute([':id' => $ho_id]);
		}
	}

	private function add_payment(int $order_id)
	{
		$this->soapplication->add_payment($order_id, 'local_invoice', 'live', 2);
	}

	private function add_cost_history(&$reservation, $comment = '', $cost = '0.00')
	{
		if (!$comment)
		{
			$comment = lang('cost is set');
		}

		$reservation['costs'][] = array(
			'time' => 'now',
			'author' => 'Cron-job',
			'comment' => $comment,
			'cost' => (float)$cost
		);
	}
}
	/*
Begreper:
application  - Søknad
allocation   - tildeling
booking      - Booking
event        - Arrangementer
reservation  - reservasjon / betalingsgrunnlag

En Søknad (application) kan resultere i en tildeling(allocation) eller et Arrangement(event).
En tildeling(allocation) kan deles opp i flere Booking(booking).

Utgangspunktet for 'Klar for fakturering' ligger i tabellen 'bb_completed_reservation'

Denne (reservation) er satt sammen av tre ulike element-typer: tildeling(allocation), Booking(booking) og Arrangement(event)

En Booking referer til en tildeling (allocation_id).

For å produsere innholdet i bb_completed_reservation:

cron-job som starter i booking/inc/class.async_task_update_reservation_state.inc.php

async_task_update_reservation_state::run()

Uttrekket defineres her (pr type):

 booking_sobooking::find_expired();
 booking_soallocation::find_expired();
 booking_soevent::find_expired();

Oppgaven her blir da:

1) Alle kandidater for 'booking' skal faktureres (som før)
2) Alle kandidater for 'event' skal faktureres (som før)
3) kandidater for 'allocation' som ikke er referert til fra 'booking' skal faktureres (omarbeides)

*/
