<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2007,2008 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.fsf.org/licenses/gpl.html GNU General Public License
 * @package phpgroupware
 * @subpackage property
 * @category core
 * @version $Id$
 */
/*
	  This program is free software: you can redistribute it and/or modify
	  it under the terms of the GNU General Public License as published by
	  the Free Software Foundation, either version 2 of the License, or
	  (at your option) any later version.

	  This program is distributed in the hope that it will be useful,
	  but WITHOUT ANY WARRANTY; without even the implied warranty of
	  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	  GNU General Public License for more details.

	  You should have received a copy of the GNU General Public License
	  along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;

/*
	 * phpGroupWare::property file handler class
	 *
	 * @package phpgroupware
	 * @subpackage property
	 * @category core
	 */

class property_bofiles
{

	/**
	 * @var string $fakebase Fake base directory.
	 */
	var $fakebase = '/property';
	var $rootdir;
	var $vfs, $flags, $userSettings, $serverSettings;

	/**
	 * constructor
	 *
	 * @param string $fakebase fakebase
	 *
	 * @return
	 */
	function __construct($fakebase = '/property')
	{
		$this->flags = Settings::getInstance()->get('flags');
		$this->userSettings = Settings::getInstance()->get('user');
		$this->serverSettings = Settings::getInstance()->get('server');

		$this->vfs		 = CreateObject('phpgwapi.vfs');
		$this->rootdir	 = $this->vfs->basedir;
		if ($fakebase)
		{
			$this->fakebase = $fakebase;
		}
		$this->vfs->fakebase = $this->fakebase;
	}

	/**
	 * Set the account id used for cron jobs where there is no user-session
	 *
	 * @param integer $account_id the account id to use - 0 = current user
	 *
	 * @return null
	 */
	public function set_account_id($account_id = 0)
	{
		if ($account_id)
		{
			$this->vfs->working_id = $account_id;
		}
	}

	/**
	 * Create catalog - starting with fakebase
	 *
	 * @param string $type part of path pointing to end target
	 *
	 * @return array Array with result on the action(failed/success) for each catalog down the path
	 */
	function create_document_dir($type)
	{
		$receipt = array();

		if (!$this->vfs->file_exists(array(
			'string'	 => $this->fakebase,
			'relatives'	 => array(RELATIVE_NONE)
		)))
		{
			$this->vfs->override_acl = 1;
			if (!$this->vfs->mkdir(array(
				'string'	 => $this->fakebase,
				'relatives'	 => array(
					RELATIVE_NONE
				)
			)))
			{
				$receipt['error'][] = array('msg' => lang('failed to create directory') . ' :' . $this->fakebase);
			}
			else
			{
				$receipt['message'][] = array('msg' => lang('directory created') . ' :' . $this->fakebase);
			}
			$this->vfs->override_acl = 0;
		}

		$type_part = explode('/', $type);

		$catalog = '';
		foreach ($type_part as $entry)
		{
			$catalog .= "/{$entry}";

			if (!$this->vfs->file_exists(array(
				'string'	 => "{$this->fakebase}{$catalog}",
				'relatives'	 => array(RELATIVE_NONE)
			)))
			{
				$this->vfs->override_acl = 1;
				if (!$this->vfs->mkdir(array(
					'string'	 => "{$this->fakebase}{$catalog}",
					'relatives'	 => array(
						RELATIVE_NONE
					)
				)))
				{
					$receipt['error'][] = array('msg' => lang('failed to create directory') . ' :' . $this->fakebase . $catalog);
				}
				else
				{
					$receipt['message'][] = array('msg' => lang('directory created') . ' :' . $this->fakebase . $catalog);
				}
				$this->vfs->override_acl = 0;
			}
		}
		//_debug_array($receipt);
		return $receipt;
	}

	/**
	 * Delete Files
	 *
	 * @param string $path   part of path where to look for files
	 * @param array  $values array holding information of selected files
	 *
	 * @return array Array with result on the action(failed/success) for each file
	 */
	function delete_file($path, $values)
	{
		$receipt = array();

		foreach ($values['file_action'] as $file_id)
		{
			$file_info = $this->vfs->get_info($file_id);

			$check_path = trim("{$this->fakebase}{$path}", "/");

			$file = "{$file_info['directory']}/{$file_info['name']}";

			if ($check_path != trim($file_info['directory'], "/"))
			{
				Cache::message_set("deleting file from wrong location", 'error');
				return false;
			}

			if ($this->vfs->file_exists(array(
				'string'	 => $file,
				'relatives'	 => array(RELATIVE_NONE)
			)))
			{
				$this->vfs->override_acl = 1;

				if (!$this->vfs->rm(array(
					'string'	 => $file,
					'relatives'	 => array(
						RELATIVE_NONE
					)
				)))
				{
					Cache::message_set(lang('failed to delete file') . ' :' . $file, 'error');
				}
				else
				{
					Cache::message_set(lang('file deleted') . ' :' . $file, 'message');
				}
				$this->vfs->override_acl = 0;
			}
		}
		return $receipt;
	}


