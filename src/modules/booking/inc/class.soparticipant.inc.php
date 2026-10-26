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
		 * The reservations that can come from an application, and where the application is read from: the
		 * reservation as "r", joined to its application as "a". A booking carries its own application_id, but
		 * only the citizen's way of making an interntildeling copies the allocation's application onto it, so a
		 * booking without one is read through its own allocation. The fragments are fixed text, never user input.
		 */
		protected static $applicant_sources = array(
			'allocation' => 'bb_allocation r JOIN bb_application a ON a.id = r.application_id',
			'booking' => 'bb_booking r LEFT JOIN bb_allocation al ON al.id = r.allocation_id'
				. ' JOIN bb_application a ON a.id = COALESCE(r.application_id, al.application_id)'
		);

		/**
		 * Who the SMS from an interntildeling (a booking) reaches besides its registered participants: the
		 * FIRST of these steps that has a usable number, in this order, and none of the others. 'creator' is
		 * the citizen who made it, 'applicant' the applicant of the tildeling it belongs to, 'group_contact'
		 * the contacts of its group. Take a step out to switch it off, or reorder them to change who is tried
		 * first; nothing else needs to change. An allocation always texts its applicant and an event only its
		 * participants, whatever this says.
		 */
		const SMS_BOOKING_CHAIN = array('creator', 'applicant', 'group_contact');

		/**
		 * The phone numbers an SMS sent from a reservation's page reaches. Only resolves the numbers;
		 * nothing is sent.
		 *
		 * The participants are exactly the numbers the page has always used. Besides them:
		 * - an allocation: the applicant of its application, when the number is not already a participant's
		 *   (same digits, with or without the country code);
		 * - a booking (interntildeling): the first step of SMS_BOOKING_CHAIN that has a usable number: the
		 *   citizen who made it (bb_booking.created_by_user_id), else the applicant of the application it carries
		 *   or of its allocation, else the contacts of the booking's group. One step, never two. A step whose
		 *   number equals a participant's is reached as that participant and does not send the SMS on to the
		 *   next step. A step whose send FAILS later does not either: only "no usable number" moves on.
		 *
		 * When the reservation comes from an application but the applicant has no usable number,
		 * applicant_skipped says why, so the caller can tell the officer who could not be contacted:
		 * 'no_number' (nothing entered), 'not_phone_shaped' (see usable_phone_sql) or 'all_zeros'.
		 * It is null when there is no application, when the applicant is reached (also as a participant), and
		 * for a booking when an earlier step was reached. creator_skipped says the same for a booking's
		 * creator: it is set only when a creator is recorded but has no usable number, not when no creator
		 * is recorded.
		 *
		 * @param string $reservation_type
		 * @param int $reservation_id
		 * @return array array(
		 *	'recipients' => list of array('phone' => string,
		 *		'source' => 'participant'|'application'|'account'|'creator'|'group_contact'),
		 *	'applicant_skipped' => null|'no_number'|'not_phone_shaped'|'all_zeros',
		 *	'creator_skipped' => null|'no_number'|'not_phone_shaped'|'all_zeros'
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

			$skipped = array('creator' => null, 'applicant' => null);
			foreach (self::sms_steps($reservation_type) as $step)
			{
				$found = $this->get_sms_step($step, $reservation_type, $reservation_id);
				if ($found['recipients'])
				{
					foreach ($found['recipients'] as $recipient)
					{
						if (!in_array(self::phone_key($recipient['phone']), $known, true))
						{
							$recipients[] = $recipient;
							$known[] = self::phone_key($recipient['phone']);
						}
					}

					break;
				}

				if (array_key_exists($step, $skipped))
				{
					$skipped[$step] = $found['skipped'];
				}
			}

			return array(
				'recipients' => $recipients,
				'applicant_skipped' => $skipped['applicant'],
				'creator_skipped' => $skipped['creator']
			);
		}

		/**
		 * The steps an SMS from a reservation tries after its participants, in order, until one has a usable number.
		 *
		 * @param string $reservation_type
		 * @return string[] 'creator', 'applicant' and 'group_contact', as SMS_BOOKING_CHAIN names them
		 */
		protected static function sms_steps( $reservation_type )
		{
			if ($reservation_type == 'booking')
			{
				return self::SMS_BOOKING_CHAIN;
			}

			return $reservation_type == 'allocation' ? array('applicant') : array();
		}

		/**
		 * What one step of the chain finds for a reservation.
		 *
		 * @param string $step a name from sms_steps()
		 * @param string $reservation_type
		 * @param int $reservation_id
		 * @return array array('recipients' => list of array('phone', 'source'), empty when the step has no
		 *	usable number, 'skipped' => null|'no_number'|'not_phone_shaped'|'all_zeros': why a creator or
		 *	applicant that exists could not be texted)
		 */
		protected function get_sms_step( $step, $reservation_type, $reservation_id )
		{
			switch ($step)
			{
				case 'creator':
					$found = $this->get_creator_phone($reservation_id);
					break;
				case 'applicant':
					$found = $this->get_applicant_phone($reservation_type, $reservation_id);
					break;
				case 'group_contact':
					$contacts = array();
					foreach ($this->get_group_contact_phones($reservation_id) as $phone)
					{
						$contacts[] = array('phone' => $phone, 'source' => 'group_contact');
					}

					return array('recipients' => $contacts, 'skipped' => null);
				default:
					throw new InvalidArgumentException("Unknown SMS step '{$step}'");
			}

			if (isset($found['phone']))
			{
				return array('recipients' => array($found), 'skipped' => null);
			}

			return array('recipients' => array(), 'skipped' => isset($found['skipped']) ? $found['skipped'] : null);
		}

		/**
		 * The phone of the citizen who made an interntildeling (bb_booking.created_by_user_id, a bb_user),
		 * by the applicant's rule: only a phone-shaped number with a digit other than 0 is usable.
		 *
		 * @param int $booking_id
		 * @return array|null array('phone' => string, 'source' => 'creator'), or
		 *	array('skipped' => 'no_number'|'not_phone_shaped'|'all_zeros') when a creator is recorded but has
		 *	no usable number (also a user who has been anonymized), or null when no citizen creator is
		 *	recorded: an older booking, one made by staff, or by a login that has no bb_user
		 */
		protected function get_creator_phone( $booking_id )
		{
			$usable = self::usable_phone_sql('u.phone');
			$sql = "SELECT CASE WHEN {$usable} THEN btrim(u.phone) END AS phone,"
				. " CASE WHEN btrim(coalesce(u.phone, '')) <> '' THEN " . self::skip_reason_sql('u.phone') . " END AS skipped"
				. " FROM bb_booking b JOIN bb_user u ON u.id = b.created_by_user_id"
				. " WHERE b.id = " . (int) $booking_id;
			$this->db->query($sql, __LINE__, __FILE__);
			if (!$this->db->next_record())
			{
				return null;
			}

			$phone = (string) $this->db->f('phone');
			if ($phone !== '')
			{
				return array('phone' => $phone, 'source' => 'creator');
			}

			$reason = (string) $this->db->f('skipped');

			return array('skipped' => $reason !== '' ? $reason : 'no_number');
		}

		/**
		 * The usable phone numbers of the contacts of the group an interntildeling was made for, oldest
		 * contact first. A contact without a usable number (see usable_phone_sql) is left out.
		 *
		 * @param int $booking_id
		 * @return string[]
		 */
		protected function get_group_contact_phones( $booking_id )
		{
			$sql = "SELECT btrim(gc.phone) AS phone"
				. " FROM bb_booking b JOIN bb_group_contact gc ON gc.group_id = b.group_id"
				. " WHERE b.id = " . (int) $booking_id . " AND " . self::usable_phone_sql('gc.phone')
				. " ORDER BY gc.id";
			$this->db->query($sql, __LINE__, __FILE__);

			$phones = array();
			while ($this->db->next_record())
			{
				$phones[] = (string) $this->db->f('phone');
			}

			return $phones;
		}

		/**
		 * The applicant's phone for an allocation or booking that comes from an application: the number
		 * entered on the application, else the one on the applicant's account (bb_user, found by customer_ssn).
		 * A value that is not a usable phone number (see usable_phone_sql) counts as empty.
		 *
		 * @param string $reservation_type a key of $applicant_sources
		 * @param int $reservation_id
		 * @return array|null array('phone' => string, 'source' => 'application'|'account'), or
		 *	array('skipped' => 'no_number'|'not_phone_shaped'|'all_zeros') when the reservation has an
		 *	application but neither source has a usable number, or null when it has no application
		 */
		protected function get_applicant_phone( $reservation_type, $reservation_id )
		{
			$source = self::$applicant_sources[$reservation_type];
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
				. " FROM {$source}"
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