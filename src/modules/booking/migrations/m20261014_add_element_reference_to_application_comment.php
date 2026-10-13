<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add element_type and element_id to bb_application_comment (ref #1392). A comment can be about one element of the application - an allocation, event or booking - instead of the application as a whole: the applicant comments on one reserved time, a timeslot withdraw records which allocation it released, and the case officer can reply on that element. element_type names the table, because ids overlap across bb_allocation, bb_event and bb_booking; a CHECK keeps both columns set or both NULL, and NULL/NULL is a comment on the whole application, which is every pre-existing row. No foreign key on element_id, which points into three tables, and an allocation can be hard-deleted (the comment then names an element that is gone, and the reader says so). The index on (application_id, element_type, element_id) is also the first index on application_id, which every comment read filters on. booking_soapplication lists both columns in its comments manytomany so the legacy DELETE and re-INSERT of the comment set writes them back.';

	private const TABLE = 'bb_application_comment';

	public function up(): void
	{
		$this->assertTableExists(self::TABLE);

		$this->ensureColumn(self::TABLE, 'element_type', [
			'type' => 'varchar',
			'precision' => 20,
			'nullable' => true,
		]);

		$this->ensureColumn(self::TABLE, 'element_id', [
			'type' => 'int',
			'precision' => 4,
			'nullable' => true,
		]);

		if (!$this->constraintExists(self::TABLE, 'bb_application_comment_element_type_check'))
		{
			$this->sql("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT bb_application_comment_element_type_check CHECK (element_type IN ('allocation', 'event', 'booking'))");
		}

		// Both or neither: an id without its type could name a row in any of the three tables.
		if (!$this->constraintExists(self::TABLE, 'bb_application_comment_element_ref_check'))
		{
			$this->sql("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT bb_application_comment_element_ref_check CHECK ((element_type IS NULL) = (element_id IS NULL))");
		}

		$this->addIndex(self::TABLE, 'bb_application_comment_element_idx', ['application_id', 'element_type', 'element_id']);
	}
};
