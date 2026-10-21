<?php

namespace App\modules\email\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailAttachmentController
{
	public function index(Request $request, Response $response): Response
	{
		try
		{
			$businessObject = \CreateObject('email.boattach_file');
			return $this->json($response, $businessObject->attach(true));
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
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
		}
	}

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

		$originalPost = $_POST;
		$originalName = (string) ($file['name'] ?? 'attachment');
		$_FILES['uploadedfile']['name'] = trim(preg_replace('/[\x00-\x1f\x7f]/', '_', basename(str_replace('\\', '/', $originalName))) ?? 'attachment');
		$_POST['action'] = lang('Attach File');
		try
		{
			$businessObject = \CreateObject('email.boattach_file');
			$result = $businessObject->attach(true);
			if (!empty($result['message'])) return $this->json($response, ['error' => $result['message']], 422);
			return $this->json($response, $result);
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
			$_POST = $originalPost;
			$_FILES['uploadedfile']['name'] = $originalName;
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
		}
	}

	public function delete(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input)) $input = json_decode((string) $request->getBody(), true);
		$ids = is_array($input) ? ($input['ids'] ?? null) : null;
		if (!is_array($ids) || !$ids)
		{
			return $this->json($response, ['error' => lang('Select attachments to delete')], 422);
		}
		foreach ($ids as $id)
		{
			if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/i', $id))
			{
				return $this->json($response, ['error' => lang('Invalid attachment reference')], 400);
			}
		}

		$originalPost = $_POST;
		$_POST['action'] = lang('Delete');
		$_POST['delete'] = array_values($ids);
		try
		{
			$businessObject = \CreateObject('email.boattach_file');
			return $this->json($response, $businessObject->attach(true));
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
			$_POST = $originalPost;
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
		}
	}

	private function json(Response $response, array $data, int $status = 200): Response
	{
		$encoded = json_encode($data);
		$response->getBody()->write($encoded === false ? '{}' : $encoded);
		return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
	}
}
