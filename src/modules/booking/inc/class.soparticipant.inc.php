<?php
	phpgw::import_class('booking.socommon');

	class booking_soparticipant extends booking_socommon
	{

		function __construct()
		{
			parent::__construct('bb_participant', array(
				'id' => array('type' => 'int'),
				'reservation_type' => array('type' => 'string', 'required' => True, 'nullable' => False),
				'reservation_id' => array('type' => 'int', 'required' => True, 'nullable' => False),
				'from_' => array('type' => 'timestamp', 'required' => False,'read_callback' => 'modify_by_timezone'),
				'to_' => array('type' => 'timestamp', 'required' => False, 'read_callback' => 'modify_by_timezone'),
				'phone' => array('type' => 'string', 'query' => true, 'required' => true),
				'email' => array('type' => 'string', 'required' => False,'sf_validator' => createObject('booking.sfValidatorEmail', array(), array(
						'invalid' => '%field% is invalid'))),
				'quantity' => array('type' => 'int', 'query' => false, 'required' => true),
				)
			);
			$this->account = $this->userSettings['account_id'];
		}

		protected function preValidate( &$entity )
		{
			if ($entity['register_type'] == 'register_in')
			{
				$entity['from_'] = date("Y-m-d H:i:s");
			}
			if ($entity['register_type'] == 'register_out')
			{
				if (empty($entity['from_']))
				{
					$entity['from_'] = date("Y-m-d H:i:s");
				}
				$entity['to_'] = date("Y-m-d H:i:s");
			}
		}

		protected function doValidate( $entity, booking_errorstack $errors )
		{

			$filter = "";

			if ($entity['register_type'] == 'register_pre')
			{
				$filter = " AND from_ IS NULL AND to_ IS NULL";
			}

			if ($entity['register_type'] == 'register_in')
			{
				$filter = " AND from_ IS NOT NULL AND to_ IS NULL";
			}

			if ($entity['register_type'] == 'register_out')
			{
	//			$filter = " AND from_ IS NOT NULL AND to_ IS NOT NULL";
				$filter = " AND 1=2";
			}

			$sql = "SELECT id"
				. " FROM bb_participant"
				. " WHERE reservation_type='{$entity['reservation_type']}'"
				. " AND reservation_id=" . (int) $entity['reservation_id']
				. " AND phone='{$entity['phone']}' {$filter}";

			$this->db->query($sql, __LINE__, __FILE__);
			if ($this->db->next_record())
			{
				$errors['phone'] = lang('Phone is already registered');
			}
		}

		function get_previous_registration($reservation_type, $reservation_id, $phone, $register_type)
		{
			$sql = "SELECT id, email, from_, to_, quantity"
				. " FROM bb_participant"
				. " WHERE reservation_type='{$reservation_type}'"
				. " AND reservation_id=" . (int) $reservation_id
				. " AND phone LIKE '%{$phone}'"
				. " ORDER BY id DESC";
			$this->db->query($sql, __LINE__, __FILE__);
			$this->db->next_record();

			$id			 = $this->db->f('id');
			$to_		 = $this->db->f('to_');
			$from_		 = $this->db->f('from_');
			$quantity	 = $this->db->f('quantity');

			/**
			 * Re-entry to the event
			 */
			if(($register_type == 'register_pre' || $register_type == 'register_in') && $to_)
			{
				$id = null;
				$to_ = null;
				$quantity = null;

				if($register_type == 'register_pre')
				{
					$from_ = null;
				}
			}
			
			/**
			 * Already out
			 */
			if($register_type == 'register_out' && $to_)
			{
				$id = null;
			}

			return array(
				'id'		 => $id,
				'email'		 => $this->db->f('email'),
				'quantity'	 => $quantity,
				'from_'		 => $from_,
				'to_'		 => $to_
			);
		}

		function get_number_of_participants($reservation_type, $reservation_id, $registered_in = false)
		{

			$filtermethod = '';
			if($registered_in)
			{
				$filtermethod .= 'AND from_ IS NOT NULL AND to_ IS NULL';

			}
			else
			{
				$filtermethod .= 'AND to_ IS NULL';
			}

			$sql = "SELECT sum(quantity) as cnt"
				. " FROM bb_participant"
				. " WHERE reservation_type='{$reservation_type}'"
				. " AND reservation_id=" . (int) $reservation_id
				. " {$filtermethod}";
			$this->db->query($sql, __LINE__, __FILE__);
			$this->db->next_record();
			return (int)$this->db->f('cnt');
		}
		
		/**
		 * The reservations that can come from an application, and the table the link
		 * (application_id) is read from. The table name is never user input.
		 */
		protected static $applicant_tables = array(
			'allocation' => 'bb_allocation',
			'booking' => 'bb_booking'
		);

		/**
		 * The phone numbers an SMS sent from a reservation's page reaches: the people who registered
		 * as participants and, for an allocation or a booking that comes from an application, the applicant.
		 * Only resolves the numbers; nothing is sent.
		 *
		 * The participants are exactly the numbers the page has always used. The applicant's number
		 * is added when it is not already one of them: same digits, with or without the country code.
		 *
		 * When the reservation comes from an application but the applicant has no usable number,
		 * applicant_skipped says why, so the caller can tell the officer who could not be contacted:
		 * 'no_number' (nothing entered), 'not_phone_shaped' (see usable_phone_sql) or 'all_zeros'.
		 * It is null when there is no application, and when the applicant is reached (also as a participant).
		 *
		 * @param string $reservation_type
		 * @param int $reservation_id
		 * @return array array(
		 *	'recipients' => list of array('phone' => string, 'source' => 'participant'|'application'|'account'),
		 *	'applicant_skipped' => null|'no_number'|'not_phone_shaped'|'all_zeros'
		 * )
		 */
		public function get_sms_recipients( $reservation_type, $reservation_id )
		{
			$participants = $this->read(array(
				'results' => -1,
				'filters' => array(
					'reservation_id' => $reservation_id,
					'reservation_type' => $reservation_type
				)
			));

			$participant_phones = array();
			foreach ((array)$participants['results'] as $participant)
			{
				$participant_phones[] = $participant['phone'];
			}

			$recipients = array();
			$known = array();
			foreach (array_unique($participant_phones) as $phone)
			{
				$recipients[] = array('phone' => $phone, 'source' => 'participant');
				$known[] = self::phone_key($phone);
			}

			$applicant_skipped = null;
			if (isset(self::$applicant_tables[$reservation_type]))
			{
				$applicant = $this->get_applicant_phone($reservation_type, $reservation_id);
				if (isset($applicant['skipped']))
				{
					$applicant_skipped = $applicant['skipped'];
				}
				elseif ($applicant && !in_array(self::phone_key($applicant['phone']), $known, true))
				{
					$recipients[] = $applicant;
				}
			}

			return array('recipients' => $recipients, 'applicant_skipped' => $applicant_skipped);
		}

		/**
		 * The applicant's phone for an allocation or booking that comes from an application: the number
		 * entered on the application, else the one on the applicant's account (bb_user, found by customer_ssn).
		 * A value that is not a usable phone number (see usable_phone_sql) counts as empty.
		 *
		 * @param string $reservation_type a key of $applicant_tables
		 * @param int $reservation_id
		 * @return array|null array('phone' => string, 'source' => 'application'|'account'), or
		 *	array('skipped' => 'no_number'|'not_phone_shaped'|'all_zeros') when the reservation has an
		 *	application but neither source has a usable number, or null when it has no application
		 */
		protected function get_applicant_phone( $reservation_type, $reservation_id )
		{
			$table = self::$applicant_tables[$reservation_type];
			$application_usable = self::usable_phone_sql('a.contact_phone');
			$account_usable = self::usable_phone_sql('u.phone');
			$account_entered = "u.customer_ssn = a.customer_ssn AND btrim(coalesce(u.phone, '')) <> ''";
			// Why nothing is usable: the application's own entry if there is one, else the account's. Worked out
			// here so the rejected text, which is free text from the citizen, never leaves the database.
			$skipped = "CASE WHEN btrim(coalesce(a.contact_phone, '')) <> '' THEN " . self::skip_reason_sql('a.contact_phone')
				. " ELSE (SELECT " . self::skip_reason_sql('u.phone') . " FROM bb_user u"
				. " WHERE a.customer_ssn <> '' AND {$account_entered} ORDER BY u.id LIMIT 1) END";
			$sql = "SELECT CASE WHEN {$application_usable} THEN btrim(a.contact_phone) END AS application_phone,"
				. " (SELECT btrim(u.phone) FROM bb_user u"
				. " WHERE a.customer_ssn <> '' AND u.customer_ssn = a.customer_ssn AND {$account_usable}"
				. " ORDER BY u.id LIMIT 1) AS account_phone,"
				. " {$skipped} AS skipped"
				. " FROM {$table} r JOIN bb_application a ON a.id = r.application_id"
				. " WHERE r.id = " . (int) $reservation_id;
			$this->db->query($sql, __LINE__, __FILE__);
			if (!$this->db->next_record())
			{
				return null;
			}

			$application_phone = (string) $this->db->f('application_phone');
			$account_phone = (string) $this->db->f('account_phone');

			if ($application_phone !== '')
			{
				return array('phone' => $application_phone, 'source' => 'application');
			}
			if ($account_phone !== '')
			{
				return array('phone' => $account_phone, 'source' => 'account');
			}

			$reason = (string) $this->db->f('skipped');

			return array('skipped' => $reason !== '' ? $reason : 'no_number');
		}

		/**
		 * SQL condition for "this column holds a phone number we may send to". The applicant's number is
		 * free text from the citizen, so only 0-9, space, + - / ( ) with at least one digit other than 0
		 * passes: anything else (a quote, letters, ';') and an all-zero placeholder count as no number.
		 *
		 * @param string $column a column of our own SQL, never user input
		 * @return string
		 */
		protected static function usable_phone_sql( $column )
		{
			return "({$column} ~ '^[0-9 +/()-]+$' AND {$column} ~ '[1-9]')";
		}

		/**
		 * SQL expression: why a NON-BLANK value fails usable_phone_sql. Only meaningful for a value that does fail it.
		 *
		 * @param string $column a column of our own SQL, never user input
		 * @return string
		 */
		protected static function skip_reason_sql( $column )
		{
			return "CASE WHEN {$column} !~ '^[0-9 +/()-]+$' THEN 'not_phone_shaped' ELSE 'all_zeros' END";
		}

		/**
		 * What makes two phone numbers the same number: the digits, without a leading 00 or the
		 * Norwegian country code (+47 91234567, 0047 91234567 and 91234567 are one number).
		 *
		 * @param string $phone
		 * @return string
		 */
		protected static function phone_key( $phone )
		{
			$digits = preg_replace('/\D+/', '', $phone);
			if (strpos($digits, '00') === 0)
			{
				$digits = substr($digits, 2);
			}
			if (strlen($digits) == 10 && strpos($digits, '47') === 0)
			{
				$digits = substr($digits, 2);
			}

			return $digits;
		}

		function delete_from_completed($reservation_type, $age_days)
		{
			$interval = (int) $age_days;
			
			switch ($reservation_type)
			{
				case 'event':
				case 'booking':
				case 'allocation':
					$sql = "DELETE FROM bb_participant WHERE reservation_type = '{$reservation_type}' AND reservation_id IN"
					. " (SELECT id FROM bb_{$reservation_type}"
					. " WHERE to_ IS NOT NULL"
					. " AND to_ <  (now() - '{$interval} days'::interval))";

					$this->db->query($sql, __LINE__, __FILE__);

					break;

				default:
					break;
			}
			
		}

	}