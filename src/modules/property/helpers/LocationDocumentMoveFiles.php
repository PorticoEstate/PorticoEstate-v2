<?php

namespace App\modules\property\helpers;

use DomainException;
use FilesystemIterator;

final class LocationDocumentMoveFiles
{
	public function prepare(string $source, string $target, int $deadline): bool
	{
		return $this->walk($source, $target, $deadline, false);
	}

	public function cleanup(string $source, string $target, int $deadline): bool
	{
		return $this->walk($source, $target, $deadline, true);
	}

	private function walk(string $source, string $target, int $deadline, bool $cleanup): bool
	{
		clearstatcache();
		if ($cleanup && !file_exists($source) && !is_link($source))
		{
			return true;
		}
		if (is_link($source) || !is_dir($source) || is_link($target)
			|| (file_exists($target) && !is_dir($target)))
		{
			throw new DomainException("Directory conflict or missing source: {$source}");
		}
		if (!is_dir($target))
		{
			if ($cleanup)
			{
				throw new DomainException("Destination directory is missing: {$target}");
			}
			if (hrtime(true) >= $deadline)
			{
				return false;
			}
			if (!@mkdir($target, fileperms($source) & 0777))
			{
				throw new DomainException("Cannot create destination directory: {$target}");
			}
		}
		$a = stat($source);
		$b = stat($target);
		if (!$a || !$b || ($a['dev'] === $b['dev'] && $a['ino'] === $b['ino']))
		{
			throw new DomainException("Source and destination are not distinct directories: {$target}");
		}
		foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $entry)
		{
			$from = $entry->getPathname();
			$to = $target . '/' . $entry->getFilename();
			if (!$entry->isLink() && $entry->isDir())
			{
				if (!$this->walk($from, $to, $deadline, $cleanup))
				{
					return false;
				}
				continue;
			}
			if ($entry->isLink() || !$entry->isFile() || is_link($to))
			{
				throw new DomainException("Unsupported file or symbolic link: {$from}");
			}
			if (file_exists($to))
			{
				$a = stat($from);
				$b = stat($to);
				if (!$a || !$b || $a['dev'] !== $b['dev'] || $a['ino'] !== $b['ino'])
				{
					throw new DomainException("Destination file conflict: {$to}");
				}
				if (!$cleanup)
				{
					continue;
				}
			}
			elseif ($cleanup)
			{
				throw new DomainException("Verified destination file is missing: {$to}");
			}
			if (hrtime(true) >= $deadline)
			{
				return false;
			}
			// A hard link publishes the same bytes atomically without replacing an existing path.
			if ($cleanup ? !@unlink($from) : !@link($from, $to))
			{
				throw new DomainException($cleanup ? "Cannot remove verified source file: {$from}"
					: "Cannot link destination file: {$to} (both paths must support hard links on the same filesystem)");
			}
		}
		if ($cleanup)
		{
			if (hrtime(true) >= $deadline)
			{
				return false;
			}
			if (!@rmdir($source))
			{
				throw new DomainException("Cannot remove source directory: {$source}");
			}
		}
		return true;
	}
}
