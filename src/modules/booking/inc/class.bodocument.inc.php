<?php
	phpgw::import_class('booking.bocommon_authorized');

	abstract class booking_bodocument extends booking_bocommon_authorized
	{

		protected
			$owner_bo;

		function __construct()
		{
			parent::__construct();
			$owningType = substr(get_class($this), 19);
			$this->so = CreateObject(sprintf('booking.sodocument_%s', $owningType));
			$this->owner_bo = CreateObject(sprintf('booking.bo%s', $owningType));
		}

		/**
		 * @see bocommon_authorized
		 */
		protected function include_subject_parent_roles(array|null $for_object = null )
		{
			$parent_roles = null;
			$owner = null;

			if (is_array($for_object))
			{
				if (!isset($for_object['owner_id']))
				{
					throw new InvalidArgumentException('Cannot initialize object parent roles unless owner_id is provided');
				}

				$owner = $this->owner_bo->read_single($for_object['owner_id']);
			}

			//Note that a null value for $owner is acceptable. That only signifies
			//that any roles specified for any owner are returned instead of roles for a specific owner.
			$parent_roles['owner'] = $this->owner_bo->get_subject_roles($owner);

			return $parent_roles;
		}

		/**
		 * @see bocommon_authorized
		 */
		protected function get_object_role_permissions( $forObject, $defaultPermissions )
		{
			return array_merge(
				array
				(
				'parent_role_permissions' => array
					(
					'owner' => array
						(
						booking_sopermission::ROLE_MANAGER => array
							(
							'write' => true,
							'create' => true,
							'delete' => true,
						),
						booking_sopermission::ROLE_CASE_OFFICER => array
							(
							'write' => array_fill_keys(array('category', 'description'), true),
						),
					),
				),
				'global' => array
					(
					booking_sopermission::ROLE_MANAGER => array
						(
						'write' => true,
						'delete' => true,
						'create' => true
					),
				),
				), $defaultPermissions
			);
		}

		/**
		 * @see bocommon_authorized
		 */
		protected function get_collection_role_permissions( $defaultPermissions )
		{
			return array_merge(
				array
				(
				'parent_role_permissions' => array
					(
					'owner' => array
						(
						booking_sopermission::ROLE_MANAGER => array(
							'create' => true,
							'delete' => true,
						),
					)
				),
				'global' => array
					(
					booking_sopermission::ROLE_MANAGER => array
						(
						'create' => true,
						'delete' => true,
					)
				),
				), $defaultPermissions
			);
		}

		public function get_files_root()
		{
			return $this->so->get_files_root();
		}

		public function get_files_path()
		{
			return $this->so->get_files_path();
		}

		public function get_categories()
		{
			return $this->so->get_categories();
		}

		public function read_parent( $owner_id )
		{
			return $this->so->read_parent($owner_id);
		}

		public function read_images()
		{
			return $this->so->read_images($this->build_default_read_params());
		}

		function update($entity)
		{
			// Get current document to check previous rotation
			$currentDocument = null;
			if (isset($entity['id']))
			{
				$currentDocument = $this->read_single($entity['id']);
			}

			$metadata = isset($entity['metadata']) && is_array($entity['metadata'])
				? $entity['metadata']
				: (isset($entity['metadata']) && is_string($entity['metadata'])
					? json_decode($entity['metadata'], true)
					: array());

			if (!is_array($metadata))
			{
				$metadata = array();
			}

			$metadataUpdated = false;

			if (isset($entity['focal_point_x']) && isset($entity['focal_point_y']) && $entity['focal_point_x'] !== '' && $entity['focal_point_y'] !== '')
			{
				$metadata['focal_point'] = array(
					'x' => (float)$entity['focal_point_x'],
					'y' => (float)$entity['focal_point_y']
				);
				$metadataUpdated = true;
			}

			// Handle rotation - physically rotate the file
			if (isset($entity['rotation']) && $entity['rotation'] !== '')
			{
				$newRotation = (int)$entity['rotation'];
				$previousRotation = isset($currentDocument['metadata']['rotation']) ? (int)$currentDocument['metadata']['rotation'] : 0;

				// Calculate the actual rotation to apply (difference)
				$rotationToApply = ($newRotation - $previousRotation + 360) % 360;

				if ($rotationToApply !== 0 && isset($currentDocument['filename']))
				{
					$this->physically_rotate_image($currentDocument['filename'], $rotationToApply);
				}

				$metadata['rotation'] = $newRotation;
				$metadataUpdated = true;
			}

			if ($metadataUpdated)
			{
				$entity['metadata'] = $metadata;
			}

			return parent::update($entity);
		}

		private function physically_rotate_image($filePath, $degrees)
		{
			// Only rotate if degrees is valid
			if (!in_array($degrees, array(90, 180, 270)))
			{
				return false;
			}

			// Check if GD is available
			if (!extension_loaded('gd'))
			{
				return false;
			}

			// Get image info
			$imageInfo = getimagesize($filePath);
			if (!$imageInfo)
			{
				return false;
			}

			// Create image resource based on type
			$mime = $imageInfo['mime'];
			switch ($mime)
			{
				case 'image/jpeg':
					$source = imagecreatefromjpeg($filePath);
					break;
				case 'image/png':
					$source = imagecreatefrompng($filePath);
					break;
				case 'image/gif':
					$source = imagecreatefromgif($filePath);
					break;
				case 'image/webp':
					$source = imagecreatefromwebp($filePath);
					break;
				default:
					return false;
			}

			if (!$source)
			{
				return false;
			}

			// Rotate image (negative because imagerotate rotates counter-clockwise)
			$rotated = imagerotate($source, -$degrees, 0);
			imagedestroy($source);

			if (!$rotated)
			{
				return false;
			}

			// Save back to the same file
			$success = false;
			switch ($mime)
			{
				case 'image/jpeg':
					$success = imagejpeg($rotated, $filePath, 90);
					break;
				case 'image/png':
					imagesavealpha($rotated, true);
					$success = imagepng($rotated, $filePath);
					break;
				case 'image/gif':
					$success = imagegif($rotated, $filePath);
					break;
				case 'image/webp':
					$success = imagewebp($rotated, $filePath, 90);
					break;
			}

			imagedestroy($rotated);

			return $success;
		}
	}