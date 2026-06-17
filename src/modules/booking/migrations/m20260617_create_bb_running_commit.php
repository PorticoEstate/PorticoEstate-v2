<?php

use App\modules\phpgwapi\services\Migration\Migration;

/**
 * Tracks which git commit the Slim instance has run, and when each commit was
 * first observed running. One row per distinct commit hash; first_seen is the
 * timestamp the running instance first recorded that hash. This builds a
 * deploy history that can later be enriched (titles, release versions, diffs)
 * from git/GitHub without persisting any of that derived data here.
 */
return new class extends Migration
{
	public string $description = 'Create bb_running_commit table tracking running git commit and first-seen timestamp';

	public function up(): void
	{
		$this->createTable('bb_running_commit', [
			'fd' => [
				'id' => ['type' => 'auto', 'nullable' => false],
				'commit_hash' => ['type' => 'varchar', 'precision' => '40', 'nullable' => false],
				'first_seen' => ['type' => 'timestamp', 'nullable' => false, 'default' => 'current_timestamp'],
			],
			'pk' => ['id'],
			'fk' => [],
			'ix' => [['first_seen']],
			'uc' => [['commit_hash']],
		]);
	}
};
