<?php

namespace App\modules\property\inc;

use App\Database\Db;
use App\modules\phpgwapi\services\Cache;
use phpgw;
use Sanitizer;

/**
 * Consolidated helper for entity form workflows — maps, validates, persists, and responds.
 *
 * This class encapsulates the complete legacy entity save flow:
 * 1. mapInput() — parse request state into normalized form arrays
 * 2. validate() — check required fields and data types
 * 3. rehydrate() — restore location/parent entity details after errors
 * 4. persistSave() — persist entity + checklist within one transaction
 * 5. handleFiles() — process file uploads/deletions
 * 6. buildSaveResponse() — decide JSON/edit/redirect outcome
 *
 * @package property
 */
class EntityFormHelper
{
	/**
	 * Map legacy entity form request state into normalized input arrays.
	 *
	 * @param string $typeApp     Current app key, e.g. property.
	 * @param string $type        Entity type key.
	 * @param string $aclLocation Current ACL location.
	 * @param object $bocommon    Legacy bocommon helper with collect_locationdata().
	 * @return array{values: array, values_attribute: mixed, bypass: bool}
	 */
	public function mapInput(string $typeApp, string $type, string $aclLocation, object $bocommon): array
	{
		$values = (array) Sanitizer::get_var('values');
		$values_attribute = Sanitizer::get_var('values_attribute');
		$bypass = (bool) Sanitizer::get_var('bypass', 'bool');

		$values['vendor_id'] = Sanitizer::get_var('vendor_id', 'int', 'POST');
		$values['vendor_name'] = Sanitizer::get_var('vendor_name', 'string', 'POST');
		$values['date'] = Sanitizer::get_var('date');

		if (!$bypass)
		{
			$insert_record = Cache::session_get('property', 'insert_record');
			$insert_record_entity = (array) Cache::session_get($typeApp, 'insert_record_values' . $aclLocation);

			if (is_array($insert_record_entity))
			{
				foreach ($insert_record_entity as $insert_value)
				{
					$insert_record['extra'][$insert_value] = $insert_value;
				}
			}

			$values = $bocommon->collect_locationdata($values, $insert_record);
		}

		return [
			'values' => $values,
			'values_attribute' => $values_attribute,
			'bypass' => $bypass,
		];
	}

	/**
	 * Validate entity form input during the legacy save flow.
	 *
	 * @param array $values Current form values.
	 * @param mixed $valuesAttribute Submitted attribute values.
	 * @param int $catId Current category id.
	 * @param int $entityId Current entity id.
	 * @param object $soadminEntity Legacy category reader.
	 * @param object $bo Legacy boentity helper for attribute metadata enrichment.
	 * @return array{values: array, values_attribute: mixed, errors: array}
	 */
	public function validate(
		array $values,
		$valuesAttribute,
		int $catId,
		int $entityId,
		object $soadminEntity,
		object $bo
	): array {
		$errors = [];

		if (!$catId)
		{
			$errors[] = ['msg' => lang('Please select entity type !')];

			return [
				'values' => $values,
				'values_attribute' => $valuesAttribute,
				'errors' => $errors,
			];
		}

		$category = $soadminEntity->read_single_category($entityId, $catId);

		if (!empty($category['org_unit']))
		{
			$orgUnitId = $values['extra']['org_unit_id'] ?? Sanitizer::get_var('org_unit_id', 'int');
			$orgUnitName = $values['org_unit_name'] ?? Sanitizer::get_var('org_unit_name', 'string');
			$values['extra']['org_unit_id'] = $orgUnitId;
			$values['org_unit_id'] = $orgUnitId;
			$values['org_unit_name'] = $orgUnitName;
		}

		if (phpgw::is_repost())
		{
			$errors[] = ['msg' => lang('Hmm... looks like a repost!')];
		}

		if (empty($values['location']) && empty($values['p']) && !empty($category['location_level']))
		{
			$errors[] = ['msg' => lang('Please select a location !')];
		}

		if (isset($valuesAttribute) && is_array($valuesAttribute))
		{
			$firstAttribute = current($valuesAttribute);
			if (empty($firstAttribute['datatype']))
			{
				$bo->get_attribute_information($valuesAttribute);
			}

			foreach ($valuesAttribute as $attribute)
			{
				$extraValue = $values['extra'][$attribute['name']] ?? null;
				if (($attribute['nullable'] ?? null) != 1 && empty($attribute['value']) && empty($extraValue))
				{
					$errors[] = ['msg' => lang('Please enter value for attribute %1', $attribute['input_text'])];
				}

				if (!empty($attribute['value']) && ($attribute['datatype'] ?? null) == 'I' && !ctype_digit((string) $attribute['value']))
				{
					$errors[] = ['msg' => lang('Please enter integer for attribute %1', $attribute['input_text'])];
				}
			}
		}

		return [
			'values' => $values,
			'values_attribute' => $valuesAttribute,
			'errors' => $errors,
		];
	}

