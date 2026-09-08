<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Create seq_bb_allocation_group and advance it past every allocation_group_id already in use (ref #982). m20260821_add_allocation_group_id_and_price_locked_to_allocation.php added the allocation_group_id column but never created the sequence the group mint draws from, so on any instance where that migration genuinely ran, the first mint fails with SQLSTATE[42P01] undefined table seq_bb_allocation_group. Two call sites take nextval from it: booking/repositories/ApplicationRepository.php and booking/inc/class.soallocation.inc.php. Creating the sequence is not enough on its own: a fresh sequence starts at 1, so on an instance that already carries grouped allocations the first mints would re-issue group ids that are in use and silently merge two unrelated series into one cascade scope. The sequence is therefore also advanced past the highest id in use. It is never moved backward: nextval is handed out before the allocation rows are written, so a sequence sitting ahead of max(allocation_group_id) is a correct state - an abandoned mint - and rewinding it to the max would re-issue ids that have already been given out.';

	private const SEQUENCE = 'seq_bb_allocation_group';

	public function up(): void
	{
		// The sequence only means anything alongside the column it feeds.
		$this->assertColumnExists('bb_allocation', 'allocation_group_id');

		if (!$this->sequenceExists(self::SEQUENCE))
		{
			$this->sql('CREATE SEQUENCE IF NOT EXISTS ' . self::SEQUENCE);
		}

		$maxInUse = $this->maxAllocationGroupId();

		if ($maxInUse === null)
		{
			// No row carries a group id, so a sequence starting at 1 collides with nothing.
			return;
		}

		if ($this->nextSequenceValue(self::SEQUENCE) > $maxInUse)
		{
			// Already past everything in use. Leave it exactly where it is - moving it
			// back to $maxInUse here would re-issue ids an earlier mint already handed out.
			return;
		}

		// setval with is_called = true marks $maxInUse as consumed, so the next
		// nextval() returns $maxInUse + 1 - the first group id that is genuinely free.
		$this->sql("SELECT setval('" . self::SEQUENCE . "', {$maxInUse}, true)");
	}

	private function sequenceExists(string $sequence): bool
	{
		$this->db->query(
			"SELECT COUNT(*) AS cnt FROM pg_class c "
				. "JOIN pg_namespace n ON n.oid = c.relnamespace "
				. "WHERE n.nspname = 'public' AND c.relkind = 'S' AND c.relname = '{$sequence}'",
			__LINE__,
			__FILE__,
			false,
			true
		);
		$this->db->next_record();

		return (int) ($this->db->Record['cnt'] ?? 0) > 0;
	}

	private function maxAllocationGroupId(): ?int
	{
		$this->db->query(
			'SELECT MAX(allocation_group_id) AS max_gid FROM bb_allocation',
			__LINE__,
			__FILE__,
			false,
			true
		);
		$this->db->next_record();
		$max = $this->db->Record['max_gid'] ?? null;

		return $max === null ? null : (int) $max;
	}

	/**
	 * What the next nextval() would return, read from the sequence without consuming it.
	 * A sequence reports is_called = false until its first nextval, and in that state
	 * last_value is the value that will be handed out rather than one already used.
	 */
	private function nextSequenceValue(string $sequence): int
	{
		$this->db->query(
			"SELECT CASE WHEN is_called THEN last_value + 1 ELSE last_value END AS next_value FROM {$sequence}",
			__LINE__,
			__FILE__,
			false,
			true
		);
		$this->db->next_record();

		return (int) $this->db->Record['next_value'];
	}
};
