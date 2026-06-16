<?php

use App\modules\phpgwapi\services\Migration\Migration;
use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\controllers\Accounts\phpgwapi_group;
use App\modules\phpgwapi\security\Acl;

/**
 * Seed migration porting the default data from setup/default_records.inc.php.
 *
 * Fresh installs skip default_records.inc.php for migration-based modules,
 * so the defaults that were not already covered by earlier migrations are
 * seeded here: activities, age groups, target audiences, the office/user
 * custom field, booking groups with ACLs, archive config sections and
 * async tasks. Existing installs already have this data — every step is
 * guarded and becomes a no-op.
 */
return new class extends Migration
{
	public string $description = 'Seed default records: activities, age groups, target audiences, groups/ACL, archive config and async tasks';

	public function up(): void
	{
		$this->seedActivities();
		$this->seedAgegroups();
		$this->seedTargetaudiences();
		$this->seedOfficeUserCustomField();
		$this->seedGroupsAndAcl();
		$this->seedArchiveConfig();
		$this->seedAsyncTasks();
	}

	private function seedActivities(): void
	{
		$activities = [
			// [id, parent_id, name, description]
			[1, null, 'Idrett', 'Idrett'],
			[2, null, 'Kultur', 'Kultur'],
			[3, null, 'Friluftsliv', 'Friluftsliv'],
			[4, 1, 'Badminton', 'Badminton'],
			[5, 1, 'Amerikansk fotball', 'Amerikansk fotball'],
			[6, 1, 'Annen idrett', 'Annen idrett'],
			[7, 1, 'Bandy - inne', 'Bandy - inne'],
			[8, 1, 'Basketball', 'Basketball'],
			[9, 1, 'Bedriftsidrett', 'Bedriftsidrett'],
			[10, 1, 'Boksing', 'Boksing'],
			[11, 1, 'Bordtennis', 'Bordtennis'],
			[12, 1, 'Bryting', 'Bryting'],
			[13, 1, 'Cheerleading', 'Cheerleading'],
			[14, 1, 'Dansing', 'Dansing'],
			[15, 1, 'Fotball', 'Fotball'],
			[16, 1, 'Friidrett', 'Friidrett'],
			[17, 1, 'Håndball', 'Håndball'],
			[18, 1, 'Innebandy', 'Innebandy'],
			[19, 1, 'Kampsport', 'Kampsport'],
			[20, 1, 'Klatring', 'Klatring'],
			[21, 1, 'Orientering', 'Orientering'],
			[22, 1, 'Skisport', 'Skisport'],
			[23, 1, 'Skyting', 'Skyting'],
			[24, 1, 'Stuping', 'Stuping'],
			[25, 1, 'Styrkeløfting', 'Styrkeløfting'],
			[26, 1, 'Svømming', 'Svømming'],
			[27, 1, 'Turn', 'Turn'],
			[28, 1, 'Vannsport', 'Vannsport'],
			[29, 1, 'Volleyball', 'Volleyball'],
			[30, 1, 'Vektløfting', 'Vektløfting'],
			[31, 2, 'Dans', 'Dans'],
			[32, 2, 'Teater', 'Teater'],
			[33, 2, 'Speidar', 'Speidar'],
			[34, 2, 'Musikk / Korps', 'Musikk / Korps'],
			[35, 2, 'Sosiale møteplassar', 'Sosiale møteplassar'],
			[36, 2, 'Musikk', 'Musikk'],
			[37, 2, 'Festivaler / Mønstringer', 'Festivaler / Mønstringer'],
			[38, 2, 'Humanitære organiasjoner', 'Humanitære organiasjoner'],
			[39, 2, 'Interesseorganiasjonar', 'Interesseorganiasjonar'],
			[40, 2, 'Kor', 'Kor'],
			[41, 2, 'Kulturlokaler', 'Kulturlokaler'],
			[42, 2, 'Kulturlokaler formidling og øving', 'Kulturlokaler formidling og øving'],
			[43, 2, 'Kulturlokaler øving og verksteder', 'Kulturlokaler øving og verksteder'],
			[44, 2, 'Kulturvern og sogelag', 'Kulturvern og sogelag'],
			[45, 2, 'Kunst, håndverk og media', 'Kunst, håndverk og media'],
			[46, 2, 'Meningheter og trossamfunn', 'Meningheter og trossamfunn'],
			[47, null, 'Annet', 'Annet'],
			[49, 47, 'Annet - Idrett møterom', 'Annet - Idrett møterom'],
			[50, 47, 'Annet - Kultur møterom', 'Annet - Kultur møterom'],
			[51, 47, 'Annet - Internt i kommunen', 'Annet - Internt i kommunen'],
			[52, 47, 'Annet - Offentlig arrangement', 'Annet - Offentlig arrangement'],
			[53, 47, 'Annet - Personlig markering', 'Annet - Personlig markering'],
			[54, 1, 'PU/HU', 'PU/HU'],
			[55, null, 'Skule', 'Skular i kommunen som bruker kommunale idrettsanlegg til idrett og arrangementer'],
			[56, 1, 'Idrettshall', 'Kommunal idrettshall'],
			[57, 1, 'Fleridrettslag', 'Idrettslag med flere idrettsgrener tilknytte laget.'],
			[58, 1, 'Fotballbane', 'Fotballbaner. Grus, kunstgras eller naturgrasbane'],
			[59, 1, 'Symjehall', 'Kommunal symjehall'],
			[60, 1, 'Gymsal', 'Gymsal tilknytta til skule'],
			[61, null, 'Undervisning/opplæring', 'Undervisning/opplæring'],
			[62, null, 'Kommersiell utleige', 'Utleie til kommersielle arrangementer i kommunale bygg og idrettsanlegg'],
			[63, 1, 'Sykling', 'Sykling'],
			[64, 2, 'Grendalag', 'Grendalag'],
		];

		foreach ($activities as [$id, $parent_id, $name, $description])
		{
			$parent = $parent_id === null ? 'NULL' : (int) $parent_id;
			$name = str_replace("'", "''", $name);
			$description = str_replace("'", "''", $description);
			$this->sql(
				"INSERT INTO bb_activity (id, parent_id, name, description, active)"
				. " SELECT {$id}, {$parent}, '{$name}', '{$description}', 1"
				. " WHERE NOT EXISTS (SELECT 1 FROM bb_activity WHERE id = {$id})"
			);
		}

		$this->sql("SELECT setval('seq_bb_activity', COALESCE((SELECT MAX(id)+1 FROM bb_activity), 1), false)");

		// The per-activity location registration (m...000122) and the
		// rescategory/activity linking (m...000168) ran before any activities
		// existed on a fresh install — replay them now that activities exist.
		// Both are idempotent and no-op on installs that already have them.
		$this->registerActivityLocations();
		$this->linkRescategoriesToActivities();
	}

	private function registerActivityLocations(): void
	{
		$location_obj = new Locations();

		$this->db->query("SELECT id, name FROM bb_activity WHERE parent_id IS NULL OR parent_id = 0 ORDER BY id", __LINE__, __FILE__);
		$activities = [];
		while ($this->db->next_record())
		{
			$activities[] = ['id' => $this->db->f('id'), 'name' => $this->db->f('name')];
		}

		foreach ($activities as $activity)
		{
			$location_obj->add(".application.{$activity['id']}", $activity['name'], 'booking', false, null, false, true);
			$location_obj->add(".resource.{$activity['id']}", $activity['name'], 'booking', false, null, false, true);
		}
	}

	private function linkRescategoriesToActivities(): void
	{
		foreach (['Lokale', 'Utstyr'] as $category)
		{
			$this->sql(
				"INSERT INTO bb_rescategory_activity (rescategory_id, activity_id)"
				. " SELECT rc.id, a.id FROM bb_rescategory rc"
				. " CROSS JOIN bb_activity a"
				. " WHERE rc.name = '{$category}' AND (a.parent_id IS NULL OR a.parent_id = 0)"
				. " AND NOT EXISTS ("
				. "   SELECT 1 FROM bb_rescategory_activity ra WHERE ra.rescategory_id = rc.id AND ra.activity_id = a.id"
				. " )"
			);
		}
	}

	private function seedAgegroups(): void
	{
		$agegroups = [
			// [id, name, sort, description, active, activity_id]
			[1, 'Småbarn 0-5 år', 0, '', 0, 1],
			[2, 'Born 0-12 år', 1, 'Barn fra 0 til og med 12 år', 1, 1],
			[3, 'Ungdom 13-19 år', 2, '', 1, 1],
			[4, 'Vaksen 20- 59 år', 4, '', 1, 1],
			[5, 'Pensjonister', 0, '', 0, 1],
			[6, 'Unge voksne 20- 25 år', 3, '', 0, 1],
			[7, 'Senior 60+år', 5, '', 1, 1],
			[8, 'Publikum', 6, 'Her legger du inn estimert publikum.', 1, 1],
			[9, 'Møtedeltakare', 8, '', 1, 1],
			[10, 'Småbarn 0-5 år', 0, '', 0, 2],
			[11, 'Born 0-12 år', 1, 'Barn fra 0 til og med 12 år', 1, 2],
			[12, 'Ungdom 13-19 år', 2, '', 1, 2],
			[13, 'Vaksen 20- 59 år', 4, '', 1, 2],
			[14, 'Pensjonister', 0, '', 0, 2],
			[15, 'Unge voksne 20- 25 år', 3, '', 0, 2],
			[16, 'Senior 60+år', 5, '', 1, 2],
			[17, 'Publikum', 6, 'Her legger du inn estimert publikum.', 1, 2],
			[18, 'Møtedeltakare', 8, '', 1, 2],
			[19, 'Småbarn 0-5 år', 0, '', 0, 3],
			[20, 'Born 0-12 år', 1, 'Barn fra 0 til og med 12 år', 1, 3],
			[21, 'Ungdom 13-19 år', 2, '', 1, 3],
			[22, 'Vaksen 20- 59 år', 4, '', 1, 3],
			[23, 'Pensjonister', 0, '', 0, 3],
			[24, 'Unge voksne 20- 25 år', 3, '', 0, 3],
			[25, 'Senior 60+år', 5, '', 1, 3],
			[26, 'Publikum', 6, 'Her legger du inn estimert publikum.', 1, 3],
			[27, 'Møtedeltakare', 8, '', 1, 3],
		];

		foreach ($agegroups as [$id, $name, $sort, $description, $active, $activity_id])
		{
			$name = str_replace("'", "''", $name);
			$description = str_replace("'", "''", $description);
			$this->sql(
				"INSERT INTO bb_agegroup (id, name, sort, description, active, activity_id)"
				. " SELECT {$id}, '{$name}', {$sort}, '{$description}', {$active}, {$activity_id}"
				. " WHERE NOT EXISTS (SELECT 1 FROM bb_agegroup WHERE id = {$id})"
			);
		}

		$this->sql("SELECT setval('seq_bb_agegroup', COALESCE((SELECT MAX(id)+1 FROM bb_agegroup), 1), false)");
	}

	private function seedTargetaudiences(): void
	{
		$audiences = [
			// [id, name, sort, description, active] — repeated for activity_id 1-3
			[1, 'Fleirkulturelle', 7, '', 0],
			[2, 'Born', 1, 'Barn fra 0 til og med 18 år', 1],
			[3, 'Ungdom', 2, 'Ungdom 13 til og med 19 år', 1],
			[4, 'Vaksen', 3, 'Vaksne mellom 20 - 59 år', 1],
			[5, 'Utviklingshemma', 5, '', 0],
			[6, 'Senior', 4, 'Senior fra 60 år', 1],
			[7, 'Funksjonshemma', 6, 'Funksjonhemma', 1],
			[8, 'Amatørkultur', 9, '', 0],
			[9, 'Offentleg arrangement', 10, 'Arrangement i regi av det offentlege', 0],
			[10, 'Profesjonell kultur', 8, '', 0],
			[11, 'Toppidrett', 7, 'Idrett på topp nivå i Norge', 0],
			[12, 'Publikum', 12, 'Publikum til stades', 1],
			[13, 'Private arrangement', 11, 'Private arrangement', 1],
			[14, 'Møte', 9, 'Møte i lokale', 1],
		];

		$id = 0;
		foreach ([1, 2, 3] as $activity_id)
		{
			foreach ($audiences as [, $name, $sort, $description, $active])
			{
				$id++;
				$name = str_replace("'", "''", $name);
				$description = str_replace("'", "''", $description);
				$this->sql(
					"INSERT INTO bb_targetaudience (id, name, sort, description, active, activity_id)"
					. " SELECT {$id}, '{$name}', {$sort}, '{$description}', {$active}, {$activity_id}"
					. " WHERE NOT EXISTS (SELECT 1 FROM bb_targetaudience WHERE id = {$id})"
				);
			}
		}

		$this->sql("SELECT setval('seq_bb_targetaudience', COALESCE((SELECT MAX(id)+1 FROM bb_targetaudience), 1), false)");
	}

	private function seedOfficeUserCustomField(): void
	{
		// CustomFields::add() is idempotent — returns early if the attribute exists
		$custom_fields = \CreateObject('phpgwapi.custom_fields');
		$custom_fields->add([
			'appname' => 'booking',
			'location' => '.office.user',
			'column_name' => 'account_id',
			'input_text' => 'User',
			'statustext' => 'System user',
			'search' => true,
			'list' => true,
			'column_info' => [
				'type' => 'user',
				'nullable' => 'False',
				'custom' => 1,
			],
		], 'bb_office_user');
	}

	private function seedGroupsAndAcl(): void
	{
		$accounts_obj = new Accounts();
		$aclobj = Acl::getInstance();
		$aclobj->enable_inheritance = true;

		$modules = ['booking', 'manual', 'preferences', 'property'];

		if (!$accounts_obj->exists('booking_group'))
		{
			$account = new phpgwapi_group();
			$account->lid = 'booking_group';
			$account->firstname = 'Booking';
			$account->lastname = 'Group';
			$booking_group = $accounts_obj->create($account, [], [], $modules);

			$aclobj->set_account_id($booking_group, true);
			$aclobj->add('booking', '.office', 7);
			$aclobj->add('booking', 'run', 1);
			$aclobj->add('property', '.', 1);
			$aclobj->add('property', 'run', 1);
			$aclobj->add('preferences', 'changepassword', 1);
			$aclobj->add('preferences', '.', 1);
			$aclobj->add('preferences', 'run', 1);
			$aclobj->save_repository();
		}

		if (!$accounts_obj->exists('booking_admin'))
		{
			$account = new phpgwapi_group();
			$account->lid = 'booking_admin';
			$account->firstname = 'Booking Admin';
			$account->lastname = 'Group';
			$booking_admin = $accounts_obj->create($account, [], [], $modules);

			$aclobj->set_account_id($booking_admin, true);
			$aclobj->add('booking', 'run', 1);
			$aclobj->add('booking', 'admin', 15);
			$aclobj->add('booking', '.office', 15);
			$aclobj->add('property', '.admin', 15);
			$aclobj->add('property', 'run', 1);
			$aclobj->add('property', '.admin_booking', 1);
			$aclobj->add('property', '.location', 15);
			$aclobj->add('property', '.owner', 15);
			$aclobj->add('preferences', 'changepassword', 1);
			$aclobj->add('preferences', '.', 1);
			$aclobj->add('preferences', 'run', 1);
			$aclobj->save_repository();
		}
	}

	private function seedArchiveConfig(): void
	{
		// add_section()/add_attrib() are idempotent — they reuse existing rows.
		// The payment/Vipps and Outlook sections are covered by earlier migrations.
		$location_obj = new Locations();
		$custom_config = \CreateObject('admin.soconfig', $location_obj->get_id('booking', 'run'));

		$section_common = $custom_config->add_section([
			'name' => 'common_archive',
			'descr' => 'common archive config',
		]);
		$custom_config->add_attrib([
			'section_id' => $section_common['section_id'],
			'input_type' => 'listbox',
			'name' => 'method',
			'descr' => 'Export / import method',
			'choice' => ['public360', 'gi_arkiv'],
		]);

		$section_public360 = $custom_config->add_section([
			'name' => 'public360',
			'descr' => 'public360 archive config',
		]);
		foreach ([
			['password', 'authkey', 'authkey'],
			['text', 'webservicehost', 'webservicehost'],
		] as [$input_type, $name, $descr])
		{
			$custom_config->add_attrib([
				'section_id' => $section_public360['section_id'],
				'input_type' => $input_type,
				'name' => $name,
				'descr' => $descr,
				'value' => '',
			]);
		}
		$custom_config->add_attrib([
			'section_id' => $section_public360['section_id'],
			'input_type' => 'listbox',
			'name' => 'debug',
			'descr' => 'debug',
			'choice' => [1],
		]);

		$section_gi_arkiv = $custom_config->add_section([
			'name' => 'gi_arkiv',
			'descr' => 'Geointegrasjon arkiv',
		]);
		foreach ([
			['text', 'webservicehost'],
			['text', 'username'],
			['password', 'password'],
			['text', 'journalenhet'],
			['text', 'arkivnoekkel'],
			['text', 'arkivnoekkel_text'],
			['text', 'fagsystem'],
			['text', 'arkivdel'],
			['text', 'sakspart_rolle'],
			['text', 'klientnavn'],
			['text', 'klientversjon'],
			['text', 'referanseoppsett'],
		] as [$input_type, $name])
		{
			$custom_config->add_attrib([
				'section_id' => $section_gi_arkiv['section_id'],
				'input_type' => $input_type,
				'name' => $name,
				'descr' => $name,
				'value' => '',
			]);
		}
		$custom_config->add_attrib([
			'section_id' => $section_gi_arkiv['section_id'],
			'input_type' => 'listbox',
			'name' => 'debug',
			'descr' => 'debug',
			'choice' => [1],
		]);
	}

	private function seedAsyncTasks(): void
	{
		// set_timer() is idempotent — returns false if the id already exists.
		// delete_access_log is covered by m...000181.
		try
		{
			$asyncservice = \CreateObject('phpgwapi.asyncservice');

			$tasks = [
				['booking_async_task_delete_expired_blocks', ['min' => '*/5'], 'booking.async_task_delete_expired_blocks'],
				['booking_async_task_update_reservation_state', ['hour' => '*/1'], 'booking.async_task_update_reservation_state'],
				['booking_async_task_delete_participants', ['day' => '*/1'], 'booking.async_task_delete_participants'],
				['booking_async_task_clean_up_old_posts', ['day' => '*/1'], 'booking.async_task_clean_up_old_posts'],
			];

			foreach ($tasks as [$id, $times, $task_class])
			{
				$asyncservice->set_timer(
					$times,
					$id,
					'booking.async_task.doRun',
					['task_class' => $task_class]
				);
			}
		}
		catch (\Throwable $e)
		{
			// Async service may not be available during setup
		}
	}
};
