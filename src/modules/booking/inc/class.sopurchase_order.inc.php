<?php

use App\Database\Db;
use App\Database\Db2;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;

class booking_sopurchase_order
{
	protected
		$db,
		$db2,
		$join,
		$like;
	var $global_lock;

	function __construct()
	{
		$this->db = Db::getInstance();
		$this->db2 = new Db2();
		$this->join = $this->db->join;
		$this->like = $this->db->like;
	}

	function add_purchase_order($purchase_order)
	{
		if (empty($purchase_order['application_id']))
		{
			$msg = 'mangler referanse til søknad for å editere ordre';
			Cache::message_set($msg, 'error');
			return false;
		}

		if (!empty($purchase_order['reservation_type']) && empty($purchase_order['reservation_id']))
		{
			return false;
		}


		if ($this->db->get_transaction())
		{
			$this->global_lock = true;
		}
		else
		{
			$this->db->transaction_begin();
		}

		//--------  add or update master -------

		if (empty($purchase_order['reservation_id']))
		{
			$sql = "SELECT id FROM bb_purchase_order WHERE parent_id IS NULL AND application_id = " . (int)$purchase_order['application_id'];
		}
		//--------  or add or update slave -------
		else
		{
			$sql = "SELECT id FROM bb_purchase_order WHERE reservation_type = '{$purchase_order['reservation_type']}' AND reservation_id = " . (int)$purchase_order['reservation_id'];
		}


		$this->db->query($sql, __LINE__, __FILE__);
		$this->db->next_record();
		$order_id = (int)$this->db->f('id');
		if ($order_id)
		{
			$this->db->query("DELETE FROM bb_purchase_order_line WHERE order_id = $order_id", __LINE__, __FILE__);
		}
		else
		{
			$value_set = array(
				'application_id'	 => (int)$purchase_order['application_id'] > 0 ? (int)$purchase_order['application_id'] : null,
				'status'			 => 0,
				'customer_id'		 => null,
				'reservation_type'	 => !empty($purchase_order['reservation_type']) ? $purchase_order['reservation_type'] : null,
				'reservation_id'	 => !empty($purchase_order['reservation_id']) ? (int) $purchase_order['reservation_id'] : null
			);

			$this->db->query('INSERT INTO bb_purchase_order (' . implode(',', array_keys($value_set)) . ') VALUES ('
				. $this->db->validate_insert(array_values($value_set)) . ')', __LINE__, __FILE__);

			$order_id = $this->db->get_last_insert_id('bb_purchase_order', 'id');
		}

		//------------

		if (!empty($purchase_order['lines']))
		{
			$tax_codes = array();
			$sql = "SELECT id, percent_ FROM fm_ecomva";
			$this->db->query($sql, __LINE__, __FILE__);
			while ($this->db->next_record())
			{
				$tax_codes[(int)$this->db->f('id')] = (int)$this->db->f('percent_');
			}

			foreach ($purchase_order['lines'] as $line)
			{
				$article_mapping_ids[] = $line['article_mapping_id'];
			}

			/**
			 * FIXME
			 */
			$current_pricing = createObject('booking.soarticle_mapping')->get_current_pricing($article_mapping_ids);

			$add_sql = "INSERT INTO bb_purchase_order_line ("
				. " order_id, status, parent_mapping_id, article_mapping_id, quantity, unit_price,"
				. " overridden_unit_price, currency,  amount, tax_code, tax)"
				. " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

			$insert_update = array();
			foreach ($purchase_order['lines'] as $line)
			{
				if (empty($line['quantity']) || !(float)$line['quantity'] > 0)
				{
					continue;
				}

				$current_price_info = $current_pricing[$line['article_mapping_id']];

				$_ex_tax_price	 = $line['ex_tax_price'];

				/**
				 * Overridden price from case officer - else price from database
				 */
				$flags = Settings::getInstance()->get('flags');
				$currentapp = $flags['currentapp'];

				if ($currentapp  == 'booking' && !is_null($_ex_tax_price) && $_ex_tax_price != 'x') // restricted to backend
				{
					$unit_price = (float)$_ex_tax_price;
				}
				else
				{
					$unit_price = $current_price_info['price'];
				}

				$overridden_unit_price	 = $unit_price;
				$currency				 = 'NOK';

				// tax excluded
				$amount = $overridden_unit_price * (float)$line['quantity'];

				$_tax_code		 = $line['tax_code'];
				if ($currentapp  == 'booking' && !is_null($_tax_code) && $_tax_code != 'x') // restricted to backend
				{
					$tax_code	 = (int)$_tax_code;
					$percent	 = (int)$tax_codes[$tax_code];
				}
				else
				{
					$tax_code	 = $current_price_info['tax_code'];
					$percent	 = (int)$current_price_info['percent'];
				}

				$tax = $amount * $percent / 100;

				$insert_update[] = array(
					1	 => array(
						'value'	 => $order_id,
						'type'	 => PDO::PARAM_INT
					),
					2	 => array(
						'value'	 => 1,
						'type'	 => PDO::PARAM_INT
					),
					3	 => array(
						'value'	 => (int)$line['parent_mapping_id'],
						'type'	 => PDO::PARAM_INT
					),
					4	 => array(
						'value'	 => $line['article_mapping_id'],
						'type'	 => PDO::PARAM_INT
					),
					5	 => array(
						'value'	 => (float)$line['quantity'],
						'type'	 => PDO::PARAM_STR
					),
					6	 => array(
						'value'	 => (float)$unit_price,
						'type'	 => PDO::PARAM_STR
					),
					7	 => array(
						'value'	 => (float)$overridden_unit_price,
						'type'	 => PDO::PARAM_STR
					),
					8	 => array(
						'value'	 => $currency,
						'type'	 => PDO::PARAM_STR
					),
					9	 => array(
						'value'	 => $amount,
						'type'	 => PDO::PARAM_STR
					),
					10	 => array(
						'value'	 => $tax_code,
						'type'	 => PDO::PARAM_INT
					),
					11	 => array(
						'value'	 => (float)$tax,
						'type'	 => PDO::PARAM_STR
					),
				);
			}
			$this->db->insert($add_sql, $insert_update, __LINE__, __FILE__);
		}


		if (!$this->global_lock)
		{
			$this->db->transaction_commit();
		}
		return $order_id;
	}

