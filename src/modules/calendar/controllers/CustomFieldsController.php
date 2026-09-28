<?php

namespace App\modules\calendar\controllers;

use App\helpers\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CustomFieldsController
{
	private function customFields(): object
	{
		return \CreateObject('calendar.bocustom_fields');
	}

	private function mapFields(object $customFields): array
	{
		$items = [];
		$order = 0;

		foreach ((array)$customFields->fields as $field => $data)
		{
			$data = is_array($data) ? $data : ['label' => (string)$data];
			$order += 10;
			$items[] = [
				'id' => (string)$field,
				'name' => (string)($data['name'] ?? $field),
				'label' => (string)($data['label'] ?? ''),
				'length' => (int)($data['length'] ?? 0),
				'shown' => (int)($data['shown'] ?? 0),
				'order' => (int)($data['order'] ?? $order),
				'title' => !empty($data['title']) ? 1 : 0,
				'disabled' => !empty($data['disabled']) ? 1 : 0,
				'stock' => isset($customFields->stock_fields[$field]) ? 1 : 0,
			];
		}

		usort($items, function ($left, $right) {
			return $left['order'] <=> $right['order'];
		});

		return $items;
	}

	private function normalizeField(array $field): array
	{
		$id = trim((string)($field['id'] ?? ''));
		$name = trim((string)($field['name'] ?? ''));
		$isStock = !empty($field['stock']);
		$key = $isStock ? $id : '#' . ltrim($id ?: $name, '#');

		$data = [
			'name' => $isStock ? $id : $name,
			'order' => (int)($field['order'] ?? 0),
			'disabled' => !empty($field['disabled']) ? 1 : 0,
		];

		if (!empty($field['label']))
		{
			$data['label'] = (string)$field['label'];
		}

		$length = (int)($field['length'] ?? 0);
		if ($length > 0)
		{
			$data['length'] = min($length, 255);
		}

		$shown = (int)($field['shown'] ?? 0);
		if ($shown > 0 && $shown < ($data['length'] ?? 256))
		{
			$data['shown'] = $shown;
		}

		if (!empty($field['title']))
		{
			$data['title'] = 1;
		}

		return [$key, $data];
	}

	private function validate(array $fields): array
	{
		$errors = [];
		$names = [];

		foreach ($fields as $field)
		{
			$name = trim((string)($field['name'] ?? ''));
			if (!empty($field['stock']))
			{
				continue;
			}

			if ($name === '')
			{
				$errors[] = lang('New name must not be empty');
				continue;
			}

			if (isset($names[strtolower($name)]))
			{
				$errors[] = lang('New name must not exist and not be empty!!!');
			}
			$names[strtolower($name)] = true;
		}

		return $errors;
	}

	public function index(Request $request, Response $response): Response
	{
		return ResponseHelper::sendJSONResponse(['data' => $this->mapFields($this->customFields())]);
	}

	public function update(Request $request, Response $response): Response
	{
		$body = json_decode($request->getBody()->getContents(), true) ?: [];
		$fields = (array)($body['fields'] ?? []);
		$errors = $this->validate($fields);
		if ($errors)
		{
			return ResponseHelper::sendErrorResponse(['error' => implode(' ', $errors)], 422);
		}

		$customFields = $this->customFields();
		$ordered = [];
		foreach ($fields as $field)
		{
			if (!empty($field['delete']) || empty($field['id']) && empty($field['name']))
			{
				continue;
			}
			[$key, $data] = $this->normalizeField((array)$field);
			$order = (int)$data['order'];
			while (isset($ordered[$order]))
			{
				++$order;
			}
			$ordered[$order] = ['key' => $key, 'data' => $data];
		}

		ksort($ordered, SORT_NUMERIC);
		$newFields = [];
		foreach ($ordered as $item)
		{
			$newFields[$item['key']] = $item['data'];
		}

		$customFields->save($newFields);

		return ResponseHelper::sendJSONResponse([
			'message' => lang('Custom fields saved'),
			'data' => $this->mapFields($this->customFields()),
		]);
	}
}