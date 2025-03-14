<?php

use App\modules\phpgwapi\services\Cache;
use App\modules\bookingfrontend\helpers\UserHelper;

phpgw::import_class('booking.bocommon_authorized');

class booking_bouser extends booking_bocommon_authorized
{

	public $public_functions = array(
		'get_applications'	 => true,
		'get_invoices'		 => true
	);

	const ROLE_ADMIN = 'user_admin';

	function __construct()
	{
		parent::__construct();
		$this->so = CreateObject('booking.souser');
	}

	public function anonymisation($id)
	{
		return $this->so->anonymisation($id);
	}

	public function get_applications($ssn = null)
	{
		if (!$ssn)
		{
			$ssn = Sanitizer::get_var('ssn', 'GET');
		}

		return $this->so->get_applications($ssn);
	}

	public function get_invoices($ssn = null)
	{
		if (!$ssn)
		{
			$ssn = Sanitizer::get_var('ssn', 'GET');
		}
		return $this->so->get_invoices($ssn);
	}

	/**
	 * @see booking_bocommon_authorized
	 */
	protected function get_subject_roles($for_object = null, $initial_roles = array())
	{
		if ($this->current_app() == 'bookingfrontend')
		{

			$bouser = new UserHelper();

			$external_login_info = $bouser->validate_ssn_login(array(
				'menuaction' => 'bookingfrontend.uiuser.edit'
			));

			if (!empty($external_login_info['ssn']) && $external_login_info['ssn'] == $for_object['customer_ssn'])
			{
				$initial_roles[] = array('role' => self::ROLE_ADMIN);
			}
		}

		return parent::get_subject_roles($for_object, $initial_roles);
	}

	/**
	 * @see bocommon_authorized
	 */
	protected function get_object_role_permissions($forObject, $defaultPermissions)
	{
		if ($this->current_app() == 'booking')
		{
			$defaultPermissions[booking_sopermission::ROLE_DEFAULT] = array(
				'read' => true,
				'delete' => true,
				'write' => true,
				'create' => true,
			);
		}

		if ($this->current_app() == 'bookingfrontend')
		{
			$defaultPermissions[self::ROLE_ADMIN] = array(
				'write' => array_fill_keys(array(
					'name', 'homepage', 'phone', 'email', 'description',
					'street', 'zip_code', 'district', 'city', 'active', 'user_number',
					'contacts'
				), true),
				'create' =>  true,
			);
		}

		return $defaultPermissions;
	}

	/**
	 * @see bocommon_authorized
	 */
	protected function get_collection_role_permissions($defaultPermissions)
	{
		if ($this->current_app() == 'booking')
		{
			$defaultPermissions[booking_sopermission::ROLE_DEFAULT]['create'] = true;
			$defaultPermissions[booking_sopermission::ROLE_DEFAULT]['write'] = true;
		}

		return $defaultPermissions;
	}

	public function get_permissions(array $entity)
	{
		return parent::get_permissions($entity);
	}

	/**
	 * Removes any extra contacts from entity if such exists (only two contacts allowed).
	 */
	protected function trim_contacts(&$entity)
	{
		if (isset($entity['contacts']) && is_array($entity['contacts']) && count($entity['contacts']) > 2)
		{
			$entity['contacts'] = array($entity['contacts'][0], $entity['contacts'][1]);
		}

		return $entity;
	}

	function add($entity)
	{
		return parent::add($this->trim_contacts($entity));
	}

	function update($entity)
	{
		return parent::update($this->trim_contacts($entity));
	}

	/**
	 * Used?????
	 * @see souser
	 */
	function find_building_users($building_id, $split = false, $activities = array())
	{
		return $this->so->find_building_users($building_id, $this->build_default_read_params(), $split, $activities);
	}