	function delete_purchase_order($application_id)
	{
		if ($this->db->get_transaction())
		{
			$this->global_lock = true;
		}
		else
		{
			$this->db->transaction_begin();
		}

		$sql = "SELECT id AS order_id FROM bb_purchase_order WHERE application_id =" . (int)$application_id;

		$this->db->query($sql, __LINE__, __FILE__);
		$order_ids = array(-1);
		while ($this->db->next_record())
		{
			$order_ids[] = (int)$this->db->f('order_id');
		}
		$now = time();

		//			$sql = "DELETE FROM bb_purchase_order_line WHERE order_id IN (" . implode(',', $order_ids) . ")";
		//			$this->db->query($sql, __LINE__, __FILE__);
		//			$sql = "DELETE FROM bb_purchase_order WHERE id IN (" . implode(',', $order_ids) . ")";
		$sql = "UPDATE bb_purchase_order SET status = 0,  cancelled = $now, application_id = NULL WHERE id IN (" . implode(',', $order_ids) . ")";
		$this->db->query($sql, __LINE__, __FILE__);

		if (!$this->global_lock)
		{
			return $this->db->transaction_commit();
		}
	}

	function get_single_purchase_order($order_id)
	{
		if (!$order_id)
		{
			return;
		}

		$sql = "SELECT bb_purchase_order_line.* , bb_purchase_order.application_id,"
			. "CASE WHEN
					(
						bb_resource.name IS NULL
					)"
			. " THEN bb_service.name ELSE bb_resource.name END AS name"
			. " FROM bb_purchase_order JOIN bb_purchase_order_line ON bb_purchase_order.id = bb_purchase_order_line.order_id"
			. " JOIN bb_article_mapping ON bb_purchase_order_line.article_mapping_id = bb_article_mapping.id"
			. " LEFT JOIN bb_service ON (bb_article_mapping.article_id = bb_service.id AND bb_article_mapping.article_cat_id = 2)"
			. " LEFT JOIN bb_resource ON (bb_article_mapping.article_id = bb_resource.id AND bb_article_mapping.article_cat_id = 1)"
			. " WHERE bb_purchase_order.id = " . (int)$order_id
			. " ORDER BY bb_purchase_order_line.id";

		$this->db->query($sql, __LINE__, __FILE__);

		$order		 = array();
		$sum		 = 0;
		$total_sum	 = 0;
		while ($this->db->next_record())
		{
			$application_id	 = (int)$this->db->f('application_id');
			$order_id		 = (int)$this->db->f('order_id');

			$_sum		 = (float)$this->db->f('amount') + (float)$this->db->f('tax');
			$sum		 = (float)$sum + $_sum;
			$total_sum	 += $_sum;

			$order['lines'][] = array(
				'application_id'		 => $application_id,
				'order_id'				 => $order_id,
				'status'				 => (int)$this->db->f('status'),
				'article_mapping_id'	 => (int)$this->db->f('article_mapping_id'),
				'quantity'				 => (float)$this->db->f('quantity'),
				'unit_price'			 => (float)$this->db->f('unit_price'),
				'overridden_unit_price'	 => (float)$this->db->f('overridden_unit_price'),
				'currency'				 => $this->db->f('currency'),
				'amount'				 => (float)$this->db->f('amount'),
				'tax_code'				 => (int)$this->db->f('tax_code'),
				'tax'					 => (float)$this->db->f('tax'),
				'name'					 => $this->db->f('name', true),
			);

			$order['order_id']	 = $order_id;
			$order['sum']		 = $sum;
		}
		return $order;
	}

