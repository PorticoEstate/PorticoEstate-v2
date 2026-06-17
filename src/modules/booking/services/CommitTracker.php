<?php

namespace App\modules\booking\services;

use App\Database\Db;

/**
 * Tracks the git commit the running Slim instance is on and persists the first
 * time each distinct commit was observed (bb_running_commit).
 *
 * The repository (including .git) is volume-mounted at the project root and the
 * container entrypoint marks it a safe git directory, so the live commit is read
 * directly with `git rev-parse HEAD` rather than being baked in at build time.
 *
 * Everything is APCu-guarded so the hot path (every web request) does at most a
 * couple of APCu reads — git and the database are only touched when the cache is
 * cold or the running commit changes after a deploy.
 */
class CommitTracker
{
	private const APCU_HASH_KEY = 'booking_running_commit_hash';
	private const APCU_RECORDED_PREFIX = 'booking_running_commit_recorded_';
	private const HASH_TTL = 300;     // re-read git HEAD at most every 5 min
	private const RECORDED_TTL = 3600; // re-attempt the guarded insert at most hourly

	private ?Db $db = null;

	private function db(): Db
	{
		return $this->db ??= Db::getInstance();
	}

	private function apcuEnabled(): bool
	{
		return function_exists('apcu_enabled') && apcu_enabled();
	}

	/**
	 * Resolve the 40-char commit hash of the running checkout, or null if it
	 * cannot be determined. Cached in APCu to avoid spawning git per request.
	 */
	public function getCurrentCommit(): ?string
	{
		if ($this->apcuEnabled())
		{
			$cached = apcu_fetch(self::APCU_HASH_KEY, $ok);
			if ($ok)
			{
				return $cached === '' ? null : $cached;
			}
		}

		$hash = $this->readGitHead();

		if ($this->apcuEnabled())
		{
			// Store '' for the failure case too, so we don't re-run git every request.
			apcu_store(self::APCU_HASH_KEY, (string) $hash, self::HASH_TTL);
		}

		return $hash;
	}

	private function readGitHead(): ?string
	{
		$root = defined('SRC_ROOT_PATH') ? dirname(SRC_ROOT_PATH) : getcwd();
		$cmd = 'git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null';
		$out = @shell_exec($cmd);
		$hash = is_string($out) ? trim($out) : '';

		return preg_match('/^[0-9a-f]{40}$/', $hash) === 1 ? $hash : null;
	}

	/**
	 * Record the running commit with its first-seen timestamp. Idempotent: the
	 * insert is guarded by a NOT EXISTS check and the unique constraint, and an
	 * APCu flag short-circuits the database entirely once a commit is recorded.
	 */
	public function record(): void
	{
		$hash = $this->getCurrentCommit();
		if ($hash === null)
		{
			return;
		}

		$recordedKey = self::APCU_RECORDED_PREFIX . $hash;
		if ($this->apcuEnabled() && apcu_fetch($recordedKey))
		{
			return;
		}

		// $hash is validated as 40 hex chars by getCurrentCommit(), so it is safe
		// to interpolate directly.
		$this->db()->query(
			"INSERT INTO bb_running_commit (commit_hash, first_seen) "
			. "SELECT '{$hash}', now() "
			. "WHERE NOT EXISTS (SELECT 1 FROM bb_running_commit WHERE commit_hash = '{$hash}')",
			__LINE__,
			__FILE__
		);

		if ($this->apcuEnabled())
		{
			apcu_store($recordedKey, true, self::RECORDED_TTL);
		}
	}

	/**
	 * The currently running commit and when it was first seen.
	 *
	 * @return array{commitId: ?string, firstSeen: ?string}
	 */
	public function getCurrent(): array
	{
		$hash = $this->getCurrentCommit();
		if ($hash === null)
		{
			return ['commitId' => null, 'firstSeen' => null];
		}

		$this->db()->query(
			"SELECT first_seen FROM bb_running_commit WHERE commit_hash = '{$hash}'",
			__LINE__,
			__FILE__
		);

		$firstSeen = $this->db()->next_record() ? $this->db()->f('first_seen') : null;

		return ['commitId' => $hash, 'firstSeen' => $firstSeen];
	}

	/**
	 * Full deploy history, newest first.
	 *
	 * @return array<int, array{commitId: string, firstSeen: string}>
	 */
	public function all(): array
	{
		$this->db()->query(
			"SELECT commit_hash, first_seen FROM bb_running_commit ORDER BY first_seen DESC",
			__LINE__,
			__FILE__
		);

		$rows = [];
		while ($this->db()->next_record())
		{
			$rows[] = [
				'commitId' => $this->db()->f('commit_hash'),
				'firstSeen' => $this->db()->f('first_seen'),
			];
		}

		return $rows;
	}
}