	function update_user_address()
	{
		set_time_limit(400);
		$configfrontend	= CreateObject('phpgwapi.config', 'bookingfrontend')->read();
		$get_name_from_external = isset($configfrontend['get_name_from_external']) && $configfrontend['get_name_from_external'] ? $configfrontend['get_name_from_external'] : '';

		$file = PHPGW_SERVER_ROOT . "/bookingfrontend/inc/custom/default/{$get_name_from_external}";

		if (is_file($file))
		{
			require_once $file;
			$external_user = new bookingfrontend_external_user_name();
		}
		else
		{
			throw new Exception('Kopling til folkeregister er ikke konfigurert');
		}

		$get_persons_only = true;
		$customers	 = $this->so->get_customer_list(true);

		$i = 0;
		foreach ($customers as  &$customer)
		{
			$user_id = $this->so->get_user_id($customer['customer_ssn']);
			$user	 = $this->read_single($user_id);

			$data = array('ssn' => $customer['customer_ssn']);
			try
			{
				$external_user->get_name_from_external_service($data);
			}
			catch (Exception $exc)
			{
			}

			$user['name'] = empty($data['middle_name']) ? "{$data['last_name']} {$data['first_name']}" :  "{$data['last_name']} {$data['first_name']} {$data['middle_name']}";

			$user['street'] = $data['street'];
			$user['zip_code'] = $data['zip_code'];
			$user['city'] = $data['city'];
			if (!$this->validate($user))
			{
				$this->update($user);
				$i++;
			}
			else
			{
				Cache::message_set("{$customer['name']} validerer ikke, mangler komplett datasett", 'error');
			}
		}

		$receipt = array();
		return $receipt['message'][] = array('msg' => lang('updated %1 users', $i));
	}

	function collect_users()
	{
		return $this->so->collect_users();
	}

	/**
	 *
	 * @param bool $get_persons_only - skip organizations
	 * @param bool $last_billing - only those billed last time
	 * @return array
	 */
	public function get_customer_list($get_persons_only = false, $last_billing = false)
	{
		$config		 = CreateObject('phpgwapi.config', 'booking')->read();
		$customers	 = $this->so->get_customer_list($get_persons_only, $last_billing);

		if ($config['customer_list_format'] == 'AGRESSO')
		{
			$agresso_cs15 = new agresso_cs15($config);
			return $agresso_cs15->get_customer_list($customers);
		}
		else if ($config['customer_list_format'] == 'FACTUM')
		{
			$factum_customer = new factum_customer($config);
			return $factum_customer->get_customer_list($customers);
		}
	}
}

class factum_customer
{
	private $client, $apar_gr_id, $pay_method;

	public function __construct($config)
	{
		$this->client = !empty($config['voucher_client']) ? $config['voucher_client'] : 'BY';
		$this->apar_gr_id = !empty($config['apar_gr_id']) ? $config['apar_gr_id'] : '10';
		$this->pay_method = !empty($config['pay_method']) ? $config['pay_method'] : 'IP'; //'BG';//'IP'
	}

	public function get_customer_list($customers)
	{
		$memory = xmlwriter_open_memory();
		xmlwriter_set_indent($memory, true);
		xmlwriter_start_document($memory, '1.0', 'ISO-8859-1');
		xmlwriter_start_element($memory, 'BkPffKunder');

		foreach ($customers as $entry) // Runs through all parties
		{
			if (empty($entry['zip_code']))
			{
				Cache::message_set("{$entry['name']} mangler PostNr", 'error');
				continue;
			}

			if ($entry['customer_internal'] == 1)
			{
				continue;
			}

			$country_code = 'NO';
			// TODO: Which standard for the country codes does Agresso follow?
			if ($country_code != 'NO' && $country_code != 'SV' && $country_code != 'IS') // Shouldn't get postal place for Norway, Sweden and Iceland
			{
				/**
				 * Not implemented
				 */
				//$this->get_postal_place();
			}

			xmlwriter_start_element($memory, 'BkPffKunde');

			$identifier = $entry['organization_number'] ? $entry['organization_number'] : $entry['customer_ssn'];
			$customer_type = $entry['organization_number'] ? 'O' : 'P';

			if ($customer_type == 'O')
			{
				xmlwriter_write_element($memory, 'Foretaksnummer', $identifier);
				xmlwriter_write_element($memory, 'Fagsystemkundeid', $entry['organization_number']);
			}
			else
			{
				xmlwriter_write_element($memory, 'Fodselsnummer', $identifier);
				//					xmlwriter_write_element($memory, 'Fornavn', $entry['name']);
				xmlwriter_write_element($memory, 'Fagsystemkundeid', $entry['customer_ssn']);
			}

			$co_address = !empty($entry['co_address']) ? $entry['co_address'] : '';
			xmlwriter_write_element($memory, 'Navn', $entry['name']);
			xmlwriter_write_element($memory, 'AdresseLinje1', $co_address);
			xmlwriter_write_element($memory, 'AdresseLinje2', $entry['street']);
			xmlwriter_write_element($memory, 'Adressetype', 'O'); //Offentlig = O,Midlertidig = M, OffentligReg = R, Utenlands = U, UtenlandsMidlertidig = X
			xmlwriter_write_element($memory, 'Poststed', $entry['city']);
			xmlwriter_write_element($memory, 'TelefonMobil', $entry['phone']);
			xmlwriter_write_element($memory, 'Epost', $entry['email']);
			xmlwriter_write_element($memory, 'Landkode', $country_code);
			xmlwriter_write_element($memory, 'PostNr', $entry['zip_code']);
			xmlwriter_write_element($memory, 'Kundekategori', $customer_type);
			xmlwriter_write_element($memory, 'SystemIdEndr', $this->client); //??

			xmlwriter_end_element($memory);
		}

		xmlwriter_end_element($memory);
		$xml = xmlwriter_output_memory($memory, true);

		return $xml;
	}
}

