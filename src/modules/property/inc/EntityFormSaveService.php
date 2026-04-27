<?php

namespace App\modules\property\inc;

use App\Database\Db;
use Sanitizer;

/**
 * Persists entity form data inside the legacy transaction boundary.
 */
class EntityFormSaveService
{
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
	 * Save the entity and any checklist stage updates within one transaction.
	 *
	 * @param array $values Form values to persist.
	 * @param mixed $attributes Attribute payload passed to bo->save().
	 * @param string $action add|edit.
	 * @param int $entityId Current entity id.
	 * @param int $catId Current category id.
	 * @param object $bo Legacy boentity helper.
	 * @return EntityFormSaveResult
	 */
	public function save(array $values, $attributes, string $action, int $entityId, int $catId, object $bo): EntityFormSaveResult
	{
		Db::getInstance()->transaction_begin();

		$receipt = $bo->save($values, $attributes, $action, $entityId, $catId);
		$values['id'] = $receipt['id'];
		$valuesChecklistStage = Sanitizer::get_var('values_checklist_stage');

		if ($valuesChecklistStage)
		{
			$bo->save_checklist($receipt['id'], $valuesChecklistStage, $receipt);
		}

		Db::getInstance()->transaction_commit();

		return new EntityFormSaveResult($receipt, $values);
	}
}