	function get_purchase_order($application_id = 0, $reservation_type = '', $reservation_id = 0, $collection = false)
	{

		if (!$application_id && !($reservation_type && $reservation_id))
		{
			return array();
		}

		if ($reservation_type && !in_array($reservation_type, array('event', 'allocation')))
		{
			return array();
		}

		$tax_codes = array();
		$sql = "SELECT id, percent_ FROM fm_ecomva";
		$this->db->query($sql, __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$tax_codes[(int)$this->db->f('id')] = (int)$this->db->f('percent_');
		}

		$filtermethod = 'WHERE bb_purchase_order.cancelled IS NULL';

		if ($reservation_type && (int) $reservation_id)
		{
			$filtermethod .= " AND bb_purchase_order.reservation_type = '{$reservation_type}' AND bb_purchase_order.reservation_id = " . (int) $reservation_id;
		}
		else if ((int) $application_id && $collection == false)
		{
			$filtermethod .= " AND bb_purchase_order.parent_id IS NULL AND bb_purchase_order.application_id = " . (int) $application_id;
		}
		else if ((int) $application_id && $collection == true)
		{
			$filtermethod .= " AND bb_purchase_order.application_id = " . (int) $application_id;
		}

		$sql = "SELECT bb_purchase_order_line.* , bb_purchase_order.application_id,"
			. " bb_article_mapping.article_code, bb_article_mapping.article_alternative_code,"
			. " CASE WHEN
					(
						bb_resource.name IS NULL
					)"
			. " THEN bb_service.name ELSE bb_resource.name END AS name"
			. " FROM bb_purchase_order JOIN bb_purchase_order_line ON bb_purchase_order.id = bb_purchase_order_line.order_id"
			. " JOIN bb_article_mapping ON bb_purchase_order_line.article_mapping_id = bb_article_mapping.id"
			. " LEFT JOIN bb_service ON (bb_article_mapping.article_id = bb_service.id AND bb_article_mapping.article_cat_id = 2)"
			. " LEFT JOIN bb_resource ON (bb_article_mapping.article_id = bb_resource.id AND bb_article_mapping.article_cat_id = 1)"
			. " {$filtermethod}"
			. " ORDER BY bb_purchase_order_line.id";

		$this->db->query($sql, __LINE__, __FILE__);

		$order		 = array();
		$sum		 = array();
		$total_sum	 = 0;
		while ($this->db->next_record())
		{
			$order_id		 = (int)$this->db->f('order_id');
			if (!isset($sum[$order_id]))
			{
				$sum[$order_id] = 0;
			}

			$_sum			 = (float)$this->db->f('amount') + (float)$this->db->f('tax');
			$sum[$order_id]	 = (float)$sum[$order_id] + $_sum;
			$total_sum		 += $_sum;

			$tax_code		 = (int)$this->db->f('tax_code');

			$order['lines'][] = array(
				'order_id'				 => $order_id,
				'status'				 => (int)$this->db->f('status'),
				'parent_mapping_id'		 => (int)$this->db->f('parent_mapping_id'),
				'article_mapping_id'	 => (int)$this->db->f('article_mapping_id'),
				'quantity'				 => (float)$this->db->f('quantity'),
				'unit_price'			 => (float)$this->db->f('unit_price'),
				'overridden_unit_price'	 => (float)$this->db->f('overridden_unit_price'),
				'currency'				 => $this->db->f('currency'),
				'amount'				 => (float)$this->db->f('amount'),
				'tax_code'				 => (int)$this->db->f('tax_code'),
				'article_code'			 => $this->db->f('article_code', true),
				'article_alternative_code' => $this->db->f('article_alternative_code', true),
				'tax'					 => (float)$this->db->f('tax'),
				'name'					 => $this->db->f('name', true),
				'tax_percent'			 => $tax_codes[$tax_code]
			);

			$order['order_id']	 = $order_id;
			$order['sum']		 = $sum[$order_id];
		}

		return $order;
	}