	/**
	 * Restore location details and parent entity references after validation errors.
	 *
	 * @param array $values Current form values.
	 * @return array Rehydrated form values.
	 */
	public function rehydrate(array $values): array
	{
		if ($values['location'])
		{
			$bolocation = CreateObject('property.bolocation');
			$location_code = implode("-", $values['location']);
			$values['extra']['view'] = true;
			$values['location_data'] = $bolocation->read_single($location_code, $values['extra']);
		}

		if ($values['extra']['p_num'])
		{
			$values['p'][$values['extra']['p_entity_id']]['p_num'] = $values['extra']['p_num'];
			$values['p'][$values['extra']['p_entity_id']]['p_entity_id'] = $values['extra']['p_entity_id'];
			$values['p'][$values['extra']['p_entity_id']]['p_cat_id'] = $values['extra']['p_cat_id'];
			$values['p'][$values['extra']['p_entity_id']]['p_cat_name'] = Sanitizer::get_var('entity_cat_name_' . $values['extra']['p_entity_id']);
		}

		return $values;
	}

	/**
	 * Save the entity and any checklist stage updates within one transaction.
	 *
	 * @param array $values Form values to persist.
	 * @param mixed $attributes Attribute payload passed to bo->save().
	 * @param string $action add|edit.
	 * @param int $entityId Current entity id.
	 * @param int $catId Current category id.
	 * @param object $bo Legacy boentity helper.
	 * @param mixed $valuesChecklistStage Optional checklist stage payload.
	 * @return array{receipt: array, values: array}
	 */
	public function persistSave(
		array $values,
		$attributes,
		string $action,
		int $entityId,
		int $catId,
		object $bo,
		$valuesChecklistStage = null
	): array
	{
		Db::getInstance()->transaction_begin();

		$receipt = $bo->save($values, $attributes, $action, $entityId, $catId);
		$values['id'] = $receipt['id'];
		if ($valuesChecklistStage === null)
		{
			$valuesChecklistStage = Sanitizer::get_var('values_checklist_stage');
		}

		if ($valuesChecklistStage)
		{
			$bo->save_checklist($receipt['id'], $valuesChecklistStage, $receipt);
		}

		Db::getInstance()->transaction_commit();

		return [
			'receipt' => $receipt,
			'values' => $values,
		];
	}

