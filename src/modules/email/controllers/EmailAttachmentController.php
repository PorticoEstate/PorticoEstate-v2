<?php

/**
 * EMail - Compose Attachments API
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Lists, uploads, and deletes temporary compose attachments using session-scoped storage.
 */

namespace App\modules\email\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailAttachmentController
{
	/**
	 * Resolve the attachment store for the current compose session.
	 *
	 * @return \App\modules\email\services\EmailAttachmentStorage The session-scoped compose attachment store.
	 */
	private function storage(): \App\modules\email\services\EmailAttachmentStorage
	{
		$bootstrap = \CreateObject('email.msg_bootstrap');
		$bootstrap->ensure_mail_msg_exists('email.api.attachments');
		$mail = \App\modules\email\services\MailMessage::getInstance();
		return new \App\modules\email\services\EmailAttachmentStorage($mail->att_files_dir);
	}

	/**
	 * List attachments available to the current compose session.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response Attachment descriptors or a storage error as JSON.
	 */
	public function index(Request $request, Response $response): Response
	{
		try
		{
			return $this->json($response, ['items' => $this->storage()->list()]);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailAttachmentController::index failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to load attachments'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::getInstance()->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
		}
	}

	/**
	 * Validate and store uploaded compose attachments.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response Stored attachment descriptors or upload errors as JSON.
	 */
	public function upload(Request $request, Response $response): Response
	{
		$file = $_FILES['uploadedfile'] ?? null;
		if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
		{
			return $this->json($response, ['error' => lang('Select a file to attach')], 422);
		}
		if ((int) ($file['size'] ?? 0) > 25 * 1024 * 1024)
		{
			return $this->json($response, ['error' => lang('Attachment exceeds the 25 MiB limit')], 413);
		}
		if (!is_uploaded_file((string) ($file['tmp_name'] ?? '')))
		{
			return $this->json($response, ['error' => lang('Invalid uploaded file')], 400);
		}

		$originalName = (string) ($file['name'] ?? 'attachment');
		try
		{
			$storage = $this->storage();
			return $this->json($response, ['items' => $storage->storeUploadedFile(
				(string) $file['tmp_name'], $originalName, (string) ($file['type'] ?? 'application/octet-stream'))]);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailAttachmentController::upload failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to upload attachment'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::getInstance()->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
		}
	}

	/**
	 * Remove selected attachments from the compose session.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response The deletion result or an attachment error as JSON.
	 */
	public function delete(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input))
        {
        	$input = json_decode((string) $request->getBody(), true);
        }
		$ids = is_array($input) ? ($input['ids'] ?? null) : null;
		if (!is_array($ids) || !$ids)
		{
			return $this->json($response, ['error' => lang('Select attachments to delete')], 422);
		}
		try
		{
			return $this->json($response, ['items' => $this->storage()->delete($ids)]);
		}
		catch (\InvalidArgumentException $exception)
		{
			return $this->json($response, ['error' => $exception->getMessage()], 400);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailAttachmentController::delete failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to delete attachments'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::getInstance()->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
		}
	}

	/**
	 * Encode an API payload and apply its JSON response headers and status.
	 *
	 * @param Response $response The response to populate with content, headers, and status.
	 * @param array $data The response payload or template variables to render.
	 * @param int $status The HTTP status code to return.
	 * @return Response The JSON HTTP response.
	 */
	private function json(Response $response, array $data, int $status = 200): Response
	{
		$encoded = json_encode($data);
		$response->getBody()->write($encoded === false ? '{}' : $encoded);
		return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
	}
}