	function get_purchase_order_combined($application_id)
	{
		if (!$application_id)
		{
			return array();
		}

		// Get related applications (parent + children) for combined display
		$application_bo = createObject('booking.boapplication');
		$related_info = $application_bo->so->get_related_applications($application_id);
		$application_ids = $related_info['application_ids'];

		if (empty($application_ids))
		{
			return array();
		}

		$tax_codes = array();
		$sql = "SELECT id, percent_ FROM fm_ecomva";
		$this->db->query($sql, __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$tax_codes[(int)$this->db->f('id')] = (int)$this->db->f('percent_');
		}

		$application_ids_string = implode(',', $application_ids);
		$filtermethod = "WHERE bb_purchase_order.cancelled IS NULL";
		$filtermethod .= " AND bb_purchase_order.application_id IN ({$application_ids_string})";
		// Only include lines that have a cost > 0
		$filtermethod .= " AND (bb_purchase_order_line.amount > 0 OR bb_purchase_order_line.tax > 0)";

		$sql = "SELECT bb_purchase_order_line.* , bb_purchase_order.application_id,"
			. " bb_article_mapping.article_code, bb_article_mapping.article_alternative_code,"
			. " CASE WHEN
					(
						bb_resource.name IS NULL
					)"
			. " THEN bb_service.name ELSE bb_resource.name END AS name"
			. " FROM bb_purchase_order JOIN bb_purchase_order_line ON bb_purchase_order.id = bb_purchase_order_line.order_id"
			. " JOIN bb_article_mapping ON bb_purchase_order_line.article_mapping_id = bb_article_mapping.id"
			. " LEFT JOIN bb_service ON (bb_article_mapping.article_id = bb_service.id AND bb_article_mapping.article_cat_id = 2)"
			. " LEFT JOIN bb_resource ON (bb_article_mapping.article_id = bb_resource.id AND bb_article_mapping.article_cat_id = 1)"
			. " {$filtermethod}"
			. " ORDER BY bb_purchase_order_line.id";

		$this->db->query($sql, __LINE__, __FILE__);

		$order		 = array();
		$sum		 = array();
		$total_sum	 = 0;
		while ($this->db->next_record())
		{
			$order_id		 = (int)$this->db->f('order_id');
			if (!isset($sum[$order_id]))
			{
				$sum[$order_id] = 0;
			}

			$_sum			 = (float)$this->db->f('amount') + (float)$this->db->f('tax');
			$sum[$order_id]	 = (float)$sum[$order_id] + $_sum;
			$total_sum		 += $_sum;

			$tax_code		 = (int)$this->db->f('tax_code');

			$order['lines'][] = array(
				'order_id'				 => $order_id,
				'status'				 => (int)$this->db->f('status'),
				'parent_mapping_id'		 => (int)$this->db->f('parent_mapping_id'),
				'article_mapping_id'	 => (int)$this->db->f('article_mapping_id'),
				'quantity'				 => (float)$this->db->f('quantity'),
				'unit_price'			 => (float)$this->db->f('unit_price'),
				'overridden_unit_price'	 => (float)$this->db->f('overridden_unit_price'),
				'currency'				 => $this->db->f('currency'),
				'amount'				 => (float)$this->db->f('amount'),
				'tax_code'				 => (int)$this->db->f('tax_code'),
				'article_code'			 => $this->db->f('article_code', true),
				'article_alternative_code' => $this->db->f('article_alternative_code', true),
				'tax'					 => (float)$this->db->f('tax'),
				'name'					 => $this->db->f('name', true),
				'tax_percent'			 => $tax_codes[$tax_code]
			);

			$order['order_id']	 = $order_id;
			$order['sum']		 = $sum[$order_id];
		}

		return $order;
	}

