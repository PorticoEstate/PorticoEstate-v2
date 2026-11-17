<?php

/**
 * EMail - Attachment Storage
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Manages compose attachment files and metadata, including uploads, restored content, lookup, and deletion.
 */

namespace App\modules\email\services;

final class EmailAttachmentStorage
{
	/**
	 * Initialize attachment storage within the supplied compose directory.
	 *
	 * @param string $directory The filesystem directory dedicated to compose attachment files.
	 */
	public function __construct(private string $directory)
	{
		if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory))
		{
			throw new \RuntimeException('Unable to initialize attachment storage');
		}
	}

	/**
	 * List stored attachment descriptors without exposing unrelated files.
	 *
	 * @return array The available attachment identifiers, names, MIME types, and sizes.
	 */
	public function list(): array
	{
		$items = [];
		foreach (glob($this->directory . '/*.info') ?: [] as $metadataPath)
		{
			$id = basename($metadataPath, '.info');
			if (!preg_match('/^[a-f0-9]{32}$/i', $id))
            {
            	continue;
            }
			$attachmentPath = $this->path($id);
			if (!is_file($attachmentPath) || is_link($metadataPath) || is_link($attachmentPath))
            {
            	continue;
            }
			$metadata = file($metadataPath, FILE_IGNORE_NEW_LINES);
			if (!is_array($metadata))
            {
            	continue;
            }
			$items[] = [
				'id' => $id,
				'name' => trim((string) ($metadata[1] ?? 'attachment')),
				'mimeType' => trim((string) ($metadata[0] ?? 'application/octet-stream')),
				'size' => (int) filesize($attachmentPath),
			];
		}
		return $items;
	}

	/**
	 * Move a submitted upload into the compose attachment store.
	 *
	 * @param string $temporaryPath The temporary upload file to move into attachment storage.
	 * @param string $name The attachment filename supplied by the upload or source message.
	 * @param string $mimeType The declared MIME media type to normalize and store.
	 * @return array The complete attachment list after storing the upload.
	 */
	public function storeUploadedFile(string $temporaryPath, string $name, string $mimeType): array
	{
		$id = bin2hex(random_bytes(16));
		$attachmentPath = $this->path($id);
		[$name, $mimeType] = $this->normalizeMetadata($name, $mimeType);
		if (!move_uploaded_file($temporaryPath, $attachmentPath))
        {
        	throw new \RuntimeException('Unable to store uploaded attachment');
        }
		$this->writeMetadata($attachmentPath, $mimeType, $name);
		return $this->list();
	}

	/**
	 * Write restored or generated attachment bytes into compose storage.
	 *
	 * @param string $content The attachment bytes to store.
	 * @param string $name The attachment filename supplied by the upload or source message.
	 * @param string $mimeType The declared MIME media type to normalize and store.
	 * @return array The complete attachment list after storing the content.
	 */
	public function storeContent(string $content, string $name, string $mimeType): array
	{
		$id = bin2hex(random_bytes(16));
		$attachmentPath = $this->path($id);
		[$name, $mimeType] = $this->normalizeMetadata($name, $mimeType);
		if (file_put_contents($attachmentPath, $content, LOCK_EX) === false)
        {
        	throw new \RuntimeException('Unable to store attachment');
        }
		$this->writeMetadata($attachmentPath, $mimeType, $name);
		return $this->list();
	}

	/**
	 * Remove the selected attachment files and their metadata.
	 *
	 * @param array $ids The attachment identifiers selected for deletion.
	 * @return array The remaining attachment descriptors after deletion.
	 */
	public function delete(array $ids): array
	{
		foreach ($ids as $id)
		{
			if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/i', $id))
            {
            	throw new \InvalidArgumentException('Invalid attachment reference');
            }
			foreach ([$this->path($id), $this->path($id) . '.info'] as $path)
			{
				if (is_file($path) && !is_link($path) && !unlink($path))
                {
                	throw new \RuntimeException('Unable to remove attachment');
                }
			}
		}
		return $this->list();
	}

	/**
	 * Resolve a validated attachment identifier to its stored file.
	 *
	 * @param string $id The opaque compose attachment identifier to validate and locate.
	 * @return string The filesystem path of the selected attachment.
	 */
	private function path(string $id): string
	{
		if (!preg_match('/^[a-f0-9]{32}$/i', $id))
        {
        	throw new \InvalidArgumentException('Invalid attachment reference');
        }
		return $this->directory . '/' . $id;
	}

	/**
	 * Normalize the attachment filename and MIME type before storage.
	 *
	 * @param string $name The attachment filename supplied by the upload or source message.
	 * @param string $mimeType The declared MIME media type to normalize and store.
	 * @return array The normalized filename and MIME-type values.
	 */
	private function normalizeMetadata(string $name, string $mimeType): array
	{
		$name = trim(preg_replace('/[\x00-\x1f\x7f]/', '_', basename(str_replace('\\', '/', $name))) ?? 'attachment');
		$mimeType = trim(preg_replace('/[\r\n]+/', '', $mimeType) ?? 'application/octet-stream');
		return [$name !== '' ? $name : 'attachment', $mimeType !== '' ? $mimeType : 'application/octet-stream'];
	}

	/**
	 * Write the filename and MIME-type metadata beside an attachment file.
	 *
	 * @param string $attachmentPath The stored attachment path whose metadata should be written.
	 * @param string $mimeType The declared MIME media type to normalize and store.
	 * @param string $name The attachment filename supplied by the upload or source message.
	 * @return void No value is returned.
	 */
	private function writeMetadata(string $attachmentPath, string $mimeType, string $name): void
	{
		if (file_put_contents($attachmentPath . '.info', $mimeType . "\n" . $name . "\n", LOCK_EX) === false)
		{
			@unlink($attachmentPath);
			throw new \RuntimeException('Unable to store attachment metadata');
		}
	}
}