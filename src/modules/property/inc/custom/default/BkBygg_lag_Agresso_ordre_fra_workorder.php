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
if (!class_exists("lag_agresso_ordre_fra_workorder"))
{

	class lag_agresso_ordre_fra_workorder
	{

		var $debug = false;
		var $cats;
		var $db;

		public function __construct()
		{
			$this->cats					 = CreateObject('phpgwapi.categories', -1, 'property', '.project');
			$this->cats->supress_info	 = true;
			$location_obj				 = new Locations();
			$config						 = CreateObject('admin.soconfig', $location_obj->get_id('property', '.invoice'));
			$this->debug				 = empty($config->config_data['export']['activate_transfer']) ? true : false;
			$this->db 					 = Db::getInstance();
		}

		public function transfer($workorder, $transfer_action)
		{
			/**
			 * Make sure it doesn't send files from development/test
			 */
			$serverSettings = Settings::getInstance()->get('server');
			if ($serverSettings['hostname'] !== 'fdvapp01e.srv.bergenkom.no')
			{
				return 2;
			}

			$project = createObject('property.boproject')->read_single($workorder['project_id'], array(), true);

			if (!$this->debug && $workorder['order_sent'] && $transfer_action !== 'resend_workorder')
			{
				$phpgwapi_common = new \phpgwapi_common();
				$transfer_time = $phpgwapi_common->show_date($workorder['order_sent']);
				Cache::message_set("Info: Ordre #{$workorder['id']} er allerede overført til Agresso {$transfer_time}");
				return 2;
			}

			$config			 = CreateObject('phpgwapi.config', 'property');
			$config->read();
			$approval_level	 = !empty($config->config_data['approval_level']) ? $config->config_data['approval_level'] : 'order';

			$approval_amount = 0;
			$price			 = 0;
			if ($approval_level == 'project')
			{
				$approval_amount = ExecMethod('property.boworkorder.get_accumulated_budget_amount', $workorder['project_id']);
				$price			 = (float)ExecMethod('property.boworkorder.get_budget_amount', $workorder['id']);
			}
			else
			{
				$approval_amount = ExecMethod('property.boworkorder.get_budget_amount', $workorder['id']);
				$price			 = (float)$approval_amount;
			}

			try
			{
				$purchase_grant_ok = CreateObject('property.botts')->validate_purchase_grant($workorder['ecodimb'], $approval_amount, $workorder['id']);
			}
			catch (Exception $ex)
			{
				throw $ex;
			}

			if (!$this->debug && !$purchase_grant_ok)
			{
				return 3;
			}

			$contacts = CreateObject('property.sogeneric');
			$contacts->get_location_info('vendor', false);

			$custom						 = createObject('property.custom_fields');
			$vendor_data['attributes']	 = $custom->find('property', '.vendor', 0, '', 'ASC', 'attrib_sort', true, true);

			$vendor_data = $contacts->read_single(array('id' => $workorder['vendor_id']), $vendor_data);
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

			$preferences = createObject('phpgwapi.preferences');
			$preferences->setAccountId($workorder['user_id']);

			$accounts_obj	 = new Accounts();

			$user_name	 = $accounts_obj->get($workorder['user_id'])->__toString();
			$account_lid = $accounts_obj->id2lid($workorder['user_id']);


			if ($workorder['ecodimb'])
			{
				$dim1 = $workorder['ecodimb'];
			}
			else if ($project['ecodimb'])
			{
				$dim1 = $project['ecodimb'];
			}
			else
			{
				throw new Exception('Dimensjonen "Ansvar" mangler');
			}

			if (!empty($workorder['location_code']))
			{
				$location_code	 = $workorder['location_code'];
				$location		 = explode('-', $location_code);
				//				$dim3 = isset($location[1]) && $location[1] ? "{$location[0]}{$location[1]}" : "{$location[0]}01";
				$dim3			 = $location[0];
			}
			else if ($project['location_code'])
			{
				$location_code	 = $project['location_code'];
				$location		 = explode('-', $location_code);
				//				$dim3 = isset($location[1]) && $location[1] ? "{$location[0]}{$location[1]}" : "{$location[0]}01";
				$dim3			 = $location[0];
			}
			else
			{
				$dim3 = 9;
			}

			if ($dim3 == 9999)
			{
				$dim3 = 9;
			}

			$address_element = execMethod('property.botts.get_address_element', $location_code);
			$_address		 = array();
			foreach ($address_element as $entry)
			{
				$_address[] = "{$entry['text']}: {$entry['value']}";
			}

			$address = '';
			if ($_address)
			{
				$address = implode(', ', $_address);
			}

			$address = mb_substr(htmlspecialchars($address, ENT_QUOTES, 'UTF-8', true), 0, 50);

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


			//EBF...

			$location_info = execMethod('property.bolocation.read_single', $location[0]);

			$named_attributes = array();

			if (isset($location_info['attributes']) && is_array($location_info['attributes']))
			{
				foreach ($location_info['attributes'] as $key => $attribute)
				{
					$named_attributes[$attribute['name']] = $attribute['value'];
				}
			}

			$tax_code	 = !empty($named_attributes['mva']) ? $named_attributes['mva'] : 0;
			//Override from workorder
			$tax_code	 = $workorder['tax_code'] ? $workorder['tax_code'] : $tax_code;

			$tjeneste	 = !empty($named_attributes['kostra_id']) ? $named_attributes['kostra_id'] : 9;
			//Override from workorder
			$tjeneste	 = $workorder['service_id'] && $workorder['service_id'] != 9 ? (int)$workorder['service_id'] : (int)$tjeneste;


			//EBF
			if ($workorder['id'] >= 45000000 && $workorder['id'] <= 45249999 && count($location) == 4)
			{
				$location_info = CreateObject('property.bolocation')->read_single($location_code, array('noattrib' => true));
				$formaal_id = (int)$location_info['cat_id'];
				$sql = "SELECT tjeneste_id FROM boei_formaal WHERE id = {$formaal_id}";
				$this->db->query($sql, __LINE__, __FILE__);
				$this->db->next_record();
				$_tjeneste = (int)$this->db->f('tjeneste_id');

				$tjeneste = $_tjeneste ? $_tjeneste : $tjeneste;
			}


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

			//art 0230...
			//	->69'
			if ($workorder['b_account_id'] == '023020')
			{
				$tax_code = '69';
			}


			$this->db->query("UPDATE fm_workorder SET service_id = {$tjeneste} WHERE id = {$workorder['id']}");

			//			_debug_array($location_info);die();

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
				if ($workorder['order_dim1'])
				{
					$sogeneric		 = CreateObject('property.sogeneric', 'order_dim1');
					$sogeneric_data	 = $sogeneric->read_single(array('id' => $workorder['order_dim1']));
					if ($sogeneric_data)
					{
						$dim6 = "{$workorder['building_part']}{$sogeneric_data['num']}";
					}
				}
			}
			else
			{
				$category		 = $this->cats->return_single($workorder['cat_id']);
				$category_arr	 = explode('-', $category[0]['name']);
				$dim6			 = (int)trim($category_arr[0]);
			}

			/*
				  P3: EBF Innkjøpsordre Portico : 45000000-45249999
				  V3: EBF Varemotttak Portico   : 45500000-45749999
				  P4: EBE Innkjøpsordre Portico : 45250000-45499999
				  V4: EBE Varemotttak Portico   : 45750000-45999999
				 */

			//			$voucher_type = 'P4';

			if ($workorder['id'] >= 45000000 && $workorder['id'] <= 45249999)
			{
				$voucher_type = 'P3';
			}
			else if ($workorder['id'] >= 45250000 && $workorder['id'] <= 45499999)
			{
				$voucher_type = 'P4';
			}
			else
			{
				throw new Exception("Ordrenummer '{$workorder['id']}' er utenfor serien:<br/>" . __FILE__ . '<br/>linje:' . __LINE__);
			}

			$param = array(
				'dim0'			 => $workorder['b_account_id'], // Art
				'dim1'			 => $dim1, // Ansvar
				'dim2'			 => $tjeneste, // Tjeneste liste 30 stk, default 9
				'dim3'			 => $dim3, // Objekt: eiendom + bygg: 6 siffer
				'dim4'			 => $workorder['contract_id'] && (int)$workorder['contract_id'] < 0 ? '' : $workorder['contract_id'], // Kontrakt - frivillig / 9, 7 tegn - alfanumerisk
				'dim5'			 => $project['external_project_id'], // Prosjekt
				'dim6'			 => $dim6, // Aktivitet - frivillig: bygningsdel, 3 siffer + bokstavkode
				'vendor_id'		 => $workorder['vendor_id'],
				'vendor_name'	 => $vendor['name'],
				'vendor_address' => mb_substr($vendor['address'], 0, 50),
				'order_id'		 => $workorder['id'],
				'tax_code'		 => $tax_code,
				'buyer'			 => $buyer,
				'invoice_remark' => mb_substr($workorder['title'], 0, 50),
				'lines'			 => array(
					array(
						'unspsc_code'	 => $workorder['unspsc_code'] ? $workorder['unspsc_code'] : 'UN-72000000',
						//						'descr' => strip_tags($workorder['descr'])
						'descr'			 => '',
						'price'			 => $price,
					)
				)
			);


			$exporter_ordre = new BkBygg_exporter_data_til_Agresso(
				array(
					'order_id'		 => $workorder['id'],
					'voucher_type'	 => $voucher_type
				)
			);
			$exporter_ordre->create_transfer_xml($param);

			$export_ok = $exporter_ordre->transfer($this->debug);

			if ($export_ok)
			{
				Cache::message_set("Ordre #{$workorder['id']} er overført til UBW");
				$this->log_transfer($workorder['id']);

				$interlink = CreateObject('property.interlink');

				$origin_data = $interlink->get_relation('property', '.project.workorder', $workorder['id'], 'origin');
				$origin_data = array_merge($origin_data, $interlink->get_relation('property', '.project', $workorder['project_id'], 'origin'));


				$tickets = array();
				foreach ($origin_data as $__origin)
				{
					if ($__origin['location'] != '.ticket')
					{
						continue;
					}

					foreach ($__origin['data'] as $_origin_data)
					{
						$tickets[] = $_origin_data['id'];
					}
				}

				//					if ($tickets)
				//					{
				//						$this->alert_external($workorder['id'], $tickets, $vendor['name'], $workorder['end_date']);
				//					}
			}
			else
			{
				throw new Exception("Overføring til UBW av Ordre #{$workorder['id']} feilet");
			}

			$voucher_type = 'V3';

			if ($workorder['id'] >= 45000000 && $workorder['id'] <= 45249999)
			{
				$voucher_type = 'V3';
			}
			else if ($workorder['id'] >= 45250000 && $workorder['id'] <= 45499999)
			{
				$voucher_type = 'V4';
			}
			else
			{
				throw new Exception("Ordrenummer '{$workorder['id']}' er utenfor serien:<br/>" . __FILE__ . '<br/>linje:' . __LINE__);
			}

			$quantity = 1; // closing the order

			$param = array(
				'voucher_type'	 => $voucher_type,
				'order_id'		 => $workorder['id'],
				'lines'			 => array(
					array(
						'UnitCode'	 => 'STK',
						'Quantity'	 => $quantity,
					)
				)
			);

		/*
		Generer varemottaket på nytt, dersom det er endringer i ordren
		*/
			//	if (empty($workorder['order_sent']))
			{
				$exporter_ordre->reset_transfer_xml();
				$exporter_ordre->create_order_receive_xml($param);
				$export_ok = $exporter_ordre->transfer($order_receive  = true);
			}
		}

		private function log_transfer($id)
		{
			$historylog	 = CreateObject('property.historylog', 'workorder');
			$historylog->add('RM', $id, "Ordre overført til agresso");
			$now		 = time();
			$this->db->query("UPDATE fm_workorder SET order_sent = {$now} WHERE id = {$id}");
		}

		private function alert_external($workorder_id, $tickets, $vendor_name, $end_date)
		{
			$historylog	 = CreateObject('property.historylog', 'workorder');
			$sotts		 = CreateObject('property.sotts');

			$send = CreateObject('phpgwapi.send');

			$recipients = array(
				//		'dag.boye.tellnes@no.issworld.com',
				'servicedesk@iss.no'
			);

			$serverSettings = Settings::getInstance()->get('server');

			$_to				 = implode(';', $recipients);
			$bcc				 = 'hc483@bergen.kommune.no';
			$cc					 = '';
			$coordinator_email	 = 'IkkeSvar@bergen.kommune.no';
			$coordinator_name	 = $serverSettings['site_title'];

			foreach ($tickets as $ticket_id)
			{
				$ticket = $sotts->read_single($ticket_id);

				if (empty($ticket['external_ticket_id']))
				{
					continue;
				}

				$subject = "WO ID: {$ticket['external_ticket_id']} er bestilt fra {$vendor_name}";
				$message = "Forventet sluttdato: {$end_date}\nVårt nr er {$ticket_id}";

				try
				{
					$rcpt = $send->msg('email', $_to, $subject, $message, '', $cc, $bcc, $coordinator_email, $coordinator_name, 'txt', '', array());
					Cache::message_set(lang('%1 is notified', $_to), 'message');
					$historylog->add('RM', $workorder_id, "ISS er varslet om bestilling med referanse til deres avviksmelding");
				}
				catch (Exception $exc)
				{
					Cache::message_set($exc->getMessage(), 'error');
				}
			}
		}
	}
}

if (!empty($transfer_action) && ($transfer_action == 'workorder' || $transfer_action == 'resend_workorder'))
{
	$exporter_ordre = new lag_agresso_ordre_fra_workorder();
	try
	{
		$exporter_ordre->transfer($workorder, $transfer_action);
	}
	catch (Exception $exc)
	{
		Cache::message_set($exc->getMessage(), 'error');
	}
}
