<?php

namespace App\modules\email\services;

final class EmailAttachmentStorage
{
	public function __construct(private string $directory)
	{
		if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory))
		{
			throw new \RuntimeException('Unable to initialize attachment storage');
		}
	}

	public function list(): array
	{
		$items = [];
		foreach (glob($this->directory . '/*.info') ?: [] as $metadataPath)
		{
			$id = basename($metadataPath, '.info');
			if (!preg_match('/^[a-f0-9]{32}$/i', $id)) continue;
			$attachmentPath = $this->path($id);
			if (!is_file($attachmentPath) || is_link($metadataPath) || is_link($attachmentPath)) continue;
			$metadata = file($metadataPath, FILE_IGNORE_NEW_LINES);
			if (!is_array($metadata)) continue;
			$items[] = [
				'id' => $id,
				'name' => trim((string) ($metadata[1] ?? 'attachment')),
				'mimeType' => trim((string) ($metadata[0] ?? 'application/octet-stream')),
				'size' => (int) filesize($attachmentPath),
			];
		}
		return $items;
	}

	public function storeUploadedFile(string $temporaryPath, string $name, string $mimeType): array
	{
		$id = bin2hex(random_bytes(16));
		$attachmentPath = $this->path($id);
		[$name, $mimeType] = $this->normalizeMetadata($name, $mimeType);
		if (!move_uploaded_file($temporaryPath, $attachmentPath)) throw new \RuntimeException('Unable to store uploaded attachment');
		$this->writeMetadata($attachmentPath, $mimeType, $name);
		return $this->list();
	}

	public function storeContent(string $content, string $name, string $mimeType): array
	{
		$id = bin2hex(random_bytes(16));
		$attachmentPath = $this->path($id);
		[$name, $mimeType] = $this->normalizeMetadata($name, $mimeType);
		if (file_put_contents($attachmentPath, $content, LOCK_EX) === false) throw new \RuntimeException('Unable to store attachment');
		$this->writeMetadata($attachmentPath, $mimeType, $name);
		return $this->list();
	}

	public function delete(array $ids): array
	{
		foreach ($ids as $id)
		{
			if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/i', $id)) throw new \InvalidArgumentException('Invalid attachment reference');
			foreach ([$this->path($id), $this->path($id) . '.info'] as $path)
			{
				if (is_file($path) && !is_link($path) && !unlink($path)) throw new \RuntimeException('Unable to remove attachment');
			}
		}
		return $this->list();
	}

	private function path(string $id): string
	{
		if (!preg_match('/^[a-f0-9]{32}$/i', $id)) throw new \InvalidArgumentException('Invalid attachment reference');
		return $this->directory . '/' . $id;
	}

	private function normalizeMetadata(string $name, string $mimeType): array
	{
		$name = trim(preg_replace('/[\x00-\x1f\x7f]/', '_', basename(str_replace('\\', '/', $name))) ?? 'attachment');
		$mimeType = trim(preg_replace('/[\r\n]+/', '', $mimeType) ?? 'application/octet-stream');
		return [$name !== '' ? $name : 'attachment', $mimeType !== '' ? $mimeType : 'application/octet-stream'];
	}

	private function writeMetadata(string $attachmentPath, string $mimeType, string $name): void
	{
		if (file_put_contents($attachmentPath . '.info', $mimeType . "\n" . $name . "\n", LOCK_EX) === false)
		{
			@unlink($attachmentPath);
			throw new \RuntimeException('Unable to store attachment metadata');
		}
	}
}