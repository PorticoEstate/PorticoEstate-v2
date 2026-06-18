<?php

use App\modules\phpgwapi\services\Migration\Migration;

/**
 * Seed bb_running_commit with known earlier commits and their commit dates,
 * so the deploy history reaches back before first-seen tracking was added.
 *
 * first_seen here is the commit's own date (committer local time, from
 * `git show -s --format=%ci`) rather than an observed runtime — these commits
 * predate the tracking. Idempotent: each insert is guarded by NOT EXISTS.
 */
return new class extends Migration
{
	public string $description = 'Seed bb_running_commit with known historical commits and their commit dates';

	public function up(): void
	{
		$this->assertTableExists('bb_running_commit');

		$commits = [
			// [full hash, commit date (committer local time)]
			['ccd74a91ccbc00eb264a632f1152a6dc57693e43', '2026-06-01 14:10:50'],
			['3cc00e085e1892a51cc3270cfc5105daa1150627', '2026-06-02 13:16:27'],
			['46642b0e0256be645d849ad4b0d69ce2869d0908', '2026-06-16 12:17:39'],
		];

		foreach ($commits as [$hash, $date])
		{
			$this->sql(
				"INSERT INTO bb_running_commit (commit_hash, first_seen) "
				. "SELECT '{$hash}', '{$date}' "
				. "WHERE NOT EXISTS (SELECT 1 FROM bb_running_commit WHERE commit_hash = '{$hash}')"
			);
		}
	}
};