	function set_tags($ids, $tags)
	{
		if (!is_array($ids))
		{
			$ids = array($ids);
		}
		return $this->vfs->set_tags($ids, $tags);
	}

	function remove_tags($ids, $tags)
	{
		if (!is_array($ids))
		{
			$ids = array($ids);
		}
		return $this->vfs->remove_tags($ids, $tags);
	}


	function get_all_tags()
	{
		return $this->vfs->get_all_tags();
	}

	/**
	 * View File - echo (or download) to browser.
	 *
	 * @param intenger $file_id
	 *
	 * @return null
	 */
	function get_file($file_id, $jasper = false)
	{
		$this->flags['noheader']	 = true;
		$this->flags['nofooter']	 = true;
		$this->flags['xslt_app']	 = false;
		Settings::getInstance()->set('flags', $this->flags);


		if (!$jasper)
		{
			$this->vfs->override_acl = 1;

			$file_info	 = $this->vfs->get_info($file_id);

			$this->vfs->override_acl = 0;

			$file_source		 = "{$this->rootdir}{$file_info['directory']}/{$file_info['name']}";

			$filesize = filesize($file_source);
			$browser = CreateObject('phpgwapi.browser');
			$browser->content_header($file_info['name'], $file_info['mime_type'], $filesize);

			$this->readfile_chunked($file_source);
		}
		else //Execute the jasper report
		{
			$output_type = 'PDF';
			$file_info	 = $this->vfs->get_info($file_id);
			$file		 = "{$file_info['directory']}/{$file_info['name']}";

			$report_source	 = "{$this->rootdir}{$file}";
			$jasper_wrapper	 = CreateObject('phpgwapi.jasper_wrapper');
			try
			{
				$jasper_wrapper->execute('', $output_type, $report_source);
			}
			catch (Exception $e)
			{
				$error = $e->getMessage();
				//FIXME Do something clever with the error
				echo "<H1>{$error}</H1>";
			}
		}
	}



	/**
	 * Read a file and display its content chunk by chunk
	 * @param string $filename
	 * @param bool $retbytes
	 * @return boolean
	 */
	function readfile_chunked($filename, $retbytes = true)
	{
		$chunk_size = 1024 * 1024;

		$buffer	 = '';
		$cnt	 = 0;
		$handle	 = fopen($filename, 'rb');

		if ($handle === false)
		{
			return false;
		}

		while (!feof($handle))
		{
			$buffer = fread($handle, $chunk_size);
			echo $buffer;
			ob_flush();
			flush();

			if ($retbytes)
			{
				$cnt += strlen($buffer);
			}
		}

		$status = fclose($handle);

		if ($retbytes && $status)
		{
			return $cnt; // return num. bytes delivered like readfile() does.
		}

		return $status;
	}

	/**
	 * View File - echo (or download) to browser.
	 *
	 * @param string $type part of path where to look for files
	 * @param string $file optional filename
	 *
	 * @return null
	 */
	function view_file($type = '', $file = '', $jasper = '')
	{
		$this->flags['noheader']	 = true;
		$this->flags['nofooter']	 = true;
		$this->flags['xslt_app']	 = false;
		Settings::getInstance()->set('flags', $this->flags);

		if (!$file)
		{
			$file_name	 = html_entity_decode(urldecode(Sanitizer::get_var('file_name')));
			$file_name	 = $this->strip_entities_from_name($file_name);
			$id			 = Sanitizer::get_var('id');
			$file		 = "{$this->fakebase}/{$type}/{$id}/{$file_name}";
		}

		// prevent path traversal
		if (preg_match('/\.\./', $file))
		{
			return false;
		}

		if ($this->vfs->file_exists(array(
			'string'	 => $file,
			'relatives'	 => array(RELATIVE_NONE)
		)))
		{
			$ls_array = $this->vfs->ls(array(
				'string'		 => $file,
				'relatives'		 => array(RELATIVE_NONE),
				'checksubdirs'	 => false,
				'nofiles'		 => true
			));

			if (!$jasper)
			{
				$this->vfs->override_acl = 1;

				$document = $this->vfs->read(array(
					'string'	 => $file,
					'relatives'	 => array(RELATIVE_NONE)
				));

				$this->vfs->override_acl = 0;

				$browser = CreateObject('phpgwapi.browser');
				$browser->content_header($ls_array[0]['name'], $ls_array[0]['mime_type'], $ls_array[0]['size']);
				echo $document;
			}
			else //Execute the jasper report
			{
				$output_type = 'PDF';

				$report_source	 = "{$this->rootdir}{$file}";
				$jasper_wrapper	 = CreateObject('phpgwapi.jasper_wrapper');
				try
				{
					$jasper_wrapper->execute('', $output_type, $report_source);
				}
				catch (Exception $e)
				{
					$error = $e->getMessage();
					//FIXME Do something clever with the error
					echo "<H1>{$error}</H1>";
				}
			}
		}
	}