	public function identify_purchase_order($application_id, $reservation_id, $reservation_type = 'event')
	{
		if (!$application_id || !$reservation_id)
		{
			return;
		}

		if (!in_array($reservation_type, array('event', 'allocation')))
		{
			return;
		}

		$this->db->query("UPDATE bb_purchase_order"
			. " SET reservation_type = '{$reservation_type}', reservation_id = " . (int)$reservation_id
			. " WHERE parent_id IS NULL"
			. " AND application_id =" . (int)$application_id, __LINE__, __FILE__);
	}

	public function copy_purchase_order_from_application($reservation, $_reservation_id, $reservation_type = 'event')
	{
		$purchase_order_id = null;
		$application_id	 = (int)$reservation['application_id'];
		$reservation_id	 = (int)$_reservation_id;

		if (!$application_id || !$reservation_id)
		{
			return;
		}

		if (!in_array($reservation_type, array('event', 'allocation')))
		{
			return;
		}

		/**
		 * Find first order related to application
		 */
		$sql = "SELECT id FROM bb_purchase_order WHERE reservation_type IS NULL AND reservation_id IS NULL AND application_id = {$application_id}";
		$this->db->query($sql, __LINE__, __FILE__);
		if ($this->db->next_record())
		{
			$purchase_order_id = $this->db->f('id');
			/**
			 * Place the order where it belong
			 */
			$this->identify_purchase_order($application_id, $reservation_id, $reservation_type);
		}
		else
		{
			$sql = "SELECT * FROM bb_purchase_order WHERE application_id = {$application_id} AND parent_id IS NULL";
			$this->db->query($sql, __LINE__, __FILE__);
			$this->db->next_record();

			$order_id		 = (int)$this->db->f('id');
			if ($order_id)
			{
				$customer_id	 = (int)$this->db->f('customer_id');
				$valueset		 = array(
					'parent_id'			 => $order_id,
					'status'			 => (int)$this->db->f('status'),
					'application_id'	 => $application_id,
					'customer_id'		 => $customer_id ? $customer_id : null,
					'reservation_type'	 => $reservation_type,
					'reservation_id'	 => $reservation_id,
				);
				$insert_fields	 = implode(',', array_keys($valueset));
				$insert_values	 = $this->db->validate_insert(array_values($valueset));
				$this->db->query("INSERT INTO bb_purchase_order ({$insert_fields}) VALUES ({$insert_values})", __LINE__, __FILE__);
				$purchase_order_id	 = $this->db->get_last_insert_id('bb_purchase_order', 'id');
				$this->copy_order_lines($order_id, $purchase_order_id);
			}
		}

		return $purchase_order_id;
	}