class agresso_cs15
{
	private $client, $apar_gr_id, $pay_method, $first_name_first;


	public function __construct($config)
	{
		$this->client = !empty($config['voucher_client']) ? $config['voucher_client'] : 'BY';
		$this->apar_gr_id = !empty($config['apar_gr_id']) ? $config['apar_gr_id'] : '10';
		$this->pay_method = !empty($config['pay_method']) ? $config['pay_method'] : 'IP'; //'BG';//'IP'
		$this->first_name_first = !empty($config['first_name_first']) ? true : false;
	}


	public function get_customer_list($customers)
	{
		$lines = array();
		$counter = 1; // set to 1 initially to satisfy agresso requirements
		foreach ($customers as $entry) // Runs through all parties
		{
			if ($entry['customer_internal'])
			{
				Cache::message_set("{$entry['name']} er intern kunde", 'message');
				continue;
			}

			if (empty($entry['zip_code']))
			{
				Cache::message_set("{$entry['name']} mangler PostNr", 'error');
				continue;
			}

			$country_code = 'NO';
			$place = '';
			// TODO: Which standard for the country codes does Agresso follow?
			if ($country_code != 'NO' && $country_code != 'SV' && $country_code != 'IS') // Shouldn't get postal place for Norway, Sweden and Iceland
			{
				/**
				 * Not implemented
				 */
				//$this->get_postal_place();
			}


			$identifier = $entry['organization_number'] ? $entry['organization_number'] : $entry['customer_ssn'];
			$customer_type = $entry['organization_number'] ? 'C' : 'P';
			/**
			 *	C - Company
			 *	P - Private
			 *	B - Both
			 */

			if ($this->first_name_first && $customer_type == 'P')
			{
				// I have $entry['name'] like this: "Nordmann Ole Stein" and I want to get "Ole Stein Nordmann"
				$parts = explode(' ', $entry['name']);
				$last_name = array_shift($parts);
				$first_name = implode(' ', $parts);
				$entry['name'] = "{$first_name} {$last_name}";
			}

			$lines[] = $this->get_line_agresso_cs15($entry['name'], $identifier, $entry['street'], $entry['city'], $country_code, $place, $entry['phone'], $entry['zip_code'], $counter, $customer_type);
			$counter++;
		}

		$contents = implode("\n", $lines);

		return $contents;
	}
	/**
	 * Builds one single line of the Agresso file.

	 * @return string
	 */
	protected function get_line_agresso_cs15($name, $identifier, $address1, $address2, $country_code, $postal_place, $phone, $postal_code, $counter, $customer_type)
	{
		// muligens format 52
		// XXX: Which charsets do Agresso accept/expect? Do we need to something regarding padding and UTF-8?
		$line = '1'  //  1	full_record
			. 'I'  //  2	change_status
			. sprintf("%-2s", $this->apar_gr_id)  //  3	apar_gr_id, Gyldig reskontrogruppe i Agresso.Hvis blank benyttes parameterverdi.
			. sprintf("%9s", $counter)   //  4	apar_id, sequence number, right justified
			. sprintf("%9s", '')  //  5	apar_id_ref
			. sprintf("%-50.50s", iconv("UTF-8", "ISO-8859-1//TRANSLIT", $name)) //  6	apar_name
			. 'R'  //  7	apar_type (key):P - Supplier (Payable)R - Customer (Receivable
			. sprintf("%-35s", '') //  8	bank_account
			. sprintf("%-4s", '') //  9	bonus_gr
			. sprintf("%3s", '')  // 10	cash_delay
			. sprintf("%-13s", '') // 11	clearing_code
			. sprintf("%-2s", $this->client) // 12	client
			. sprintf("%1s", '')  // 13	collect_flag
			. sprintf("%-25.25s", $identifier) // 14	comp_reg_no
			. $customer_type  // 15	control
			. sprintf("%20s", '') // 16	credit_limit
			. 'NOK'  // 17	NOK
			. sprintf("%1s", '')  // 18	currency_set
			. sprintf("%-4s", '') // 19	disc_code
			. sprintf("%-15s", '') // 20	ext_apar_ref
			. sprintf("%-8s", '') // 21	factor_short
			. sprintf("%-35s", '') // 22	foreign_acc
			. sprintf("%-6s", '') // 23	int_rule_id
			. sprintf("%-12s", '') // 24	invoice_code
			. 'NO'  // 25	language
			. sprintf("%9s", '')  // 26	main_apar_id
			. sprintf("%-80s", '') // 27	message_text
			. sprintf("%3s", '')  // 28	pay_delay
			. sprintf("%-2s", $this->pay_method) // 29	pay_method
			. sprintf("%-13s", '') // 30	postal_acc
			. sprintf("%-1s", '') // 31	priority_no
			. sprintf("%-10s", '') // 32	short_name
			. 'N'  // 33	status
			. sprintf("%-11s", '') // 34	swift
			. sprintf("%-1s", '') // 35	tax_set
			. sprintf("%-2s", '') // 36	tax_system
			. sprintf("%-2s", '') // 37	terms_id
			. sprintf("%-1s", '') // 38	terms_set
			. sprintf("%-25s", '') // 39	vat_reg_no
			. sprintf("%-40.40s", iconv("UTF-8", "ISO-8859-1//TRANSLIT", $address1)) // 40	address1
			. sprintf("%-40.40s", iconv("UTF-8", "ISO-8859-1//TRANSLIT", $address2)) // 40	address2
			. sprintf("%-40.40s", '')   // 40	address3
			. sprintf("%-40.40s", '')   // 40	address4
			. '1'  // 41	address_type
			. sprintf("%-6s", '') // 42	agr_user_id
			. sprintf("%-255s", '') // 43	cc_name
			. sprintf("%-3.3s", $country_code) // 44	country_code
			. sprintf("%-50s", '') // 45	description
			. sprintf("%-40.40s", iconv("UTF-8", "ISO-8859-1//TRANSLIT", $postal_place)) // 46	place
			. sprintf("%-40s", '') // 47	province
			. sprintf("%-35.35s", iconv("utf-8", "ISO-8859-1//TRANSLIT", $phone))  // 48	telephone_1
			. sprintf("%-35s", '') // 49	telephone_2
			. sprintf("%-35s", '') // 50	telephone_3
			. sprintf("%-35s", '') // 51	telephone_4
			. sprintf("%-35s", '') // 52	telephone_5
			. sprintf("%-35s", '') // 53	telephone_6
			. sprintf("%-35s", '') // 54	telephone_7
			. sprintf("%-255s", '') // 55	to_name
			. sprintf("%-15.15s", $postal_code) // 56	zip_code
			. sprintf("%-50s", '') // 57	e_mail
			. sprintf("%-35s", '') // 58	pos_title
			. sprintf("%-4s", '') // 59	pay_temp_id
			. sprintf("%-25s", '') // 60	reference_1
		;

		return str_replace(array("\n", "\r"), '', $line);
	}
}
