<?php

use App\modules\phpgwapi\services\Settings;

phpgw::import_class('booking.socommon');

	abstract class booking_sodocument extends booking_socommon
	{

		const CATEGORY_HMS_DOCUMENT = 'HMS_document';
		const CATEGORY_PRICE_LIST = 'price_list';
		const CATEGORY_PICTURE_MAIN = 'picture_main';
		const CATEGORY_PICTURE = 'picture';
		const CATEGORY_DRAWING = 'drawing';
		const CATEGORY_REGULATION = 'regulation';
		const CATEGORY_OTHER = 'other';

		protected
			$defaultCategories = array(
				self::CATEGORY_HMS_DOCUMENT,
				self::CATEGORY_PRICE_LIST,
				self::CATEGORY_PICTURE_MAIN,
				self::CATEGORY_PICTURE,
				self::CATEGORY_DRAWING,
				self::CATEGORY_REGULATION,
				self::CATEGORY_OTHER,
				),
			$uploadRootDir,
			$ownerType = null;
		protected static
			$document_owners = array(
			'building',
			'resource',
			'application'
		);

		var $newFile;

		function __construct()
		{
			$flags = Settings::getInstance()->get('flags');

			$this->ownerType = substr(get_class($this), 19);

			$fields = array(
				'id' => array('type' => 'int'),
				'name' => array('type' => 'string', 'query' => true),
				'owner_id' => array('type' => 'int', 'required' => true),
				'category' => array('type' => 'string', 'required' => true),
				'description' => array('type' => 'string', 'required' => false),
			);

			if ($this->get_owner_type() != 'application')
			{
				$fields['owner_name'] = array(
					'type' => 'string',
					'query' => true,
					'join' => array(
						'table' => sprintf('bb_%s', $this->get_owner_type()),
						'fkey' => 'owner_id',
						'key' => 'id',
						'column' => 'name'
					)
				);
			}
			else if($this->get_owner_type() == 'application' && $flags['currentapp'] == 'bookingfrontend')
			{
				$fields['secret'] = array(
					'type' => 'string',
					'query' => true,
					'join' => array(
						'table' => sprintf('bb_%s', $this->get_owner_type()),
						'fkey' => 'owner_id',
						'key' => 'id',
						'column' => 'secret'
					)
				);
			}

			parent::__construct(sprintf('bb_document_%s', $this->get_owner_type()), $fields);
			$this->account = $this->userSettings['account_id'];

			$server_files_dir = $this->_chomp_dir_sep($this->serverSettings['files_dir']);

			if (!file_exists($server_files_dir) || !is_dir($server_files_dir))
			{
				throw new LogicException('The upload directory is not properly configured: ' . $server_files_dir);
			}

			if (!is_writable($server_files_dir))
			{
				throw new LogicException('The upload directory is not writable');
			}

			$this->uploadRootDir = $server_files_dir . DIRECTORY_SEPARATOR . 'booking';
		}

		public static function get_document_owners()
		{
			return self::$document_owners;
		}

		public function get_categories()
		{
			return $this->defaultCategories;
		}

		public function get_owner_type()
		{
			return $this->ownerType;
		}

		public function get_files_root()
		{
			return $this->uploadRootDir;
		}

		public function get_files_path()
		{
			return self::get_files_root() . DIRECTORY_SEPARATOR . $this->get_owner_type();
		}

		private function _chomp_dir_sep( $string )
		{
			$sep = DIRECTORY_SEPARATOR == '/' ? '\\/' : preg_quote(DIRECTORY_SEPARATOR);
			return preg_replace('/(' . $sep . ')+$/', '', trim($string));
		}

		public function generate_filename( $document_id, $document_name )
		{
			return $this->get_files_path() . DIRECTORY_SEPARATOR . $document_id . '_' . $document_name;
		}

		function read_single( $id )
		{
			$document = parent::read_single($id);
			if (is_array($document))
			{
				$document['filename'] = $this->generate_filename($document['id'], $document['name']);
			}
			return $document;
		}

		public function read_parent( $owner_id )
		{
			$parent_so = CreateObject(sprintf('booking.so%s', $this->get_owner_type()));
			return $parent_so->read_single($owner_id);
		}

		protected function doValidate( $document, booking_errorstack $errors )
		{
			$this->newFile = null;

			if (!$document['id'])
			{
				$fileValidator = createObject('booking.sfValidatorFile');
				$files = $document['files'];
				unset($document['files']);
				try
				{
					if ($this->newFile = $fileValidator->clean($files['name']))
					{
						$document['name'] = $this->newFile->getOriginalName();
					}
				}
				catch (sfValidatorError $e)
				{
					if ($e->getCode() == 'required')
					{
						$errors['name'] = lang('Missing file for document');
						return;
					}
					throw $e;
				}

				$finfo = finfo_open(FILEINFO_MIME_TYPE); // return mime type ala mimetype extension

				foreach (array_keys($files) as $key)
				{
					$_mime_type	 = $files[$key]['type'];
					$_tmp_name	 = $files[$key]['tmp_name'];
				}

				/* get mime-type for a specific file */
				$mime_type = finfo_file($finfo, $_tmp_name);
				/* close connection */
				finfo_close($finfo);

				if ($_mime_type !== $mime_type)
				{
					$errors['name'] = lang('filetype %1 is spoofed as %2', $mime_type, $_mime_type);
				}
			}

			if (!in_array($document['category'], $this->defaultCategories))
			{
				$errors['category'] = lang('Invalid category');
			}

			if (!preg_match('/(jpg|jpeg|png|gif|xls|xlsx|doc|docx|txt|pdf|odt|ods)$/i', $document['name']))
			{
				$errors['name'] = lang('Not a valid filetype');
			}

		}

		function add( $document )
		{
			if (!$this->newFile)
			{
				throw new LogicException('Missing file');
			}

			$this->db->transaction_begin();

			$document['name'] = $this->newFile->getOriginalName();
			$receipt = parent::add($document);

			$filePath = $this->generate_filename($receipt['id'], $document['name']);
			$this->newFile->save($filePath);

			// make sure that uploaded images are "web friendly"
			// automatically resize pictures that are too big
			if (preg_match('/(jpg|jpeg|gif|bmp|png)$/i', $this->newFile->getOriginalName()))
			{
				$imagetype = exif_imagetype($filePath);

				if(!in_array($imagetype, array(IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP)))
				{
					throw new LogicException('File is not a valid image');
				}

				$config = CreateObject('phpgwapi.config', 'booking');
				$config->read();
				$image_maxwidth = isset($config->config_data['image_maxwidth']) && $config->config_data['image_maxwidth'] ? $config->config_data['image_maxwidth'] : 300;
				$image_maxheight = isset($config->config_data['image_maxheight']) && $config->config_data['image_maxheight'] ? $config->config_data['image_maxheight'] : 300;

				$this->resize_image($filePath, $filePath, $image_maxheight);
//				$thumb = new Imagick($filePath);
//				$thumb->resizeImage($image_maxwidth, $image_maxheight, Imagick::FILTER_LANCZOS, 1, true);
//				$thumb->writeImage($filePath);
//				$thumb->clear();
//				$thumb->destroy();
			}

			if ($this->db->transaction_commit())
			{
				return $receipt;
			}

			throw new UnexpectedValueException('Transaction failed.');
		}

		/**
		 * Resize image using GD
		 * @param string $source
		 * @param string $dest
		 * @param int $target_height
		 */
		function resize_image( $source, $dest, $target_height = 800 )
		{
			$imgInfo = getimagesize($source);

			$width = $imgInfo[0];
			$height = $imgInfo[1];

			$target_width = round($width * ($target_height / $height));

			$x = 0;
			$y = 0;

			$new_im = ImageCreatetruecolor($target_width, $target_height);

			if ($imgInfo[2] == IMAGETYPE_JPEG)
			{
				$im = imagecreatefromjpeg($source);
				imagecopyresampled($new_im, $im, 0, 0, $x, $y, $target_width, $target_height, $width, $height);
				imagejpeg($new_im, $dest, 95); // Thumbnail quality (Value from 1 to 100)
			}
			else if ($imgInfo[2] == IMAGETYPE_GIF)
			{
				$im = imagecreatefromgif($source);
				imagecopyresampled($new_im, $im, 0, 0, $x, $y, $target_width, $target_height, $width, $height);
				imagegif($new_im, $dest);
			}
			else if ($imgInfo[2] == IMAGETYPE_PNG)
			{
				$im = imagecreatefrompng($source);
				imagecopyresampled($new_im, $im, 0, 0, $x, $y, $target_width, $target_height, $width, $height);
				imagepng($new_im, $dest);
			}
			else if ($imgInfo[2] == IMAGETYPE_BMP)
			{
				$im = imagecreatefrombmp($source);
				imagecopyresampled($new_im, $im, 0, 0, $x, $y, $target_width, $target_height, $width, $height);
				imagebmp($new_im, $dest);
			}
		}


		function delete( $id )
		{
			if (!is_array($document = $this->read_single($id)))
			{
				return false;
			}

			$this->db->transaction_begin();

			parent::delete($id);

			if ($this->db->transaction_commit())
			{
				if (file_exists($document['filename']))
				{
					unlink($document['filename']);
				}
				return true;
			}

			return false;
		}

		function has_results( &$result )
		{
			return is_array($result) && isset($result['total_records']) && $result['total_records'] > 0 && isset($result['results']);
		}

		function read( $params )
		{
			$result = parent::read($params);

			if ($this->has_results($result))
			{
				foreach ($result['results'] as &$record)
				{
					$record['is_image'] = $this->is_image($record);
				}
			}

			return $result;
		}

		public function is_image( array &$entity )
		{
			if (!in_array($entity['category'], array(self::CATEGORY_PICTURE_MAIN,self::CATEGORY_PICTURE)))
			{
				return false;
			}

			switch (strtolower($this->get_file_extension($entity)))
			{
				case 'png':
				case 'gif':
				case 'jpg':
				case 'jpeg':
					return true;
			}

			return false;
		}

		public function read_images( $params = array() )
		{
			if (!isset($params['filters']))
			{
				$params['filters'] = array();
			}
			$params['filters']['category'] = array(booking_sodocument::CATEGORY_PICTURE_MAIN, booking_sodocument::CATEGORY_PICTURE);

			$documents = $this->read($params);
			$images = array('results' => array(), 'total_records' => 0);
			foreach ($documents['results'] as &$document)
			{
				if ($document['is_image'])
				{
					$images['results'][] = $document;
					$images['total_records'] ++;
				}
			}

			return $images;
		}

		public function get_file_extension( array &$entity )
		{
			return (false === $pos = strrpos($entity['name'], '.')) ? false : substr($entity['name'], $pos + 1);
		}
	}