	function copy_order_lines($from_id, $to_id)
	{

		$sql = "SELECT * FROM bb_purchase_order_line WHERE order_id = " . (int)$from_id;
		$this->db->query($sql, __LINE__, __FILE__);

		$valueset = array();

		while ($this->db->next_record())
		{
			$valueset[] = array(
				1	 => array(
					'value'	 => (int)$to_id,
					'type'	 => PDO::PARAM_INT
				),
				2	 => array(
					'value'	 => 1,
					'type'	 => PDO::PARAM_INT
				),
				3	 => array(
					'value'	 => (int)$this->db->f('parent_mapping_id'),
					'type'	 => PDO::PARAM_INT
				),
				4	 => array(
					'value'	 => (int)$this->db->f('article_mapping_id'),
					'type'	 => PDO::PARAM_INT
				),
				5	 => array(
					'value'	 => $this->db->f('unit_price'),
					'type'	 => PDO::PARAM_STR
				),
				6	 => array(
					'value'	 => $this->db->f('overridden_unit_price'),
					'type'	 => PDO::PARAM_STR
				),
				7	 => array(
					'value'	 => $this->db->f('currency'),
					'type'	 => PDO::PARAM_STR
				),
				8	 => array(
					'value'	 => $this->db->f('quantity'),
					'type'	 => PDO::PARAM_STR
				),
				9	 => array(
					'value'	 => $this->db->f('amount'),
					'type'	 => PDO::PARAM_STR
				),
				10	 => array(
					'value'	 => (int)$this->db->f('tax_code'),
					'type'	 => PDO::PARAM_INT
				),
				11	 => array(
					'value'	 => $this->db->f('tax'),
					'type'	 => PDO::PARAM_STR
				),
			);
		}

		$sql_insert = 'INSERT INTO bb_purchase_order_line'
			. ' (order_id, status, parent_mapping_id, article_mapping_id, unit_price, overridden_unit_price, currency, quantity, amount, tax_code, tax)'
			. ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

		if ($valueset)
		{
			return $this->db->insert($sql_insert, $valueset, __LINE__, __FILE__);
		}
	}

