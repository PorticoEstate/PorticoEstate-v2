<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create bb_application_change_request (ref #1392). An applicant asking for a time outside what was approved for an element of an accepted application is a request the case officer accepts or rejects on that element, not a reason to put the whole application back in PENDING. Until now the request existed only as comment text. One row per request: the element (type + id), its time when asked (old_from_/old_to_) and the time asked for (new_from_/new_to_), both naive local like the element tables, and the status OPEN, ACCEPTED, REJECTED or SUPERSEDED. created, modified and resolved_at are naive UTC. Only events write it for now; element_type admits allocation and booking so allocations can use the same table later. At most one OPEN request per element, enforced by a partial unique index. No foreign key on application_id: soapplication::delete_application deletes applications outright and a key would block that; a request of a deleted application is inert. No foreign key on element_id, which points into three tables.';

	private const TABLE = 'bb_application_change_request';

	public function up(): void
	{
		$this->assertTableExists('bb_application');

		$this->createTable(self::TABLE, [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'application_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'element_type' => ['type' => 'varchar', 'precision' => 20, 'nullable' => false],
				'element_id' => ['type' => 'int', 'precision' => 4, 'nullable' => false],
				'old_from_' => ['type' => 'timestamp', 'nullable' => false],
				'old_to_' => ['type' => 'timestamp', 'nullable' => false],
				'new_from_' => ['type' => 'timestamp', 'nullable' => false],
				'new_to_' => ['type' => 'timestamp', 'nullable' => false],
				'status' => ['type' => 'varchar', 'precision' => 20, 'nullable' => false, 'default' => 'OPEN'],
				'requested_by' => ['type' => 'varchar', 'precision' => 150, 'nullable' => true],
				'created' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
				'modified' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
				'resolved_by' => ['type' => 'int', 'precision' => 4, 'nullable' => true],
				'resolved_at' => ['type' => 'timestamp', 'nullable' => true],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [],
			'uc' => [],
		]);

		$this->addIndex(self::TABLE, 'bb_application_change_request_application_idx', ['application_id', 'status']);

		// One open request per element: a new request supersedes the open one first.
		$this->addIndex(self::TABLE, 'bb_application_change_request_one_open_idx', ['element_type', 'element_id'], true, "status = 'OPEN'");

		if (!$this->constraintExists(self::TABLE, 'bb_application_change_request_element_type_check'))
		{
			$this->sql("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT bb_application_change_request_element_type_check CHECK (element_type IN ('event', 'allocation', 'booking'))");
		}

		if (!$this->constraintExists(self::TABLE, 'bb_application_change_request_status_check'))
		{
			$this->sql("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT bb_application_change_request_status_check CHECK (status IN ('OPEN', 'ACCEPTED', 'REJECTED', 'SUPERSEDED'))");
		}
	}
};
