<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2016 Free Software Foundation, Inc. http://www.fsf.org/
 * This file is part of phpGroupWare.
 *
 * phpGroupWare is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * phpGroupWare is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with phpGroupWare; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package property
 * @subpackage helpdesk
 * @version $Id$
 */

use App\Database\Db;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\controllers\Accounts\Accounts;

/**
 * Description
 * @package property
 */

if (!class_exists("lag_agresso_ordre_fra_melding"))
{

	class lag_agresso_ordre_fra_melding
	{

		var $debug = false;
		var $cats;
		var $db;

		function __construct()
		{
			$this->cats					 = CreateObject('phpgwapi.categories', -1, 'property', '.project');
			$this->cats->supress_info	 = true;
			$location_obj				 = new Locations();
			$config						 = CreateObject('admin.soconfig', $location_obj->get_id('property', '.invoice'));
			$this->debug				 = empty($config->config_data['export']['activate_transfer']) ? true : false;
			$this->db					 = Db::getInstance();
		}

		public function transfer($id, $resend_order = null)
		{
			/**
			 * Make sure it doesn't send files from development/test
			 */
			$serverSettings = Settings::getInstance()->get('server');
			if ($serverSettings['hostname'] !== 'fdvapp01e.srv.bergenkom.no')
			{
				return 2;
			}

			$_ticket = ExecMethod('property.sotts.read_single', $id);

			if (!$this->debug && $_ticket['order_sent'] && !$resend_order)
			{
				return 2;
			}

			$payment_type = CreateObject('property.sogeneric', 'order_template_payment_type')->read_single(array('id' => (int)$_ticket['payment_type']));

			if ($_ticket['payment_type'] && !$payment_type['transfer_to_external'])
			{
				return 2;
			}

			$config			 = CreateObject('phpgwapi.config', 'property');
			$config->read();

			$price	 = 0;
			$budgets = ExecMethod('property.botts.get_budgets', $id);
			foreach ($budgets as $budget)
			{

				$price += $budget['amount'];
			}

			try
			{
				$purchase_grant_ok = CreateObject('property.botts')->validate_purchase_grant($_ticket['ecodimb'], $price, $_ticket['order_id']);
			}
			catch (Exception $ex)
			{
				throw $ex;
			}

			if (!$this->debug && !$purchase_grant_ok)
			{
				return 3;
			}
			//		_debug_array($_ticket);die();

			$contacts = CreateObject('property.sogeneric');
			$contacts->get_location_info('vendor', false);

			$custom						 = createObject('property.custom_fields');
			$vendor_data['attributes']	 = $custom->find('property', '.vendor', 0, '', 'ASC', 'attrib_sort', true, true);

			$vendor_data = $contacts->read_single(array('id' => $_ticket['vendor_id']), $vendor_data);
			if (is_array($vendor_data))
			{
				if ($vendor_data['category'] == 2) // intern leverandør
				{
					return 2;
				}

				foreach ($vendor_data['attributes'] as $attribute)
				{
					if ($attribute['name'] == 'adresse')
					{
						$vendor['address'] = $attribute['value'];
					}
					if ($attribute['name'] == 'org_name')
					{
						$vendor['name'] = $attribute['value'];
					}
				}
			}
			unset($contacts);


			if ($resend_order || (Sanitizer::get_var('on_behalf_of_assigned', 'bool') && isset($_ticket['assignedto_name'])))
			{
				$user_name		 = $_ticket['assignedto_name'];

				$preferences = createObject('phpgwapi.preferences', $_ticket['assignedto']);
				Settings::getInstance()->update('user', ['preferences' => $preferences->data]);

				$accounts_obj	 = new Accounts();
				$account_lid	 = $accounts_obj->id2lid($_ticket['assignedto']);
			}
			else
			{
				$userSettings = Settings::getInstance()->get('user');
				$user_name	 = $userSettings['fullname'];
				$account_lid = $userSettings['account_lid'];
			}

			$address = mb_substr(htmlspecialchars($_ticket['address'], ENT_QUOTES, 'UTF-8', true), 0, 50);


			$buyer = array(
				'Name'				 => $user_name,
				'AddressInfo'		 => array(
					array(
						'Address' => htmlspecialchars_decode($address, ENT_QUOTES)
					)
				),
				'BuyerReferences'	 => array(
					array(
						'Responsible'	 => strtoupper($account_lid),
						'RequestedBy'	 => strtoupper($account_lid),
						'Accountable'	 => strtoupper($account_lid),
					)
				)
			);
			if ($_ticket['location_code'])
			{
				$_location_arr = explode('-', $_ticket['location_code']);
				$dim3 = $_location_arr[0];
			}
			else
			{
				$dim3 = 9;
			}

			if ($dim3 == 9999)
			{
				$dim3 = 9;
			}

			$dim6 = 9;

			//Override from order
			$tax_code = $_ticket['tax_code'] ? $_ticket['tax_code'] : 0;
			switch ($tax_code)
			{
				case '0':
					$tax_code	 = '6A';
					break;
				case '75':
					$tax_code	 = '60';
					break;
				default:
					$tax_code	 = '6A';
					break;
			}

			$collect_building_part = false;
			if (isset($config->config_data['workorder_require_building_part']))
			{
				if ($config->config_data['workorder_require_building_part'] == 1)
				{
					$collect_building_part = true;
				}
			}
			if ($collect_building_part)
			{
				if ($_ticket['order_dim1'])
				{
					$sogeneric		 = CreateObject('property.sogeneric', 'order_dim1');
					$sogeneric_data	 = $sogeneric->read_single(array('id' => $_ticket['order_dim1']));
					if ($sogeneric_data)
					{
						$dim6 = "{$_ticket['building_part']}{$sogeneric_data['num']}";
					}
				}
			}
			else
			{
				$category		 = $this->cats->return_single($_ticket['order_cat_id']);
				$category_arr	 = explode('-', $category[0]['name']);
				$dim6			 = (int)trim($category_arr[0]);
			}


			/*
				  P3: EBF Innkjøpsordre Portico : 45000000-45249999
				  V3: EBF Varemotttak Portico   : 45500000-45749999
				  P4: EBE Innkjøpsordre Portico : 45250000-45499999
				  V4: EBE Varemotttak Portico   : 45750000-45999999
				 */


			//EBF
			if ($_ticket['order_id'] >= 45000000 && $_ticket['order_id'] <= 45249999)
			{
				$voucher_type = 'P3';

				if (isset($_location_arr) && count($_location_arr) == 4)
				{
					$location_info = CreateObject('property.bolocation')->read_single($_ticket['location_code'], array('noattrib' => true));
					$formaal_id = (int)$location_info['cat_id'];
					$sql = "SELECT tjeneste_id FROM boei_formaal WHERE id = {$formaal_id}";
					$this->db->query($sql, __LINE__, __FILE__);
					$this->db->next_record();
					$_tjeneste = (int)$this->db->f('tjeneste_id');
					$_ticket['service_id'] = $_tjeneste ? $_tjeneste : $_ticket['service_id'];
				}
			}
			else if ($_ticket['order_id'] >= 45250000 && $_ticket['order_id'] <= 45499999)
			{
				$voucher_type = 'P4';
			}
			else
			{
				throw new Exception("Ordrenummer '{$_ticket['order_id']}' er utenfor serien:<br/>" . __FILE__ . '<br/>linje:' . __LINE__);
			}


			$param = array(
				'voucher_type'	 => $voucher_type,
				'dim0'			 => $_ticket['b_account_id'], // Art
				'dim1'			 => $_ticket['ecodimb'], // Ansvar
				'dim2'			 => $_ticket['service_id'] ? $_ticket['service_id'] : 9, // Tjeneste liste 30 stk, default 9
				'dim3'			 => $dim3, // Objekt: eiendom + bygg: 6 siffer
				'dim4'			 => $_ticket['contract_id'] == '-1' ? '' : $_ticket['contract_id'], // Kontrakt - frivillig / 9, 7 tegn - alfanumerisk
				'dim5'			 => $_ticket['external_project_id'], // Prosjekt
				'dim6'			 => $dim6, // Aktivitet - frivillig: bygningsdel, 3 siffer + bokstavkode
				'vendor_id'		 => $_ticket['vendor_id'],
				'vendor_name'	 => $vendor['name'],
				'vendor_address' => mb_substr($vendor['address'], 0, 50),
				'order_id'		 => $_ticket['order_id'],
				'tax_code'		 => $tax_code,
				'buyer'			 => $buyer,
				'invoice_remark' => mb_substr($_ticket['invoice_remark'], 0, 120),
				'lines'			 => array(
					array(
						'unspsc_code'	 => $_ticket['unspsc_code'] ? $_ticket['unspsc_code'] : 'UN-72000000',
						'descr'			 => '',
						'price'			 => $price,
					)
				)
			);

			$exporter_ordre = new BkBygg_exporter_data_til_Agresso(
				array(
					'order_id'		 => $_ticket['order_id'],
					'voucher_type'	 => $voucher_type
				)
			);
			$exporter_ordre->create_transfer_xml($param);

			$export_ok = $exporter_ordre->transfer();

			if ($export_ok)
			{
				Cache::message_set("Ordre #{$_ticket['order_id']} er overført");
				$this->log_transfer($id);
			}

			$voucher_type = 'V3';

			if ($_ticket['order_id'] >= 45000000 && $_ticket['order_id'] <= 45249999)
			{
				$voucher_type = 'V3';
			}
			else if ($_ticket['order_id'] >= 45250000 && $_ticket['order_id'] <= 45499999)
			{
				$voucher_type = 'V4';
			}
			else
			{
				throw new Exception("Ordrenummer '{$_ticket['order_id']}' er utenfor serien:<br/>" . __FILE__ . '<br/>linje:' . __LINE__);
			}

			$quantity = 1; // closing the order

			$param = array(
				'voucher_type'	 => $voucher_type,
				'order_id'		 => $_ticket['order_id'],
				'lines'			 => array(
					array(
						'UnitCode'	 => 'STK',
						'Quantity'	 => $quantity,
					)
				)
			);

			if (empty($_ticket['order_sent']))
			{
				$exporter_ordre->reset_transfer_xml();
				$exporter_ordre->create_order_receive_xml($param);
				$export_ok = $exporter_ordre->transfer($order_receive  = true);
			}

			return $export_ok;
		}

		private function log_transfer($id)
		{
			$historylog	 = CreateObject('property.historylog', 'tts');
			$historylog->add('RM', $id, "Ordre overført til agresso");
			$now		 = time();
			$this->db->query("UPDATE fm_tts_tickets SET order_sent = {$now} WHERE id = {$id}");
		}

		private function get_unspsc_code_descr($unspsc_code)
		{
			$this->db->query("SELECT name FROM fm_unspsc_code WHERE id = '{$unspsc_code}'");
			$this->db->next_record();
			return $this->db->f('name');
		}
	}
}


if ($data['order_sent'] && !$data['verified_transfered'])
{
	$resend_order = true;
}
else
{
	$resend_order = false;
}


if ((!empty($data['order_id']) && (!empty($data['send_order']) && !empty($data['vendor_email'][0]))) || $resend_order)
{
	$exporter_ordre					 = new lag_agresso_ordre_fra_melding();

	try
	{
		$data['purchase_grant_error']	 = $exporter_ordre->transfer($id, $resend_order) == 3 ? true : false;
		$data['purchase_grant_checked']	 = true;
	}
	catch (Exception $exc)
	{
		Cache::message_set($exc->getMessage(), 'error');
	}
}