	function get_order_payments($order_id)
	{
		if (empty($order_id))
		{
			return array();
		}

		$data	 = array();
		$sql	 = "SELECT * FROM bb_payment"
			. " WHERE order_id = {$order_id}"
			. " ORDER BY id";

		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$payment_method_id =  $this->db->f('payment_method_id');
			$payment_method = $payment_method_id == 2 ? 'Etterfakturering' : 'Vipps';

			$data[] = array(
				'id'					 => $this->db->f('id'),
				'order_id'				 => $this->db->f('order_id'),
				'payment_method'		 => $payment_method,
				'payment_gateway_mode'	 => $this->db->f('payment_gateway_mode'),
				'remote_id'				 => $this->db->f('remote_id'),
				'remote_state'			 => $this->db->f('remote_state'),
				'amount'				 => (float)$this->db->f('amount'),
				'currency'				 => $this->db->f('currency'),
				'refunded_amount'		 => (float)$this->db->f('refunded_amount'),
				'refunded_currency'		 => $this->db->f('refunded_currency'),
				'status'				 => $this->db->f('status'), //'new', pending, completed, voided, partially_refunded, refunded
				'created'				 => $this->db->f('created'),
				'autorized'				 => $this->db->f('autorized'),
				'expires'				 => $this->db->f('expires'),
				'completet'				 => $this->db->f('completet'),
				'captured'				 => $this->db->f('captured'),
			);
		}
		return $data;
	}

	/**
	 * Carry one allocation's article lines onto another allocation in the same
	 * recurrence group, and hand back what the receiving order now totals.
	 *
	 * WHY THIS EXISTS. With activate_application_articles on, bb_allocation.cost
	 * is not authoritative for anything: booking_uiallocation's edit page renders
	 * the price from the purchase order and leaves cost readOnly, and
	 * async_task_update_reservation_state invoices the ORDER and then writes the
	 * order's sum back over cost. So a price cascade that moves cost alone is
	 * invisible on the page, unbilled, and silently reverted the moment the
	 * occurrence expires. The price has to move as LINES or it has not moved.
	 *
	 * The lines are copied verbatim rather than rebuilt through
	 * add_purchase_order(): that path re-derives every unit price from
	 * soarticle_mapping::get_current_pricing(), so rebuilding would quietly
	 * reprice the copy against today's article table instead of carrying the
	 * price the officer actually set. copy_order_lines() moves amount, tax and
	 * tax_code across untouched, which is what "the same price" means here.
	 *
	 * 🔴 AN ORDER THAT HAS BEEN BILLED IS NEVER REWRITTEN. A bb_payment row, or a
	 * bb_completed_reservation already associated with an export file, means the
	 * money has left the building; re-pricing it after the fact would change what
	 * a citizen was invoiced. Such a member is SKIPPED and says so in its status,
	 * and the caller must then leave its cost alone as well - moving cost while
	 * leaving the order behind is the very defect this method exists to fix.
	 * The guard deliberately matches socompleted_reservation::find_expired_orders,
	 * which selects on bb_payment.id IS NULL for the same reason.
	 *
	 * A target with no order of its own gets one from
	 * copy_purchase_order_from_application(), which already relocates the
	 * application's unplaced order or mints a child of it. Reusing it is what
	 * keeps the parent_id chain intact; re-implementing the insert here would be
	 * a second place for that topology to drift.
	 *
	 * IDEMPOTENT. The target's order is identified by
	 * (reservation_type, reservation_id), a pair that names at most one row, and
	 * its lines are deleted before the copy. Running the same cascade twice
	 * therefore finds the same order, mints nothing, and leaves the same lines.
	 *
	 * @param int $source_order_id the order whose lines are authoritative
	 * @param int $application_id  the application both allocations belong to
	 * @param int $allocation_id   the allocation receiving the price
	 * @return array status: written|skipped_billed|skipped_exported|skipped_no_order|skipped_source
	 *               order_id: the receiving order, where one was found or made
	 *               sum: TAX-INCLUSIVE total of the receiving order, or null
	 */
	public function cascade_order_to_allocation($source_order_id, $application_id, $allocation_id)
	{
		$source_order_id = (int)$source_order_id;
		$application_id	 = (int)$application_id;
		$allocation_id	 = (int)$allocation_id;

		$result = array('status' => 'skipped_no_order', 'order_id' => null, 'sum' => null);

		if (!$source_order_id || !$application_id || !$allocation_id)
		{
			return $result;
		}

		$this->db->query("SELECT id FROM bb_purchase_order"
			. " WHERE reservation_type = 'allocation'"
			. " AND reservation_id = {$allocation_id}", __LINE__, __FILE__);

		$target_order_id = $this->db->next_record() ? (int)$this->db->f('id') : 0;

		// The source cannot cascade onto itself; its own order is already the truth.
		if ($target_order_id && $target_order_id === $source_order_id)
		{
			$result['status']	 = 'skipped_source';
			$result['order_id']	 = $target_order_id;
			return $result;
		}

		if (!$target_order_id)
		{
			$target_order_id = (int)$this->copy_purchase_order_from_application(
				array('application_id' => $application_id), $allocation_id, 'allocation');

			if (!$target_order_id)
			{
				return $result;
			}
		}

		$result['order_id'] = $target_order_id;

		if ($this->get_order_payments($target_order_id))
		{
			$result['status'] = 'skipped_billed';
			return $result;
		}

		$this->db->query("SELECT id FROM bb_completed_reservation"
			. " WHERE reservation_type = 'allocation'"
			. " AND reservation_id = {$allocation_id}"
			. " AND export_file_id IS NOT NULL", __LINE__, __FILE__);

		if ($this->db->next_record())
		{
			$result['status'] = 'skipped_exported';
			return $result;
		}

		if ($this->db->get_transaction())
		{
			$this->global_lock = true;
		}
		else
		{
			$this->db->transaction_begin();
		}

		$this->db->query("DELETE FROM bb_purchase_order_line"
			. " WHERE order_id = {$target_order_id}", __LINE__, __FILE__);

		$this->copy_order_lines($source_order_id, $target_order_id);

		if (!$this->global_lock)
		{
			$this->db->transaction_commit();
		}

		// TAX BASIS. get_single_purchase_order() sums amount + tax per line, so
		// what comes back is TAX-INCLUSIVE and is the figure bb_allocation.cost
		// wants. sum(amount) alone is ex-tax and would undercharge by the tax -
		// 25% on nearly every live line, 0% on the handful carrying tax_code 0,
		// which is exactly the shape that makes the mistake look correct in a
		// spot check. The rate is per line, from fm_ecomva.percent_, never global.
		$order = $this->get_single_purchase_order($target_order_id);

		$result['status']	 = 'written';
		$result['sum']		 = isset($order['sum']) ? (float)$order['sum'] : null;

		return $result;
	}
}