	/**
	 * Get attachments
	 *
	 * @param string $path   part of path where to look for files
	 * @param array  $values array holding information of selected files
	 *
	 * @return array Array with filecontent
	 */
	function get_attachments($values)
	{
		$attachments = array();
		foreach ($values as $file_id)
		{
			$file_info = $this->vfs->get_info($file_id);

			$file = "{$file_info['directory']}/{$file_info['name']}";

			$attachments[] = array(
				'file'	 => "{$this->serverSettings['files_dir']}{$file}",
				'name'	 => $file_info['name'],
				'type'	 => $file_info['mime_type']
			);
		}
		return $attachments;
	}

	function strip_entities_from_name($file_name)
	{
		return str_replace(array('&#40;', '&#41;', '&#8722;&#8722;'), array(
			'(',
			')',
			'--'
		), $file_name);
	}

	function convert_heic_to_jpg($source)
	{
		require_once PHPGW_API_INC . '/heic_to_jpg/vendor/autoload.php';
		$dest = str_ireplace('.heic', '.jpg', $source);
		Maestroerror\HeicToJpg::convert($source)->saveAs($dest);
		return $dest;
	}

	function resize_image($source, $dest, $target_height = 800)
	{
		if (preg_match('/\.heic$/i', $source))
		{
			//			$source = $this->convert_heic_to_jpg($source);
		}

		$imgInfo = getimagesize($source);

		$width = $imgInfo[0];
		$height = $imgInfo[1];

		$target_width = round($width * ($target_height / $height));

		$x = 0;
		$y = 0;

		//			if ($width > $height)
		//			{
		//				$x = ceil(($width - $height) / 2);
		//				$width = $height;
		//			}
		//			else if ($height > $width)
		//			{
		//				$y = ceil(($height - $width) / 2);
		//				$height = $width;
		//			}

		$new_im = ImageCreatetruecolor($target_width, $target_height);

		if ($imgInfo[2] == IMAGETYPE_JPEG)
		{
			$im = imagecreatefromjpeg($source);
			if ($im)
			{
				imagecopyresampled($new_im, $im, 0, 0, $x, $y, $target_width, $target_height, $width, $height);
				imagejpeg($new_im, $dest, 95); // Thumbnail quality (Value from 1 to 100)
			}
		}
		else if ($imgInfo[2] == IMAGETYPE_GIF)
		{
			$im = imagecreatefromgif($source);
			if ($im)
			{
				imagecopyresampled($new_im, $im, 0, 0, $x, $y, $target_width, $target_height, $width, $height);
				imagegif($new_im, $dest);
			}
		}
		else if ($imgInfo[2] == IMAGETYPE_PNG)
		{
			$im = imagecreatefrompng($source);
			if ($im)
			{
				imagecopyresampled($new_im, $im, 0, 0, $x, $y, $target_width, $target_height, $width, $height);
				imagepng($new_im, $dest);
			}
		}
	}

	function is_image($fileName)
	{
		// Verifies that a file is an image
		if ($fileName !== '.' && $fileName !== '..')
		{
			$imgInfo =  exif_imagetype($fileName);

			$imgType = array(
				IMAGETYPE_JPEG,
				IMAGETYPE_GIF,
				IMAGETYPE_PNG,
			);

			if (in_array($imgInfo, $imgType))
			{
				return true;
			}

			if (!$imgInfo && preg_match('/\.heic$/i', $fileName))
			{
				// Create a new Imagick object
				//		$image = new Imagick($fileName);
				// Get the image format
				//		$imageFormat = $image->getImageFormat();
				//		if($imageFormat === 'HEIC')
				{
					//			return true;
				}
			}

			return false;
		}
	}
}
