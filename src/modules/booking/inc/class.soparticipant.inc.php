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
		 * The phone numbers an SMS sent from a reservation's page reaches: the people who registered
		 * as participants and, for an allocation that comes from an application, the applicant.
		 * Only resolves the numbers; nothing is sent.
		 *
		 * The participants are exactly the numbers the page has always used. The applicant's number
		 * is added when it is not already one of them: same digits, with or without the country code.
		 *
		 * @param string $reservation_type
		 * @param int $reservation_id
		 * @return array list of array('phone' => string, 'source' => 'participant'|'application'|'account')
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

			if ($reservation_type == 'allocation')
			{
				$applicant = $this->get_applicant_phone($reservation_id);
				if ($applicant && !in_array(self::phone_key($applicant['phone']), $known))
				{
					$recipients[] = $applicant;
				}
			}

			return $recipients;
		}

		/**
		 * The applicant's phone for an allocation that comes from an application: the number entered
		 * on the application, else the one on the applicant's account (bb_user, found by customer_ssn).
		 * An entry without a digit counts as empty.
		 *
		 * @param int $allocation_id
		 * @return array|null array('phone' => string, 'source' => 'application'|'account'), or null
		 *	when the allocation has no application or neither source has a number
		 */
		protected function get_applicant_phone( $allocation_id )
		{
			$sql = "SELECT CASE WHEN a.contact_phone ~ '[0-9]' THEN btrim(a.contact_phone) END AS application_phone,"
				. " (SELECT btrim(u.phone) FROM bb_user u"
				. " WHERE a.customer_ssn <> '' AND u.customer_ssn = a.customer_ssn AND u.phone ~ '[0-9]'"
				. " ORDER BY u.id LIMIT 1) AS account_phone"
				. " FROM bb_allocation al JOIN bb_application a ON a.id = al.application_id"
				. " WHERE al.id = " . (int) $allocation_id;
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

			return null;
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