	/**
	 * Process file deletions and uploads after a successful save.
	 *
	 * @param array $values Saved form values including id and location data.
	 * @param string $categoryDir Entity category directory.
	 * @param string $typeApp Current type app value.
	 * @param array $errors Receipt error array to append upload/file conflicts to.
	 * @return void
	 */
	public function handleFiles(array $values, string $categoryDir, string $typeApp, array &$errors): void
	{
		$id = (int) $values['id'];
		if (empty($id))
		{
			throw new \Exception('uientity::_handle_files() - missing id');
		}

		$loc1 = isset($values['location']['loc1']) && $values['location']['loc1'] ? $values['location']['loc1'] : 'dummy';
		if ($typeApp == 'catch')
		{
			$loc1 = 'dummy';
		}

		$bofiles = CreateObject('property.bofiles');
		if (isset($values['file_action']) && is_array($values['file_action']))
		{
			$bofiles->delete_file("/{$categoryDir}/{$loc1}/{$id}/", $values);
		}

		if (isset($values['file_jasperaction']) && is_array($values['file_jasperaction']))
		{
			$values['file_action'] = $values['file_jasperaction'];
			$bofiles->delete_file("{$categoryDir}/{$loc1}/{$id}/", $values);
		}

		$files = [];
		if (isset($_FILES['file']['name']) && $_FILES['file']['name'])
		{
			$fileName = str_replace(' ', '_', $_FILES['file']['name']);
			$toFile = "{$bofiles->fakebase}/{$categoryDir}/{$loc1}/{$id}/{$fileName}";

			if ($bofiles->vfs->file_exists([
				'string' => $toFile,
				'relatives' => [RELATIVE_NONE],
			]))
			{
				$errors[] = ['msg' => lang('This file already exists !')];
			}
			else
			{
				$files[] = [
					'from_file' => $_FILES['file']['tmp_name'],
					'to_file' => $toFile,
				];
			}
		}

		if (isset($_FILES['jasperfile']['name']) && $_FILES['jasperfile']['name'])
		{
			$fileName = 'jasper::' . str_replace(' ', '_', $_FILES['jasperfile']['name']);
			$toFile = "{$bofiles->fakebase}/{$categoryDir}/{$loc1}/{$id}/{$fileName}";

			if ($bofiles->vfs->file_exists([
				'string' => $toFile,
				'relatives' => [RELATIVE_NONE],
			]))
			{
				$errors[] = ['msg' => lang('This file already exists !')];
			}
			else
			{
				$files[] = [
					'from_file' => $_FILES['jasperfile']['tmp_name'],
					'to_file' => $toFile,
				];
			}
		}

		foreach ($files as $file)
		{
			$bofiles->create_document_dir("{$categoryDir}/{$loc1}/{$id}");
			$bofiles->vfs->override_acl = 1;

			if (!$bofiles->vfs->cp([
				'from' => $file['from_file'],
				'to' => $file['to_file'],
				'relatives' => [RELATIVE_NONE | VFS_REAL, RELATIVE_ALL],
			]))
			{
				$errors[] = ['msg' => lang('Failed to upload file !')];
			}

			$bofiles->vfs->override_acl = 0;
		}
	}

	/**
	 * Build the legacy UI response decision for save() without performing side effects.
	 *
	 * @param bool $isJson Whether the request expects JSON.
	 * @param array $receipt Save receipt payload.
	 * @param array $values Current form values.
	 * @param string $errorOrSuccess 'error' or 'success'.
	 * @param int $originalId Entity id from the request (success path only).
	 * @param int $entityId Current entity id (success path only).
	 * @param int $catId Current category id (success path only).
	 * @param string $type Current type (success path only).
	 * @return array{type: string, payload: array, values: array}
	 */
	public function buildSaveResponse(
		bool $isJson,
		array $receipt,
		array $values,
		string $errorOrSuccess,
		int $originalId = 0,
		int $entityId = 0,
		int $catId = 0,
		string $type = ''
	): array {
		if ($errorOrSuccess === 'error')
		{
			if ($isJson)
			{
				return [
					'type' => 'json',
					'payload' => [
						'status' => 'error',
						'receipt' => $receipt,
					],
					'values' => [],
				];
			}

			return [
				'type' => 'edit',
				'payload' => [],
				'values' => $values,
			];
		}

		// Success path
		if ($isJson)
		{
			return [
				'type' => 'json',
				'payload' => [
					'status' => 'saved',
					'id' => $receipt['id'],
					'receipt' => $receipt,
				],
				'values' => [],
			];
		}

		if (!empty($values['apply']))
		{
			if ($originalId || (!empty($receipt['id'])))
			{
				$_id = !empty($receipt['id']) ? $receipt['id'] : $originalId;

				return [
					'type' => 'redirect-edit',
					'payload' => [
						'id' => $_id,
						'entity_id' => $entityId,
						'cat_id' => $catId,
						'type' => $type,
					],
					'values' => [],
				];
			}

			return [
				'type' => 'edit',
				'payload' => [],
				'values' => $values,
			];
		}

		return [
			'type' => 'redirect-index',
			'payload' => [
				'entity_id' => $entityId,
				'cat_id' => $catId,
				'type' => $type,
			],
			'values' => [],
		];
	}
}
