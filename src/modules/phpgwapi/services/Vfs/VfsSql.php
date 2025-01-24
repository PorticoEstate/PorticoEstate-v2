<?php

/**
 * Virtual File System with SQL backend
 * @author Jason Wies <zone@phpgroupware.org>
 * @author Giancarlo Susin
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright(C) 2001 Jason Wies
 * @copyright Copyright(C) 2004,2014 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.fsf.org/licenses/lgpl.html GNU Lesser General Public License
 * @package phpgwapi
 * @subpackage vfs
 * @version $Id$
 */

namespace App\modules\phpgwapi\services\vfs;

use App\modules\phpgwapi\services\Vfs\VfsFileoperationFilesystem;
use App\modules\phpgwapi\services\Log;
use App\modules\phpgwapi\security\Acl;
use App\Database\Db;
use App\Database\Db2;
use PDO;
use Exception;

/**
 * VFS SQL select
 * @see extra_sql()
 */
define('VFS_SQL_SELECT', 1);
/**
 * VFS SQL delete
 * @see extra_sql()
 */
define('VFS_SQL_DELETE', 2);
/**
 * VFS SQL update
 * @see extra_sql()
 */
define('VFS_SQL_UPDATE', 4);


/**
 * Virtual File System with SQL backend
 *
 * @package phpgwapi
 * @subpackage vfs
 * @ignore
 */
class Vfs extends VfsShared
{
	var $file_actions;
	var $acl_default, $db, $db2, $fileoperation, $log;

	/**
	 * constructor, sets up variables
	 *
	 */
	function __construct()
	{
		parent::__construct();
		/*
			   File/dir attributes, each corresponding to a database field.  Useful for use in loops
			   If an attribute was added to the table, add it here and possibly add it to
			   set_attributes()

			   set_attributes now uses this array().   07-Dec-01 skeeter
			*/

		$this->db = Db::getInstance();

		//create a new pdo  $this->db2 of the database based on the configuration
		$this->db2 = new Db2();

		$this->log = new Log();

		$this->attributes[] = 'deleteable';
		$this->attributes[] = 'content';

		/*
			   Decide whether to use any actual filesystem calls(fopen(), fread(),
			   unlink(), rmdir(), touch(), etc.).  If not, then we're working completely
			   in the database.
			*/

		if (isset($this->serverSettings['file_store_contents']) && $this->serverSettings['file_store_contents'])
		{
			$file_store_contents = $this->serverSettings['file_store_contents'];
		}
		else
		{
			$file_store_contents = 'filesystem';
		}

		switch ($file_store_contents)
		{
			case 'braArkiv':
			case 'filesystem':
				$this->file_actions = 1;
				break;
			default:
				$this->file_actions = 0;
				break;
		}

		if ($this->file_actions)
		{
			$this->fileoperation = new VfsFileoperationFilesystem();
		}

		$this->acl_default = $this->serverSettings['acl_default'];

		// test if the files-dir is inside the document-root, and refuse working if so
		//
		if ($this->file_actions && $this->in_docroot($this->basedir))
		{
			$phpgwapi_common = new \phpgwapi_common();
			$phpgwapi_common->phpgw_header();
			if ($this->flags['noheader'])
			{
				echo parse_navbar();
			}
			echo '<p align="center"><font color="red"><b>' . lang('Path to user and group files HAS TO BE OUTSIDE of the webservers document-root!!!') . "</b></font></p>\n";
			$phpgwapi_common->phpgw_exit();
		}

		$params = [];
		$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_SELECT));
		$sql = "SELECT directory, name, link_directory, link_name FROM phpgw_vfs WHERE ";

		if (in_array($this->serverSettings['db_type'], array('mssql', 'mssqlnative', 'sqlsrv', 'sybase')))
		{
			$sql .= "(CONVERT(varchar,link_directory) != '' AND CONVERT(varchar,link_name) != '')";
		}
		else
		{
			$sql .= "(link_directory IS NOT NULL or link_directory != '') AND (link_name IS NOT NULL or link_name != '')";
		}

		$sql .= $extraSql['sql'];

		$stmt = $this->db->prepare($sql);
		$params = array_merge($params, $extraSql['params']);

		$stmt->execute($params);

		$this->linked_dirs = array();
		while ($row = $stmt->fetch())
		{
			$this->linked_dirs[] = $row;
		}
	}

	/**
	 * test if $path lies within the webservers document-root
	 *
	 */
	function in_docroot($path)
	{
		//$docroots = array(PHPGW_SERVER_ROOT, $_SERVER['DOCUMENT_ROOT']);
		$docroots = array(SRC_ROOT_PATH);
		//in case vfs is called from cli(cron-job)

		if ($_SERVER['DOCUMENT_ROOT'])
		{
			$docroots[] = $_SERVER['DOCUMENT_ROOT'];
		}

		foreach ($docroots as $docroot)
		{
			$len = strlen($docroot);

			if ($docroot == substr($path, 0, $len))
			{
				$rest = substr($path, $len);

				if (!strlen($rest) || $rest[0] == DIRECTORY_SEPARATOR)
				{
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Return extra SQL code that should be appended to certain queries
	 *
	 * @param query_type The type of query to get extra SQL code for, in the form of a VFS_SQL define
	 * @return Extra SQL code
	 */
	function extra_sql($data)
	{
		if (!is_array($data))
		{
			$data = array('query_type' => VFS_SQL_SELECT);
		}

		$sql = '';
		$params = [];

		if ($data['query_type'] == VFS_SQL_SELECT || $data['query_type'] == VFS_SQL_DELETE || $data['query_type'] == VFS_SQL_UPDATE)
		{
			$sql = ' AND((';

			if (is_array($this->meta_types))
			{
				$conditions = [];
				foreach ($this->meta_types as $num => $type)
				{
					$conditions[] = "mime_type != :type{$num}";
					$params[":type{$num}"] = $type;
				}
				$sql .= implode(' AND ', $conditions);
			}
			$sql .= ') OR mime_type IS NULL)';
		}

		return ['sql' => $sql, 'params' => $params];
	}
	/**
	 * Add a journal entry after(or before) completing an operation,
	 *
	 * 	  and increment the version number.  This function should be used internally only
	 * Note that state_one and state_two are ignored for some VFS_OPERATION's, for others
	 * 		 * they are required.  They are ignored for any "custom" operation
	 * 		 * The two operations that require state_two:
	 * 		 * operation		 * 	state_two
	 * 		 * VFS_OPERATION_COPIED	fake_full_path of copied to
	 * 		 * VFS_OPERATION_MOVED		 * fake_full_path of moved to

	 * 		 * If deleting, you must call add_journal() before you delete the entry from the database
	 * @param string File or directory to add entry for
	 * @param relatives Relativity array
	 * @param operation The operation that was performed.  Either a VFS_OPERATION define or
	 * 		 *   a non-integer descriptive text string
	 * @param state_one The first "state" of the file or directory.  Can be a file name, size,
	 * 		 *   location, whatever is appropriate for the specific operation
	 * @param state_two The second "state" of the file or directory
	 * @param incversion Boolean True/False.  Increment the version for the file?  Note that this is
	 * 		 *    handled automatically for the VFS_OPERATION defines.
	 * 		 *    i.e. VFS_OPERATION_EDITED would increment the version, VFS_OPERATION_COPIED
	 * 		 *    would not
	 * @return Boolean True/False
	 */
	function add_journal($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'		=> array(RELATIVE_CURRENT),
			'state_one'		=> false,
			'state_two'		=> false,
			'incversion'	=> true
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$account_id = $this->userSettings['account_id'];

		$p = $this->path_parts(array('string' => $data['string'], 'relatives' => array($data['relatives'][0])));

		/* We check that they have some sort of access to the file other than read */
		if (
			!$this->acl_check(array('string' => $p->fake_full_path, 'relatives' => array($p->mask), 'operation' => ACL_ADD)) &&
			!$this->acl_check(array('string' => $p->fake_full_path, 'relatives' => array($p->mask), 'operation' => ACL_EDIT)) &&
			!$this->acl_check(array('string' => $p->fake_full_path, 'relatives' => array($p->mask), 'operation' => ACL_DELETE))
		)
		{
			return false;
		}

		if (!$this->file_exists(array('string' => $p->fake_full_path, 'relatives' => array($p->mask))))
		{
			return false;
		}

		$ls_array = $this->ls(
			array(
				'string'		=> $p->fake_full_path,
				'relatives'		=> array($p->mask),
				'checksubdirs'	=> false,
				'mime_type'		=> false,
				'nofiles'		=> true
			)
		);
		$file_array = $ls_array[0];

		$sql = 'INSERT INTO phpgw_vfs(';
		$sql2 = ' VALUES(';
		$morethanone = false;
		$modified = false;

		//for($i = 0; list($attribute, $value) = each($file_array); $i++)
		foreach ($file_array as $attribute => $value)
		{
			if ($attribute == 'file_id' || $attribute == 'content')
			{
				continue;
			}

			if ($attribute == 'owner_id')
			{
				$value = $account_id;
			}

			if ($attribute == 'created')
			{
				$value = $this->now;
			}

			if ($attribute == 'modified' && !$modified)
			{
				unset($value);
			}

			if ($attribute == 'mime_type')
			{
				$value = 'journal';
			}

			if ($attribute == 'comment')
			{
				switch ($data['operation'])
				{
					case VFS_OPERATION_CREATED:
						$value = 'Created';
						$data['incversion'] = true;
						break;
					case VFS_OPERATION_EDITED:
						$value = 'Edited';
						$data['incversion'] = true;
						break;
					case VFS_OPERATION_EDITED_COMMENT:
						$value = 'Edited comment';
						$data['incversion'] = false;
						break;
					case VFS_OPERATION_COPIED:
						if (!$data['state_one'])
						{
							$data['state_one'] = $p->fake_full_path;
						}
						if (!$data['state_two'])
						{
							return false;
						}
						$value = "Copied {$data['state_one']} to {$data['state_two']}";
						$data['incversion'] = false;
						break;
					case VFS_OPERATION_MOVED:
						if (!$data['state_one'])
						{
							$data['state_one'] = $p->fake_full_path;
						}
						if (!$data['state_two'])
						{
							return false;
						}
						$value = "Moved {$data['state_one']} to {$data['state_two']}";
						$data['incversion'] = false;
						break;
					case VFS_OPERATION_DELETED:
						$value = 'Deleted';
						$data['incversion'] = false;
						break;
					default:
						$value = $data['operation'];
						break;
				}
			}

			/*
				   Let's increment the version for the file itself.  We keep the current
				   version when making the journal entry, because that was the version that
				   was operated on.  The maximum numbers for each part in the version string:
				   none.99.9.9
				*/
			if ($attribute == 'version' && $data['incversion'])
			{
				$version_parts = explode(".", $value);
				$newnumofparts = $numofparts = count($version_parts);

				if ($version_parts[3] >= 9)
				{
					$version_parts[3] = 0;
					$version_parts[2]++;
					$version_parts_3_update = 1;
				}
				else if (isset($version_parts[3]))
				{
					$version_parts[3]++;
				}

				if ($version_parts[2] >= 9 && $version_parts[3] == 0 && $version_parts_3_update)
				{
					$version_parts[2] = 0;
					$version_parts[1]++;
				}

				if ($version_parts[1] > 99)
				{
					$version_parts[1] = 0;
					$version_parts[0]++;
				}
				$newversion = '';
				for ($j = 0; $j < $newnumofparts; $j++)
				{
					if (!isset($version_parts[$j]))
					{
						break;
					}

					if ($j)
					{
						$newversion .= '.';
					}

					$newversion .= $version_parts[$j];
				}

				$this->set_attributes(
					array(
						'string'		=> $p->fake_full_path,
						'relatives'		=> array($p->mask),
						'attributes'	=> array(
							'version' => $newversion
						)
					)
				);
			}
			if (isset($value) && !empty($value))
			{
				if ($morethanone)
				{
					$sql .= ', ';
					$sql2 .= ', ';
				}
				else
				{
					$morethanone = true;
				}
				$sql .= "$attribute";
				$sql2 .= "'" . $this->clean_string(array('string' => $value)) . "'";
			}
		}
		unset($morethanone);
		$sql .= ')';
		$sql2 .= ')';

		$sql .= $sql2;

		/*
			   These are some special situations where we need to flush the journal entries
			   or move the 'journal' entries to 'journal-deleted'.  Kind of hackish, but they
			   provide a consistent feel to the system
			*/
		$flush_path = '';
		if ($data['operation'] == VFS_OPERATION_CREATED)
		{
			$flush_path = $p->fake_full_path;
			$deleteall = true;
		}

		if ($data['operation'] == VFS_OPERATION_COPIED || $data['operation'] == VFS_OPERATION_MOVED)
		{
			$flush_path = $data['state_two'];
			$deleteall = false;
		}

		if ($flush_path)
		{
			$flush_path_parts = $this->path_parts(
				array(
					'string'	=> $flush_path,
					'relatives'	=> array(RELATIVE_NONE)
				)
			);

			$this->flush_journal(
				array(
					'string'	=> $flush_path_parts->fake_full_path,
					'relatives'	=> array($flush_path_parts->mask),
					'deleteall'	=> $deleteall
				)
			);
		}

		if ($data['operation'] == VFS_OPERATION_COPIED)
		{
			/*
				   We copy it going the other way as well, so both files show the operation.
				   The code is a bad hack to prevent recursion.  Ideally it would use VFS_OPERATION_COPIED
				*/
			$this->add_journal(
				array(
					'string'	=> $data['state_two'],
					'relatives'	=> array(RELATIVE_NONE),
					'operation'	=> "Copied {$data['state_one']} to {$data['state_two']}",
					'state_one'	=> null,
					'state_two'	=> null,
					'incversion'	=> false
				)
			);
		}

		if ($data['operation'] == VFS_OPERATION_MOVED)
		{
			$state_one_path_parts = $this->path_parts(
				array(
					'string'	=> $data['state_one'],
					'relatives'	=> array(RELATIVE_NONE)
				)
			);

			$query = $this->db->query("UPDATE phpgw_vfs SET mime_type='journal-deleted'"
				. " WHERE directory='{$state_one_path_parts->fake_leading_dirs_clean}'"
				. " AND name='{$state_one_path_parts->fake_name_clean}' AND mime_type='journal'");

			/*
				   We create the file in addition to logging the MOVED operation.  This is an
				   advantage because we can now search for 'Create' to see when a file was created
				*/
			$this->add_journal(
				array(
					'string'	=> $data['state_two'],
					'relatives'	=> array(RELATIVE_NONE),
					'operation'	=> VFS_OPERATION_CREATED
				)
			);
		}

		/* This is the SQL query we made for THIS request, remember that one? */
		$query = $this->db->query($sql, __LINE__, __FILE__);

		/*
			   If we were to add an option of whether to keep journal entries for deleted files
			   or not, it would go in the if here
			*/
		if ($data['operation'] == VFS_OPERATION_DELETED)
		{
			$query = $this->db->query("UPDATE phpgw_vfs SET mime_type='journal-deleted'"
				. " WHERE directory='{$p->fake_leading_dirs_clean}' AND name='{$p->fake_name_clean}' AND mime_type='journal'");
		}

		return true;
	}

	/**
	 * Flush journal entries for $string.  Used before adding $string
	 *
	 * flush_journal() is an internal function and should be called from add_journal() only
	 * @param string File/directory to flush journal entries of
	 * @param relatives Realtivity array
	 * @param deleteall Delete all types of journal entries, including the active Create entry.
	 * 		 *   Normally you only want to delete the Create entry when replacing the file
	 * 		 *   Note that this option does not effect $deleteonly
	 * @param deletedonly Only flush 'journal-deleted' entries(created when $string was deleted)
	 * @return Boolean True/False
	 */
	function flush_journal($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'		=> array(RELATIVE_CURRENT),
			'deleteall'		=> false,
			'deletedonly'	=> false
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$p = $this->path_parts(
			array(
				'string'	=> $data['string'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		$sql = "DELETE FROM phpgw_vfs WHERE directory='{$p->fake_leading_dirs_clean}' AND name='{$p->fake_name_clean}'";

		if (!$data['deleteall'])
		{
			$sql .= " AND(mime_type != 'journal' AND comment != 'Created')";
		}

		$sql .= "  AND(mime_type='journal-deleted'";

		if (!$data['deletedonly'])
		{
			$sql .= " OR mime_type='journal'";
		}

		$sql .= ")";

		$query = $this->db->query($sql, __LINE__, __FILE__);

		if ($query)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/*
		 * See vfs_shared
		 */
	function get_journal($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT),
			'type'	=> false
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$p = $this->path_parts(
			array(
				'string'	=> $data['string'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		if (!$this->acl_check(array(
			'string' => $p->fake_full_path,
			'relatives' => array($p->mask)
		)))
		{
			return false;
		}

		$sql = "SELECT * FROM phpgw_vfs WHERE directory=:directory AND name=:name";

		$params = array(':directory' => $p->fake_leading_dirs_clean, ':name' => $p->fake_name_clean);

		if ($data['type'] == 1)
		{
			$sql .= " AND mime_type='journal'";
		}
		elseif ($data['type'] == 2)
		{
			$sql .= " AND mime_type='journal-deleted'";
		}
		else
		{
			$sql .= " AND(mime_type='journal' OR mime_type='journal-deleted')";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);

		$rarray = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$rarray[] = $this->Record($this->attributes, $row);
		}
		return $rarray;
	}

	/*
		 * See vfs_shared
		 */
	function acl_check($data)
	{
		//echo 'checking vfs_sql::acl_check(' . print_r($data, true) . '</pre>';
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT),
			'operation'	=> ACL_READ,
			'must_exist'	=> false
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		/* Accommodate special situations */
		if ($this->override_acl || $data['relatives'][0] == RELATIVE_USER_APP)
		{
			return true;
		}

		if (!isset($data['owner_id']) || !$data['owner_id'])
		{
			$p = $this->path_parts(
				array(
					'string'	=> $data['string'],
					'relatives'	=> array($data['relatives'][0])
				)
			);

			/* Temporary, until we get symlink type files set up */
			if ($p->outside)
			{
				return true;
			}

			/* Read access is always allowed here, but nothing else is */
			if ($data['string'] == '/' || $data['string'] == $this->fakebase)
			{
				if ($data['operation'] == ACL_READ)
				{
					return true;
				}
				else
				{
					return false;
				}
			}

			/* If the file doesn't exist, we get ownership from the parent directory */
			if (!$this->file_exists(array(
				'string'	=> $p->fake_full_path,
				'relatives'	=> array($p->mask)
			)))
			{
				if ($data['must_exist'])
				{
					return false;
				}

				$data['string'] = $p->fake_leading_dirs;
				$p2 = $this->path_parts(
					array(
						'string'	=> $data['string'],
						'relatives'	=> array($p->mask)
					)
				);

				if (!$this->file_exists(array(
					'string'	=> $data['string'],
					'relatives'	=> array($p->mask)
				)))
				{
					return false;
				}
			}
			else
			{
				$p2 = $p;
			}

			/*
				   We don't use ls() to get owner_id as we normally would,
				   because ls() calls acl_check(), which would create an infinite loop
				*/
			$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_SELECT));
			$sql = "SELECT owner_id FROM phpgw_vfs WHERE directory = :directory AND name = :name" . $extraSql['sql'];

			$stmt = $this->db->prepare($sql);
			$params = array(':directory' => $p2->fake_leading_dirs_clean, ':name' => $p2->fake_name_clean);
			$params = array_merge($params, $extraSql['params']);

			$stmt->execute($params);

			$record = $stmt->fetch();
			$owner_id = $record['owner_id'];
		}
		else
		{
			$owner_id = $data['owner_id'];
		}

		/* This is correct.  The ACL currently doesn't handle undefined values correctly */
		if (!$owner_id)
		{
			$owner_id = 0;
		}

		$user_id = $this->userSettings['account_id'];

		/* They always have access to their own files */
		if ($owner_id == $user_id)
		{
			return true;
		}

		$currentapp = $this->flags['currentapp'];
		return Acl::getInstance()->check('run', ACL_READ, $currentapp);
	}

	/*
		 * get file info by id
		 *
		 * @return Array of file information.
		 */
	function get_info($file_id)
	{
		$file_id = (int) $file_id;

		$attributes = $this->attributes;
		$attributes[] = 'tags';

		$sql = "SELECT tags, phpgw_vfs.* FROM phpgw_vfs LEFT JOIN phpgw_vfs_filetags ON phpgw_vfs.file_id = phpgw_vfs_filetags.file_id WHERE phpgw_vfs.file_id=:file_id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':file_id' => $file_id]);

		$values = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			foreach ($attributes as $attribute)
			{
				$values[$attribute] = $row[$attribute];
			}
		}
		return $values;
	}

	/*
		 * get file by id
		 *
		 * @return Array of file information and file content
		 */
	function get($file_id)
	{
		$file_info = $this->get_info($file_id);

		$file_info['content'] =  $this->read(
			array(
				'string' => "{$file_info['directory']}/{$file_info['name']}",
				'relatives' => array(RELATIVE_NONE)
			)
		);

		return $file_info;
	}
	/*
		 * See vfs_shared
		 */
	function read($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$p = $this->path_parts(
			array(
				'string'	=> $data['string'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		if (!$this->acl_check(array(
			'string'	=> $p->fake_full_path,
			'relatives'	=> array($p->mask),
			'operation'	=> ACL_READ
		)))
		{
			return false;
		}

		if ($this->file_actions || $p->outside)
		{
			if ($p->outside)
			{
				$contents = null;
				if ($filesize = filesize($p->real_full_path) > 0 && $fp = fopen($p->real_full_path, 'rb'))
				{
					$contents = fread($fp, $filesize);
					fclose($fp);
				}
			}
			else
			{
				$contents = $this->fileoperation->read($p);
			}
		}
		else
		{
			$ls_array = $this->ls(
				array(
					'string'	=> $p->fake_full_path,
					'relatives'	=> array($p->mask)
				)
			);

			$contents = $ls_array[0]['content'];
		}

		return $contents;
	}

	/*
		 * See vfs_shared
		 */
	function write($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT),
			'content'	=> ''
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$p = $this->path_parts(
			array(
				'string'	=> $data['string'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		if (!$this->fileoperation->check_target_directory($p))
		{
			$this->log->error(array(
				'text' => 'vfs::write() : missing target directory %1',
				'p1'   => $p->real_leading_dirs,
				'p2'	 => '',
				'line' => __LINE__,
				'file' => __FILE__
			));

			return false;
		}

		if ($this->file_exists(array(
			'string'	=> $p->fake_full_path,
			'relatives'	=> array($p->mask)
		)))
		{
			$acl_operation = ACL_EDIT;
			$journal_operation = VFS_OPERATION_EDITED;
		}
		else
		{
			$acl_operation = ACL_ADD;
		}

		if (!$this->acl_check(array(
			'string'	=> $p->fake_full_path,
			'relatives'	=> array($p->mask),
			'operation'	=> $acl_operation
		)))
		{
			return false;
		}

		umask(000);

		/*
			   If 'string' doesn't exist, touch() creates both the file and the database entry
			   If 'string' does exist, touch() sets the modification time and modified by
			*/
		$_document_id = $this->touch(
			array(
				'string'	=> $p->fake_full_path,
				'relatives'	=> array($p->mask)
			)
		);

		if ($this->file_actions)
		{
			$write_ok = $this->fileoperation->write($p, $data['content']);
		}

		if ($write_ok || !$this->file_actions)
		{
			if ($this->file_actions)
			{
				$set_attributes_array = array(
					'size'	=> strlen($data['content'])
				);
				if (isset($this->fileoperation->external_ref) && $this->fileoperation->external_ref)
				{
					$set_attributes_array['external_id'] = $_document_id;
				}
			}
			else
			{
				$set_attributes_array = array(
					'size'	=> strlen($data['content']),
					'content'	=> $data['content']
				);
			}

			$this->set_attributes(
				array(
					'string'	=> $p->fake_full_path,
					'relatives'	=> array($p->mask),
					'attributes'	=> $set_attributes_array
				)
			);

			if ($journal_operation)
			{
				$this->add_journal(
					array(
						'string'	=> $p->fake_full_path,
						'relatives'	=> array($p->mask),
						'operation'	=> $journal_operation
					)
				);
			}

			return true;
		}
		else
		{
			return false;
		}
	}

	/*
		 * See vfs_shared
		 */
	function touch($data, $p = array())
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$account_id = $this->userSettings['account_id'];
		$currentapp = $this->flags['currentapp'];

		if (!$p)
		{
			$p = $this->path_parts(
				array(
					'string'	=> $data['string'],
					'relatives'	=> array($data['relatives'][0])
				)
			);
		}

		umask(000);

		if ($this->file_actions)
		{
			/*
				   PHP's touch function will automatically decide whether to
				   create the file or set the modification time
				*/

			/* In case of $p->outside: touch on local disk */
			if ($p->outside)
			{
				return @touch($p->real_full_path);
			}
			else
			{
				$rr = $this->fileoperation->touch($p);
			}
		}

		/* We, however, have to decide this ourselves */
		if ($this->file_exists(array(
			'string'	=> $p->fake_full_path,
			'relatives'	=> array($p->mask)
		)))
		{
			if (!$this->acl_check(array(
				'string'	=> $p->fake_full_path,
				'relatives'	=> array($p->mask),
				'operation'	=> ACL_EDIT
			)))
			{
				return false;
			}

			$vr = $this->set_attributes(
				array(
					'string'		=> $p->fake_full_path,
					'relatives'		=> array($p->mask),
					'attributes'	=> array(
						'modifiedby_id' => $account_id,
						'modified'		=> $this->now
					)
				)
			);
		}
		else
		{
			if (!$this->acl_check(array(
				'string'	=> $p->fake_full_path,
				'relatives'	=> array($p->mask),
				'operation'	=> ACL_ADD
			)))
			{
				return false;
			}

			$value_set = array(
				'owner_id'	=> $this->working_id,
				'directory'	=> $p->fake_leading_dirs_clean,
				'name'		=> $p->fake_name_clean
			);

			if (isset($this->fileoperation->external_ref) && $this->fileoperation->external_ref)
			{
				$value_set['external_id'] = $rr;
			}

			$cols = implode(',', array_keys($value_set));
			$values	= $this->db->validate_insert(array_values($value_set));
			$sql = "INSERT INTO phpgw_vfs ({$cols}) VALUES ({$values})";

			$query = $this->db->query($sql, __LINE__, __FILE__);

			$this->set_attributes(
				array(
					'string'		=> $p->fake_full_path,
					'relatives'		=> array($p->mask),
					'attributes'	=> array(
						'createdby_id'	=> $account_id,
						'created'		=> $this->now,
						'size'			=> 0,
						'deleteable'	=> 'Y',
						'app'			=> $currentapp
					)
				)
			);
			$this->correct_attributes(
				array(
					'string'	=> $p->fake_full_path,
					'relatives'	=> array($p->mask)
				)
			);

			$this->add_journal(
				array(
					'string'	=> $p->fake_full_path,
					'relatives'	=> array($p->mask),
					'operation'	=> VFS_OPERATION_CREATED
				)
			);
		}

		if ($rr || $vr || $query)
		{
			if (isset($this->fileoperation->external_ref) && $this->fileoperation->external_ref)
			{
				return $rr;
			}
			return true;
		}
		else
		{
			return false;
		}
	}

	function touch2($data, $p = array())
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$account_id = $this->userSettings['account_id'];
		$currentapp = $this->flags['currentapp'];

		if (!$p)
		{
			$p = $this->path_parts(
				array(
					'string'	=> $data['string'],
					'relatives'	=> array($data['relatives'][0])
				)
			);
		}

		umask(000);

		if (!$this->acl_check(array(
			'string'	=> $p->fake_full_path,
			'relatives'	=> array($p->mask),
			'operation'	=> ACL_ADD
		)))
		{
			return false;
		}

		$value_set = array(
			'owner_id'	=> $this->working_id,
			'directory'	=> $p->fake_leading_dirs_clean,
			'name'		=> $p->fake_name
		);

		$cols = implode(',', array_keys($value_set));
		$values	= $this->db->validate_insert(array_values($value_set));
		$sql = "INSERT INTO phpgw_vfs ({$cols}) VALUES ({$values})";

		$query = $this->db->query($sql, __LINE__, __FILE__);

		$last_insert_id = $this->db->get_last_insert_id('phpgw_vfs', 'file_id');

		$this->set_attributes(
			array(
				'string'		=> $p->fake_full_path,
				'relatives'		=> array($p->mask),
				'attributes'	=> array(
					'createdby_id'	=> $account_id,
					'created'		=> $this->now,
					'size'			=> 0,
					'deleteable'	=> 'Y',
					'app'			=> $currentapp
				)
			)
		);
		$this->correct_attributes(
			array(
				'string'	=> $p->fake_full_path,
				'relatives'	=> array($p->mask)
			)
		);

		$this->add_journal(
			array(
				'string'	=> $p->fake_full_path,
				'relatives'	=> array($p->mask),
				'operation'	=> VFS_OPERATION_CREATED
			)
		);

		if ($query)
		{
			return $last_insert_id;
		}
		else
		{
			return false;
		}
	}

	/*
		 * See vfs_shared
		 */
	function cp($data)
	{
		if (!$data['from'])
		{
			throw new Exception('nothing to copy from');
		}

		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT, RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$account_id = $this->userSettings['account_id'];

		$f = $this->path_parts(
			array(
				'string'	=> $data['from'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		$t = $this->path_parts(
			array(
				'string'	=> $data['to'],
				'relatives'	=> array($data['relatives'][1])
			)
		);

		if (!$this->fileoperation->check_target_directory($t))
		{
			$this->log->error(array(
				'text' => 'vfs::cp() : missing target directory %1',
				'p1'   => $t->real_leading_dirs,
				'p2'	 => '',
				'line' => __LINE__,
				'file' => __FILE__
			));

			return false;
		}

		if (!$this->acl_check(array(
			'string'	=> $f->fake_full_path,
			'relatives'	=> array($f->mask),
			'operation'	=> ACL_READ
		)))
		{
			return false;
		}

		if ($this->file_exists(array(
			'string'	=> $t->fake_full_path,
			'relatives'	=> array($t->mask)
		)))
		{
			if (!$this->acl_check(array(
				'string'	=> $t->fake_full_path,
				'relatives'	=> array($t->mask),
				'operation'	=> ACL_EDIT
			)))
			{
				return false;
			}
		}
		else
		{
			if (!$this->acl_check(array(
				'string'	=> $t->fake_full_path,
				'relatives'	=> array($t->mask),
				'operation'	=> ACL_ADD
			)))
			{
				return false;
			}
		}

		umask(000);

		if (
			$this->file_type(array(
				'string'	=> $f->fake_full_path,
				'relatives'	=> array($f->mask)
			)) != 'Directory'
		)
		{
			if ($this->file_actions)
			{
				//					if(!$this->fileoperation->touch($data, $t) && !$this->fileoperation->copy($f, $t))
				if (isset($this->fileoperation->external_ref) && $this->fileoperation->external_ref)
				{
					$_document_id = $this->fileoperation->touch($data, $t);
				}
				else
				{
					$_document_id = null;
				}

				if (!$this->fileoperation->copy($f, $t, $_document_id))
				{
					return false;
				}

				$size = $this->fileoperation->filesize($f);
			}
			else
			{
				$content = $this->read(
					array(
						'string'	=> $f->fake_full_path,
						'relatives'	=> array($f->mask)
					)
				);

				$size = strlen($content);
			}

			if ($t->outside)
			{
				return true;
			}

			$ls_array = $this->ls(
				array(
					'string'	=> $f->real_full_path, // Sigurd: seems to work better with real - old: 'string'	=> $f->fake_full_path,
					'relatives'	=> array($f->mask),
					'checksubdirs'	=> false,
					'mime_type'	=> false,
					'nofiles'	=> true
				)
			);
			$record = $ls_array[0];

			if ($this->file_exists(array(
				'string'	=> $data['to'],
				'relatives'	=> array($data['relatives'][1])
			)))
			{
				$extraSql = $this->extra_sql(VFS_SQL_UPDATE);
				$sql = "UPDATE phpgw_vfs SET owner_id=:owner_id, directory=:directory, name=:name WHERE owner_id=:owner_id AND directory=:directory AND name=:name " . $extraSql['sql'];

				$stmt = $this->db->prepare($sql);
				$params = array(':owner_id' => $this->working_id, ':directory' => $t->fake_leading_dirs_clean, ':name' => $t->fake_name_clean);
				$params = array_merge($params, $extraSql['params']);

				$stmt->execute($params);
				$set_attributes_array = array(
					'createdby_id'	=> $account_id,
					'created'		=> $this->now,
					'size'			=> $size,
					'mime_type'		=> $record['mime_type'],
					'deleteable'	=> $record['deleteable'],
					'comment'		=> $record['comment'],
					'app'			=> $record['app']
				);

				if (!$this->file_actions)
				{
					$set_attributes_array['content'] = $content;
				}

				$this->set_attributes(
					array(
						'string'	=> $t->fake_full_path,
						'relatives'	=> array($t->mask),
						'attributes'	=> $set_attributes_array
					)
				);

				$this->add_journal(
					array(
						'string'	=> $t->fake_full_path,
						'relatives'	=> array($t->mask),
						'operation'	=> VFS_OPERATION_EDITED
					)
				);
			}
			else
			{
				$_document_id = $this->touch(
					array(
						'string'	=> $t->fake_full_path,
						'relatives'	=> array($t->mask)
					)
				);

				$set_attributes_array = array(
					'createdby_id'	=> $account_id,
					'created'		=> $this->now,
					'size'			=> $size,
					'mime_type'		=> $record['mime_type'],
					'deleteable'	=> $record['deleteable'],
					'comment'		=> $record['comment'],
					'app'			=> $record['app']
				);

				if (isset($this->fileoperation->external_ref) && $this->fileoperation->external_ref)
				{
					$set_attributes_array['external_id'] = $_document_id;
				}

				if (!$this->file_actions)
				{
					$set_attributes_array['content'] = $content;
				}

				$this->set_attributes(
					array(
						'string'	=> $t->fake_full_path,
						'relatives'	=> array($t->mask),
						'attributes'	=> $set_attributes_array
					)
				);
			}
			$this->correct_attributes(
				array(
					'string'	=> $t->fake_full_path,
					'relatives'	=> array($t->mask)
				)
			);
		}
		else	/* It's a directory */
		{
			/* First, make the initial directory */
			if (
				$this->mkdir(array(
					'string'	=> $data['to'],
					'relatives'	=> array($data['relatives'][1])
				)) === false
			)
			{
				return false;
			}

			/* Next, we create all the directories below the initial directory */
			$ls = $this->ls(
				array(
					'string'	=> $f->fake_full_path,
					'relatives'	=> array($f->mask),
					'checksubdirs'	=> true,
					'mime_type'	=> 'Directory'
				)
			);

			//while(list($num, $entry) = each($ls))
			foreach ($ls as $num => $entry)
			{
				$newdir = preg_replace("/^" . str_replace('/', '\/', $f->fake_full_path) . "/", $t->fake_full_path, $entry['directory']);

				if (
					$this->mkdir(array(
						'string'	=> "{$newdir}/{$entry['name']}",
						'relatives'	=> array($t->mask)
					)) === false
				)
				{
					return false;
				}
			}

			/* Lastly, we copy the files over */
			$ls = $this->ls(
				array(
					'string'	=> $f->fake_full_path,
					'relatives'	=> array($f->mask)
				)
			);

			//while(list($num, $entry) = each($ls))
			foreach ($ls as $num => $entry)
			{
				if ($entry['mime_type'] == 'Directory')
				{
					continue;
				}

				$newdir = preg_replace("/^" . str_replace('/', '\/', $f->fake_full_path) . "/", $t->fake_full_path, $entry['directory']);
				$this->cp(
					array(
						'from'	=> "{$entry['directory']}/{$entry['name']}",
						'to'	=> "{$newdir}/{$entry['name']}",
						'relatives'	=> array($f->mask, $t->mask)
					)
				);
			}
		}

		if (!$f->outside)
		{
			$this->add_journal(
				array(
					'string'	=> $f->fake_full_path,
					'relatives'	=> array($f->mask),
					'operation'	=> VFS_OPERATION_COPIED,
					'state_one'	=> NULL,
					'state_two'	=> $t->fake_full_path
				)
			);
		}

		return true;
	}
	/*
		 * Same as cp function, except an exception is thrown if there is a failure
		 * errors have also been expanded
		 */
	function cp2($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT, RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$account_id = $this->userSettings['account_id'];

		$f = $this->path_parts(
			array(
				'string'	=> $data['from'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		$t = $this->path_parts(
			array(
				'string'	=> $data['to'],
				'relatives'	=> array($data['relatives'][1])
			)
		);

		if (!$this->acl_check(array(
			'string'	=> $f->fake_full_path,
			'relatives'	=> array($f->mask),
			'operation'	=> ACL_READ
		)))
		{
			throw new Exception('ACL(READ) check failed!');
		}

		if ($this->file_exists(array(
			'string'	=> $t->fake_full_path,
			'relatives'	=> array($t->mask)
		)))
		{
			if (!$this->acl_check(array(
				'string'	=> $t->fake_full_path,
				'relatives'	=> array($t->mask),
				'operation'	=> ACL_EDIT
			)))
			{
				throw new Exception('ACL(EDIT) check failed!');
			}
		}
		else
		{
			if (!$this->acl_check(array(
				'string'	=> $t->fake_full_path,
				'relatives'	=> array($t->mask),
				'operation'	=> ACL_ADD
			)))
			{
				throw new Exception('ACL(ADD) check failed!');
			}
		}

		umask(000);

		if (
			$this->file_type(array(
				'string'	=> $f->fake_full_path,
				'relatives'	=> array($f->mask)
			)) != 'Directory'
		)
		{
			if ($this->file_actions)
			{
				if (!$this->fileoperation->copy($f, $t))
				{
					$error = "Copy failed!\n";
					$error = $error . "f->real_full_path: $f->real_full_path \n";
					$error = $error . "t->real_full_path: $t->real_full_path \n";
					throw new Exception($error);
				}
				$size = $this->fileoperation->filesize($f);
			}
			else
			{
				$content = $this->read(
					array(
						'string'	=> $f->fake_full_path,
						'relatives'	=> array($f->mask)
					)
				);

				$size = strlen($content);
			}

			if ($t->outside)
			{
				return true;
			}

			$ls_array = $this->ls(
				array(
					'string'		=> $f->real_full_path, // Sigurd: seems to work better with real - old: 'string'	=> $f->fake_full_path,
					'relatives'		=> array($f->mask),
					'checksubdirs'	=> false,
					'mime_type'		=> false,
					'nofiles'		=> true
				)
			);
			$record = $ls_array[0];

			if ($this->file_exists(array(
				'string'	=> $data['to'],
				'relatives'	=> array($data['relatives'][1])
			)))
			{
				$extraSql = $this->extra_sql(VFS_SQL_UPDATE);
				$sql = "UPDATE phpgw_vfs SET owner_id=:owner_id, directory=:directory, name=:name WHERE owner_id=:owner_id AND directory=:directory AND name=:name " . $extraSql['sql'];

				$stmt = $this->db->prepare($sql);
				$params = array(':owner_id' => $this->working_id, ':directory' => $t->fake_leading_dirs_clean, ':name' => $t->fake_name_clean);
				$params = array_merge($params, $extraSql['params']);

				$stmt->execute($params);

				$set_attributes_array = array(
					'createdby_id'		=> $account_id,
					'created'			=> $this->now,
					'size'				=> $size,
					'mime_type'			=> $record['mime_type'],
					'deleteable'		=> $record['deleteable'],
					'comment'			=> $record['comment'],
					'app'				=> $record['app']
				);

				if (!$this->file_actions)
				{
					$set_attributes_array['content'] = $content;
				}

				$this->set_attributes(
					array(
						'string'		=> $t->fake_full_path,
						'relatives'		=> array($t->mask),
						'attributes'	=> $set_attributes_array
					)
				);

				$this->add_journal(
					array(
						'string'	=> $t->fake_full_path,
						'relatives'	=> array($t->mask),
						'operation'	=> VFS_OPERATION_EDITED
					)
				);
			}
			else
			{
				$_document_id = $this->touch(
					array(
						'string'	=> $t->fake_full_path,
						'relatives'	=> array($t->mask)
					)
				);

				$set_attributes_array = array(
					'createdby_id'		=> $account_id,
					'created'			=> $this->now,
					'size'				=> $size,
					'mime_type'			=> $record['mime_type'],
					'deleteable'		=> $record['deleteable'],
					'comment'			=> $record['comment'],
					'app'				=> $record['app']
				);

				if (isset($this->fileoperation->external_ref) && $this->fileoperation->external_ref)
				{
					$set_attributes_array['external_id'] = $_document_id;
				}

				if (!$this->file_actions)
				{
					$set_attributes_array['content'] = $content;
				}

				$this->set_attributes(
					array(
						'string'		=> $t->fake_full_path,
						'relatives'		=> array($t->mask),
						'attributes'	=> $set_attributes_array
					)
				);
			}
			$this->correct_attributes(
				array(
					'string'	=> $t->fake_full_path,
					'relatives'	=> array($t->mask)
				)
			);
		}
		else	/* It's a directory */
		{
			/* First, make the initial directory */
			if (
				$this->mkdir(array(
					'string'	=> $data['to'],
					'relatives'	=> array($data['relatives'][1])
				)) === false
			)
			{
				throw new Exception('Error, it is a directory');
			}

			/* Next, we create all the directories below the initial directory */
			$ls = $this->ls(
				array(
					'string'		=> $f->fake_full_path,
					'relatives'		=> array($f->mask),
					'checksubdirs'	=> true,
					'mime_type'		=> 'Directory'
				)
			);

			//while(list($num, $entry) = each($ls))
			foreach ($ls as $num => $entry)
			{
				$newdir = preg_replace("/^" . str_replace('/', '\/', $f->fake_full_path) . "/", $t->fake_full_path, $entry['directory']);
				if (
					$this->mkdir(array(
						'string'	=> "{$newdir}/{$entry['name']}",
						'relatives'	=> array($t->mask)
					)) === false
				)
				{
					throw new Exception('While loop error!');
				}
			}

			/* Lastly, we copy the files over */
			$ls = $this->ls(
				array(
					'string'	=> $f->fake_full_path,
					'relatives'	=> array($f->mask)
				)
			);

			//while(list($num, $entry) = each($ls))
			foreach ($ls as $num => $entry)
			{
				if ($entry['mime_type'] == 'Directory')
				{
					continue;
				}

				$newdir = preg_replace("/^" . str_replace('/', '\/', $f->fake_full_path) . "/", $t->fake_full_path, $entry['directory']);

				$this->cp(
					array(
						'from'		=> "{$entry['directory']}/{$entry['name']}",
						'to'		=> "{$newdir}/{$entry['name']}",
						'relatives'	=> array($f->mask, $t->mask)
					)
				);
			}
		}

		if (!$f->outside)
		{
			$this->add_journal(
				array(
					'string'	=> $f->fake_full_path,
					'relatives'	=> array($f->mask),
					'operation'	=> VFS_OPERATION_COPIED,
					'state_one'	=> NULL,
					'state_two'	=> $t->fake_full_path
				)
			);
		}

		return true;
	}


	function cp3($data)
	{
		if (!$data['from'])
		{
			throw new Exception('nothing to copy from');
		}

		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT, RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$account_id = $this->userSettings['account_id'];

		$f = $this->path_parts(
			array(
				'string'	=> $data['from'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		$t = $this->path_parts(
			array(
				'string'	=> $data['to'],
				'relatives'	=> array($data['relatives'][1])
			)
		);

		if (!$this->fileoperation->check_target_directory($t))
		{
			$this->log->error(array(
				'text' => 'vfs::cp() : missing target directory %1',
				'p1'   => $t->real_leading_dirs,
				'p2'	 => '',
				'line' => __LINE__,
				'file' => __FILE__
			));

			return false;
		}

		if (!$this->acl_check(array(
			'string'	=> $f->fake_full_path,
			'relatives'	=> array($f->mask),
			'operation'	=> ACL_READ
		)))
		{
			return false;
		}

		if ($this->file_exists(array(
			'string'	=> $t->fake_full_path,
			'relatives'	=> array($t->mask)
		)))
		{
			if (!$this->acl_check(array(
				'string'	=> $t->fake_full_path,
				'relatives'	=> array($t->mask),
				'operation'	=> ACL_EDIT
			)))
			{
				return false;
			}
		}
		else
		{
			if (!$this->acl_check(array(
				'string'	=> $t->fake_full_path,
				'relatives'	=> array($t->mask),
				'operation'	=> ACL_ADD
			)))
			{
				return false;
			}
		}

		umask(000);

		if (
			$this->file_type(array(
				'string'	=> $f->fake_full_path,
				'relatives'	=> array($f->mask)
			)) != 'Directory'
		)
		{
			if ($this->file_actions)
			{
				//					if(!$this->fileoperation->touch($data, $t) && !$this->fileoperation->copy($f, $t))
				if (isset($this->fileoperation->external_ref) && $this->fileoperation->external_ref)
				{
					$_document_id = $this->fileoperation->touch($data, $t);
				}

				if (!$this->fileoperation->copy($f, $t, $_document_id))
				{
					return false;
				}

				$size = $this->fileoperation->filesize($f);
			}
			else
			{
				$content = $this->read(
					array(
						'string'	=> $f->fake_full_path,
						'relatives'	=> array($f->mask)
					)
				);

				$size = strlen($content);
			}

			if ($t->outside)
			{
				return true;
			}

			$ls_array = $this->ls(
				array(
					'string'	=> $f->real_full_path, // Sigurd: seems to work better with real - old: 'string'	=> $f->fake_full_path,
					'relatives'	=> array($f->mask),
					'checksubdirs'	=> false,
					'mime_type'	=> false,
					'nofiles'	=> true
				)
			);
			$record = $ls_array[0];

			if ($data['id'])
			{
				$file_info = $this->get_info($data['id']);
				$old_file = "{$file_info['directory']}/{$file_info['name']}";

				$p = $this->path_parts(
					array(
						'string'	=> $old_file,
						'relatives'	=> array($data['relatives'][0])
					)
				);

				if (!$this->acl_check(array(
					'string'	=> $p->fake_full_path,
					'relatives'	=> array($p->mask),
					'operation'	=> ACL_DELETE
				)))
				{
					return false;
				}

				if (!$this->file_exists(array(
					'string'	=> $old_file,
					'relatives'	=> array($data['relatives'][0])
				)))
				{
					if ($this->file_actions)
					{
						$this->fileoperation->unlink($p);
					}
				}

				$file_name = $t->fake_leading_dirs . '/' . $data['id'] . '_#' . $t->fake_name;
				$t2 = $this->path_parts(
					array(
						'string'	=> $file_name,
						'relatives'	=> array($data['relatives'][1])
					)
				);

				if (!$this->fileoperation->rename($t, $t2))
				{
					return false;
				}

				$extraSql = $this->extra_sql(VFS_SQL_UPDATE);
				$sql = "UPDATE phpgw_vfs SET owner_id=:owner_id, directory=:directory, name=:name WHERE owner_id=:owner_id AND file_id=:file_id " . $extraSql['sql'];

				$stmt = $this->db->prepare($sql);
				$params = array(':owner_id' => $this->working_id, ':directory' => $t2->fake_leading_dirs_clean, ':name' => $t2->fake_name_clean, ':file_id' => $data['id']);
				$params = array_merge($params, $extraSql['params']);

				$stmt->execute($params);
				$t = $t2;

				$set_attributes_array = array(
					'createdby_id'	=> $account_id,
					'created'		=> $this->now,
					'size'			=> $size,
					'mime_type'		=> $record['mime_type'],
					'deleteable'	=> $record['deleteable'],
					'comment'		=> $record['comment'],
					'app'			=> $record['app']
				);

				if (!$this->file_actions)
				{
					$set_attributes_array['content'] = $content;
				}

				$this->set_attributes(
					array(
						'string'	=> $t->fake_full_path,
						'relatives'	=> array($t->mask),
						'attributes'	=> $set_attributes_array
					)
				);

				$this->add_journal(
					array(
						'string'	=> $t->fake_full_path,
						'relatives'	=> array($t->mask),
						'operation'	=> VFS_OPERATION_EDITED
					)
				);
				$file_id = $data['id'];
			}
			else
			{
				$file_id = $this->touch2(
					array(
						'string'	=> $t->fake_full_path,
						'relatives'	=> array($t->mask)
					)
				);

				if ($file_id)
				{
					if ($this->file_actions)
					{
						$new_name = $t->fake_leading_dirs . '/' . $file_id . '_#' . $t->fake_name;

						$t2 = $this->path_parts(
							array(
								'string'	=> $new_name,
								'relatives'	=> array($data['relatives'][1])
							)
						);

						$rr = $this->fileoperation->rename($t, $t2);

						if ($rr)
						{
							$query = $this->db->query("UPDATE phpgw_vfs SET owner_id='{$this->working_id}',"
								. " directory='{$t2->fake_leading_dirs_clean}',"
								. " name='{$t2->fake_name_clean}'"
								. " WHERE owner_id='{$this->working_id}' AND directory='{$t->fake_leading_dirs_clean}'"
								. " AND name='{$t->fake_name_clean}'", __LINE__, __FILE__);

							$t = $t2;
						}
					}

					$set_attributes_array = array(
						'createdby_id'	=> $account_id,
						'created'		=> $this->now,
						'size'			=> $size,
						'mime_type'		=> $record['mime_type'],
						'deleteable'	=> $record['deleteable'],
						'comment'		=> $record['comment'],
						'app'			=> $record['app']
					);

					if (!$this->file_actions)
					{
						$set_attributes_array['content'] = $content;
					}

					$this->set_attributes(
						array(
							'string'	=> $t->fake_full_path,
							'relatives'	=> array($t->mask),
							'attributes'	=> $set_attributes_array
						)
					);
				}
			}
			$this->correct_attributes(
				array(
					'string'	=> $t->fake_full_path,
					'relatives'	=> array($t->mask)
				)
			);

			if ($file_id)
			{
				return $file_id;
			}
			else
			{
				return false;
			}
		}
		else	/* It's a directory */
		{
			/* First, make the initial directory */
			if (
				$this->mkdir(array(
					'string'	=> $data['to'],
					'relatives'	=> array($data['relatives'][1])
				)) === false
			)
			{
				return false;
			}

			/* Next, we create all the directories below the initial directory */
			$ls = $this->ls(
				array(
					'string'	=> $f->fake_full_path,
					'relatives'	=> array($f->mask),
					'checksubdirs'	=> true,
					'mime_type'	=> 'Directory'
				)
			);

			//while(list($num, $entry) = each($ls))
			foreach ($ls as $num => $entry)
			{
				$newdir = preg_replace("/^" . str_replace('/', '\/', $f->fake_full_path) . "/", $t->fake_full_path, $entry['directory']);

				if (
					$this->mkdir(array(
						'string'	=> "{$newdir}/{$entry['name']}",
						'relatives'	=> array($t->mask)
					)) === false
				)
				{
					return false;
				}
			}

			/* Lastly, we copy the files over */
			$ls = $this->ls(
				array(
					'string'	=> $f->fake_full_path,
					'relatives'	=> array($f->mask)
				)
			);

			//while(list($num, $entry) = each($ls))
			foreach ($ls as $num => $entry)
			{
				if ($entry['mime_type'] == 'Directory')
				{
					continue;
				}

				$newdir = preg_replace("/^" . str_replace('/', '\/', $f->fake_full_path) . "/", $t->fake_full_path, $entry['directory']);
				$this->cp(
					array(
						'from'	=> "{$entry['directory']}/{$entry['name']}",
						'to'	=> "{$newdir}/{$entry['name']}",
						'relatives'	=> array($f->mask, $t->mask)
					)
				);
			}
		}

		if (!$f->outside)
		{
			$this->add_journal(
				array(
					'string'	=> $f->fake_full_path,
					'relatives'	=> array($f->mask),
					'operation'	=> VFS_OPERATION_COPIED,
					'state_one'	=> NULL,
					'state_two'	=> $t->fake_full_path
				)
			);
		}

		return true;
	}

	/*
		 * See vfs_shared
		 */
	function mv($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT, RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$account_id = $this->userSettings['account_id'];
		$f = $this->path_parts(
			array(
				'string'	=> $data['from'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		$t = $this->path_parts(
			array(
				'string'	=> $data['to'],
				'relatives'	=> array($data['relatives'][1])
			)
		);
		if (
			!$this->acl_check(array(
				'string'	=> $f->fake_full_path,
				'relatives'	=> array($f->mask),
				'operation'	=> ACL_READ
			))
			|| !$this->acl_check(array(
				'string'	=> $f->fake_full_path,
				'relatives'	=> array($f->mask),
				'operation'	=> ACL_DELETE
			))
		)
		{
			return false;
		}

		if (!$this->acl_check(array(
			'string'	=> $t->fake_full_path,
			'relatives'	=> array($t->mask),
			'operation'	=> ACL_ADD
		)))
		{
			return false;
		}

		if ($this->file_exists(array(
			'string'	=> $t->fake_full_path,
			'relatives'	=> array($t->mask)
		)))
		{
			if (!$this->acl_check(array(
				'string'	=> $t->fake_full_path,
				'relatives'	=> array($t->mask),
				'operation'	=> ACL_EDIT
			)))
			{
				return false;
			}
		}

		umask(000);

		/* We can't move directories into themselves */
		if (($this->file_type(array(
				'string'	=> $f->fake_full_path,
				'relatives'	=> array($f->mask)
			) == 'Directory'))
			//				&& preg_match("/^{$f->fake_full_path}/", $t->fake_full_path)
			&& preg_match("/^" . str_replace('/', '\/', $f->fake_full_path) . "/", $t->fake_full_path)
		)
		{
			if (($t->fake_full_path == $f->fake_full_path) || substr($t->fake_full_path, strlen($f->fake_full_path), 1) == '/')
			{
				return false;
			}
		}
		if ($this->file_exists(array(
			'string'	=> $f->fake_full_path,
			'relatives'	=> array($f->mask)
		)))
		{
			/* We get the listing now, because it will change after we update the database */
			$ls = $this->ls(
				array(
					'string'	=> $f->fake_full_path,
					'relatives'	=> array($f->mask)
				)
			);

			if ($this->file_exists(array(
				'string'	=> $t->fake_full_path,
				'relatives'	=> array($t->mask)
			)))
			{
				$this->rm(
					array(
						'string'	=> $t->fake_full_path,
						'relatives'	=> array($t->mask)
					)
				);
			}

			/*
				   We add the journal entry now, before we delete.  This way the mime_type
				   field will be updated to 'journal-deleted' when the file is actually deleted
				*/
			if (!$f->outside)
			{
				$this->add_journal(
					array(
						'string'	=> $f->fake_full_path,
						'relatives'	=> array($f->mask),
						'operation'	=> VFS_OPERATION_MOVED,
						'state_one'	=> $f->fake_full_path,
						'state_two'	=> $t->fake_full_path
					)
				);
			}

			/*
				   If the from file is outside, it won't have a database entry,
				   so we have to touch it and find the size
				*/
			if ($f->outside)
			{
				$size = filesize($f->real_full_path);
				if ($size === false)
				{
					_debug_array($f);
					$size = 1;
				}
				$_document_id = $this->touch(
					array(
						'string'	=> $t->fake_full_path,
						'relatives'	=> array($t->mask)
					)
				);
				$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_UPDATE));
				$sql = "UPDATE phpgw_vfs SET size=:size WHERE directory=:directory AND name=:name " . $extraSql['sql'];

				$stmt = $this->db->prepare($sql);
				$params = array(':size' => $size, ':directory' => $t->fake_leading_dirs_clean, ':name' => $t->fake_name_clean);
				$params = array_merge($params, $extraSql['params']);

				$stmt->execute($params);
			}
			elseif (!$t->outside)
			{
				$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_UPDATE));
				$sql = "UPDATE phpgw_vfs SET name=:new_name, directory=:new_directory WHERE directory=:directory AND name=:name " . $extraSql['sql'];

				$stmt = $this->db->prepare($sql);
				$params = array(':new_name' => $t->fake_name_clean, ':new_directory' => $t->fake_leading_dirs_clean, ':directory' => $f->fake_leading_dirs_clean, ':name' => $f->fake_name_clean);
				$params = array_merge($params, $extraSql['params']);

				$stmt->execute($params);
			}

			$this->set_attributes(
				array(
					'string'	=> $t->fake_full_path,
					'relatives'	=> array($t->mask),
					'attributes'	=> array(
						'modifiedby_id' => $account_id,
						'modified' => $this->now,
						'external_id'	=> isset($this->fileoperation->external_ref) && $this->fileoperation->external_ref ? $_document_id : false
					)
				)
			);

			$this->correct_attributes(
				array(
					'string'	=> $t->fake_full_path,
					'relatives'	=> array($t->mask)
				)
			);

			if ($this->file_actions)
			{
				$rr = $this->fileoperation->rename($f, $t);
			}

			/*
				   This removes the original entry from the database
				   The actual file is already deleted because of the rename() above
				*/
			if ($t->outside)
			{
				$this->rm(
					array(
						'string'	=> $f->fake_full_path,
						'relatives'	=> $f->mask
					)
				);
			}
		}
		else
		{
			return false;
		}

		if (
			$this->file_type(array(
				'string'	=> $t->fake_full_path,
				'relatives'	=> array($t->mask)
			)) == 'Directory'
		)
		{
			/* We got $ls from above, before we renamed the directory */
			//while(list($num, $entry) = each($ls))
			if (is_array($ls))
			{
				foreach ($ls as $num => $entry)
				{
					$newdir = preg_replace("/^" . str_replace('/', '\/', $f->fake_full_path) . "/", $t->fake_full_path, $entry['directory']);
					$newdir_clean = $this->clean_string(array('string' => $newdir));

					$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_UPDATE));
					$sql = "UPDATE phpgw_vfs SET directory=:newdir_clean WHERE file_id=:file_id " . $extraSql['sql'];

					$stmt = $this->db->prepare($sql);
					$params = array(':newdir_clean' => $newdir_clean, ':file_id' => $entry['file_id']);
					$params = array_merge($params, $extraSql['params']);

					$stmt->execute($params);
					$this->correct_attributes(
						array(
							'string'	=> "{$newdir}/{$entry['name']}",
							'relatives'	=> array($t->mask)
						)
					);
				}
			}
		}

		$this->add_journal(
			array(
				'string'	=> $t->fake_full_path,
				'relatives'	=> array($t->mask),
				'operation'	=> VFS_OPERATION_MOVED,
				'state_one'	=> $f->fake_full_path,
				'state_two'	=> $t->fake_full_path
			)
		);

		return true;
	}

	/*
		 * See vfs_shared
		 */
	function rm($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$p = $this->path_parts(
			array(
				'string'	=> $data['string'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		if (!$this->acl_check(array(
			'string'	=> $p->fake_full_path,
			'relatives'	=> array($p->mask),
			'operation'	=> ACL_DELETE
		)))
		{
			return false;
		}

		if (!$this->file_exists(array(
			'string'	=> $data['string'],
			'relatives'	=> array($data['relatives'][0])
		)))
		{
			if ($this->file_actions)
			{
				$rr = $this->fileoperation->unlink($p);
			}
			else
			{
				$rr = true;
			}

			if ($rr)
			{
				return true;
			}
			else
			{
				return false;
			}
		}

		if (
			$this->file_type(array(
				'string'	=> $data['string'],
				'relatives'	=> array($data['relatives'][0])
			)) != 'Directory'
		)
		{
			$this->add_journal(
				array(
					'string'	=> $p->fake_full_path,
					'relatives'	=> array($p->mask),
					'operation'	=> VFS_OPERATION_DELETED
				)
			);

			$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_DELETE));
			$sql = "SELECT file_id FROM phpgw_vfs WHERE directory=:directory AND name=:name " . $extraSql['sql'];

			$stmt = $this->db->prepare($sql);
			$params = array(':directory' => $p->fake_leading_dirs_clean, ':name' => $p->fake_name_clean);
			$params = array_merge($params, $extraSql['params']);

			$stmt->execute($params);

			$row = $stmt->fetch(PDO::FETCH_ASSOC);

			$file_id = (int)$row['file_id'];
			if ($file_id)
			{
				$sql = "DELETE FROM phpgw_vfs_filetags WHERE file_id = :file_id";
				$stmt = $this->db->prepare($sql);
				$stmt->execute([':file_id' => $file_id]);
			}

			$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_DELETE));
			$sql = "DELETE FROM phpgw_vfs WHERE directory=:directory AND name=:name " . $extraSql['sql'];

			$stmt = $this->db->prepare($sql);
			$params = array(':directory' => $p->fake_leading_dirs_clean, ':name' => $p->fake_name_clean);
			$params = array_merge($params, $extraSql['params']);

			$ret = $stmt->execute($params);
			if ($this->file_actions)
			{
				$rr = $this->fileoperation->unlink($p);
			}
			else
			{
				$rr = true;
			}

			if ($ret || $rr)
			{
				return true;
			}
			else
			{
				return false;
			}
		}
		else
		{
			$ls = $this->ls(
				array(
					'string'	=> $p->fake_full_path,
					'relatives'	=> array($p->mask)
				)
			);

			/* First, we cycle through the entries and delete the files */
			//while(list($num, $entry) = each($ls))
			foreach ($ls as $num => $entry)
			{
				if ($entry['mime_type'] == 'Directory')
				{
					continue;
				}

				$this->rm(
					array(
						'string'	=> "{$entry['directory']}/{$entry['name']}",
						'relatives'	=> array($p->mask)
					)
				);
			}

			/* Now we cycle through again and delete the directories */
			//reset($ls);
			//while(list($num, $entry) = each($ls))
			foreach ($ls as $num => $entry)
			{
				if ($entry['mime_type'] != 'Directory')
				{
					continue;
				}

				/* Only the best in confusing recursion */
				$this->rm(
					array(
						'string'	=> "{$entry['directory']}/{$entry['name']}",
						'relatives'	=> array($p->mask)
					)
				);
			}

			/* If the directory is linked, we delete the placeholder directory */
			$ls_array = $this->ls(
				array(
					'string'		=> $p->fake_full_path,
					'relatives'		=> array($p->mask),
					'checksubdirs'	=> false,
					'mime_type'		=> false,
					'nofiles'		=> true
				)
			);
			$link_info = $ls_array[0];

			if ($link_info['link_directory'] && $link_info['link_name'])
			{
				$path = $this->path_parts(
					array(
						'string'	=> "{$link_info['directory']}/{$link_info['name']}",
						'relatives'	=> array($p->mask),
						'nolinks'	=> true
					)
				);

				if ($this->file_actions)
				{
					$this->fileoperation->rmdir($path);
				}
			}

			/* Last, we delete the directory itself */
			$this->add_journal(
				array(
					'string'	=> $p->fake_full_path,
					'relatives'	=> array($p->mask),
					'operaton'	=> VFS_OPERATION_DELETED
				)
			);

			$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_DELETE));
			$sql = "DELETE FROM phpgw_vfs WHERE directory=:directory AND name=:name " . $extraSql['sql'];

			$stmt = $this->db->prepare($sql);
			$params = array(':directory' => $p->fake_leading_dirs_clean, ':name' => $p->fake_name_clean);
			$params = array_merge($params, $extraSql['params']);

			$stmt->execute($params);

			if ($this->file_actions)
			{
				$this->fileoperation->rmdir($p);
			}

			return true;
		}
	}

	/*
		 * See vfs_shared
		 */
	function mkdir($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$account_id = $this->userSettings['account_id'];
		$currentapp = $this->flags['currentapp'];

		$p = $this->path_parts(
			array(
				'string'	=> $data['string'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		if (!$this->acl_check(
			array(
				'string'	=> $p->fake_full_path,
				'relatives'	=> array($p->mask),
				'operation'	=> ACL_ADD
			)
		))
		{
			return false;
		}

		/* We don't allow /'s in dir names, of course */
		if (preg_match('/\//', $p->fake_name))
		{
			return false;
		}

		umask(000);

		if ($this->file_actions)
		{
			if (!$this->fileoperation->check_target_directory($p))
			{
				$this->log->error(array(
					'text' => 'vfs::mkdir() : missing leading directory %1 when attempting to create %2',
					'p1'   => $p->real_leading_dirs,
					'p2'	 => $p->real_full_path,
					'line' => __LINE__,
					'file' => __FILE__
				));

				return false;
			}

			/* Auto create home */

			$this->fileoperation->auto_create_home($this->basedir);

			if ($this->fileoperation->file_exists($p))
			{
				if (!$this->fileoperation->is_dir($p))
				{
					return false;
				}
			}
			else if (!$this->fileoperation->mkdir($p))
			{
				$this->log->error(array(
					'text' => 'vfs::mkdir() : failed to create directory %1',
					'p1'   => $p->real_full_path,
					'p2'	 => '',
					'line' => __LINE__,
					'file' => __FILE__
				));

				return false;
			}
		}

		if (!$this->file_exists(array(
			'string'	=> $p->fake_full_path,
			'relatives'	=> array($p->mask)
		)))
		{
			$query = $this->db->query("INSERT INTO phpgw_vfs(owner_id, name, directory)"
				. " VALUES({$this->working_id}, '{$p->fake_name_clean}', '{$p->fake_leading_dirs_clean}')", __LINE__, __FILE__);
			$this->set_attributes(
				array(
					'string'		=> $p->fake_full_path,
					'relatives'		=> array($p->mask),
					'attributes'	=> array(
						'createdby_id'	=> $account_id,
						'size'			=> 4096,
						'mime_type'		=> 'Directory',
						'created'		=> $this->now,
						'deleteable'	=> 'Y',
						'app'			=> $currentapp
					)
				)
			);

			$this->correct_attributes(
				array(
					'string'	=> $p->fake_full_path,
					'relatives'	=> array($p->mask)
				)
			);

			$this->add_journal(
				array(
					'string'	=> $p->fake_full_path,
					'relatives'	=> array($p->mask),
					'operation'	=> VFS_OPERATION_CREATED
				)
			);
		}
		else
		{
			return false;
		}

		return true;
	}

	/*
		 * See vfs_shared
		 */
	function make_link($data)
	{
		/* Does not seem to be used */
		return false;

		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT, RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$account_id = $this->userSettings['account_id'];
		$currentapp = $this->flags['currentapp'];

		$vp = $this->path_parts(
			array(
				'string'	=> $data['vdir'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		$rp = $this->path_parts(
			array(
				'string'	=> $data['rdir'],
				'relatives'	=> array($data['relatives'][1])
			)
		);

		if (!$this->acl_check(array(
			'string'	=> $vp->fake_full_path,
			'relatives'	=> array($vp->mask),
			'operation'	=> ACL_ADD
		)))
		{
			return false;
		}

		if ($this->file_exists(array(
			'string'	=> $rp->real_full_path,
			'relatives'	=> array($rp->mask)
		)))
		{
			if (!$this->fileoperation->is_dir($rp))
			{
				return false;
			}
		}
		elseif (!$this->fileoperation->mkdir($rp))
		{
			return false;
		}

		if (!$this->mkdir(array(
			'string'	=> $vp->fake_full_path,
			'relatives'	=> array($vp->mask)
		)))
		{
			return false;
		}

		//FIXME real_full_path...
		$size = $this->get_size(
			array(
				'string'	=> $rp->real_full_path,
				'relatives'	=> array($rp->mask)
			)
		);

		$this->set_attributes(
			array(
				'string'	=> $vp->fake_full_path,
				'relatives'	=> array($vp->mask),
				'attributes'	=> array(
					'link_directory' => $rp->real_leading_dirs,
					'link_name' => $rp->real_name,
					'size' => $size
				)
			)
		);

		$this->correct_attributes(
			array(
				'string'	=> $vp->fake_full_path,
				'relatives'	=> array($vp->mask)
			)
		);

		return true;
	}

	/*
		 * See vfs_shared
		 */
	function set_attributes($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT),
			'attributes'	=> array()
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$p = $this->path_parts(
			array(
				'string'	=> $data['string'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		/*
			   This is kind of trivial, given that set_attributes() can change owner_id,
			   size, etc.
			*/
		if (!$this->acl_check(array(
			'string'	=> $p->fake_full_path,
			'relatives'	=> array($p->mask),
			'operation'	=> ACL_EDIT
		)))
		{
			return false;
		}

		if (!$this->file_exists(array(
			'string'	=> $data['string'],
			'relatives'	=> array($data['relatives'][0])
		)))
		{
			return false;
		}

		/*
			   All this voodoo just decides which attributes to update
			   depending on if the attribute was supplied in the 'attributes' array
			*/

		$ls_array = $this->ls(
			array(
				'string'	=> $p->fake_full_path,
				'relatives'	=> array($p->mask),
				'checksubdirs'	=> false,
				'nofiles'	=> true
			)
		);
		$record = $ls_array[0];

		$sql = 'UPDATE phpgw_vfs SET ';

		$change_attributes = 0;
		$edited_comment = false;

		reset($this->attributes);
		$value_set = array();
		$params = array();

		foreach ($this->attributes as $num => $attribute)
		{
			if (isset($data['attributes'][$attribute]))
			{
				$$attribute = $data['attributes'][$attribute];

				/*
					   Indicate that the EDITED_COMMENT operation needs to be journaled,
					   but only if the comment changed
					*/
				if ($attribute == 'comment' && $data['attributes'][$attribute] != $record[$attribute])
				{
					$edited_comment = true;
				}

				if ($attribute == 'owner_id' && !$$attribute)
				{
					$$attribute = $this->userSettings['account_id'];
				}

				$$attribute = $this->clean_string(array('string' => $$attribute));

				$value_set[] = $attribute . " = :" . $attribute;
				$params[":" . $attribute] = $$attribute;

				$change_attributes++;
			}
		}

		if ($change_attributes)
		{
			$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_UPDATE));
			$sql = "UPDATE phpgw_vfs SET " . implode(", ", $value_set) . " WHERE file_id=:file_id " . $extraSql['sql'];
			$params[':file_id'] = (int)$record['file_id'];
			$params = array_merge($params, $extraSql['params']);

			$stmt = $this->db->prepare($sql);
			$stmt->execute($params);

			if ($stmt->rowCount() > 0)
			{
				if ($edited_comment)
				{
					$this->add_journal(
						array(
							'string'	=> $p->fake_full_path,
							'relatives'	=> array($p->mask),
							'operation'	=> VFS_OPERATION_EDITED_COMMENT
						)
					);
				}

				return true;
			}
			else
			{
				return false;
			}
		}
		else
		{
			//Nothing was done, because nothing required !
			//This is a kind of bug isn't it ?
			//So I let people choose to debug here :/
			//FIXME : decide what we are doing here !
			return true;
		}
	}

	/*
		 * See vfs_shared
		 */
	function file_type($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$p = $this->path_parts(
			array(
				'string'	=> $data['string'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		if (!$this->acl_check(array(
			'string'	=> $p->fake_full_path,
			'relatives'	=> array($p->mask),
			'operation'	=> ACL_READ,
			'must_exist'	=> true
		)))
		{
			return false;
		}

		/*
			* The file is outside the virtual root
			*/
		if ($p->outside)
		{
			if (is_dir($p->real_full_path))
			{
				return ('Directory');
			}

			/*
				   We don't return an empty string here, because it may still match with a database query
				   because of linked directories
				*/
		}

		/*
			   We don't use ls() because it calls file_type() to determine if it has been
			   passed a directory
			*/
		$db2 = &$this->db2;
		$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_SELECT));
		$sql = "SELECT mime_type FROM phpgw_vfs WHERE directory=:directory AND name=:name " . $extraSql['sql'];

		$stmt = $db2->prepare($sql);
		$params = array(':directory' => $p->fake_leading_dirs_clean, ':name' => $p->fake_name_clean);
		$params = array_merge($params, $extraSql['params']);

		$stmt->execute($params);

		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$mime_type = $row['mime_type'];
		if (!$mime_type)
		{
			$mime_type = $this->get_ext_mime_type(array('string' => $data['string']));
			{
				$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_SELECT));
				$sql = "UPDATE phpgw_vfs SET mime_type=:mime_type WHERE directory=:directory AND name=:name " . $extraSql['sql'];

				$stmt = $db2->prepare($sql);
				$params = array(':mime_type' => $mime_type, ':directory' => $p->fake_leading_dirs_clean, ':name' => $p->fake_name_clean);
				$params = array_merge($params, $extraSql['params']);

				$stmt->execute($params);
			}
		}

		return $mime_type;
	}

	function get_all_tags()
	{
		$sql = "SELECT DISTINCT jsonb_array_elements(tags) as tag FROM phpgw_vfs_filetags";

		$stmt = $this->db->prepare($sql);
		$stmt->execute();

		$tags = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$tag = json_decode($row['tag']);
			$tags[] = array(
				'id' => $tag,
				'name' => $tag
			);
		}
		return $tags;
	}


	function remove_tags($ids, $tags)
	{
		if (!is_array($tags))
		{
			$tags = array($tags);
		}

		$_tags = array_unique($tags);

		foreach ($_tags as $tag)
		{
			$this->remove_tag($ids, $tag);
		}
	}

	function remove_tag($ids, $tag)
	{
		if (!is_array($ids))
		{
			$ids = array($ids);
		}

		$json_string = json_encode($tag);

		$sql = "SELECT file_id FROM phpgw_vfs_filetags WHERE file_id IN (" . implode(',', $ids) . ")"
			. " AND tags @> '[{$json_string}]'::jsonb";

		$this->db->query($sql);

		$ids_with_tag = array();
		while ($this->db->next_record())
		{
			$ids_with_tag[] = $this->db->f('file_id');
		}

		if ($ids_with_tag)
		{
			$placeholders = implode(',', array_fill(0, count($ids_with_tag), '?'));
			$sql = "UPDATE phpgw_vfs_filetags SET tags = tags - ? WHERE file_id IN ($placeholders)";

			$params = array_merge(array($tag), $ids_with_tag);
			$stmt = $this->db->prepare($sql);
			$stmt->execute($params);
		}
	}

	/**
	 * Add or update multiple tags on multiple files
	 * @param array $ids
	 * @param array $tag
	 */
	function set_tags($ids, $tags)
	{
		if (!is_array($tags))
		{
			$tags = array($tags);
		}

		$_tags = array_unique($tags);

		foreach ($_tags as $tag)
		{
			$this->set_tag($ids, $tag);
		}
	}

	/**
	 * Add or update single tag on multiple files
	 * @param array $ids
	 * @param string $tag
	 */
	function set_tag($ids, $tag)
	{
		if (!$tag || !$ids)
		{
			return;
		}

		if (!is_array($ids))
		{
			$ids = array($ids);
		}

		$placeholders = implode(',', array_fill(0, count($ids), '?'));
		$sql = "SELECT file_id FROM phpgw_vfs_filetags WHERE file_id IN ($placeholders)";

		$stmt = $this->db->prepare($sql);
		$stmt->execute($ids);

		$existing_ids = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$existing_ids[] = $row['file_id'];
		}
		$ids_with_tag = array();

		if ($existing_ids)
		{
			$sql = "SELECT file_id FROM phpgw_vfs_filetags WHERE file_id IN (" . implode(',', $existing_ids) . ")"
				. " AND tags @> '[" . json_encode($tag) . "]'::jsonb";

			$this->db->query($sql);

			$ids_with_tag = array();
			while ($this->db->next_record())
			{
				$ids_with_tag[] = $this->db->f('file_id');
			}
		}

		$new_ids = array_diff($ids, $existing_ids);

		$append_ids = array_diff($existing_ids, $ids_with_tag);
		if ($new_ids)
		{
			$sql = 'INSERT INTO phpgw_vfs_filetags (file_id, tags) VALUES(?, ?)';
			$valueset	 = array();
			foreach ($new_ids as $new_id)
			{
				$valueset[] = array(
					1	 => array(
						'value'	 => (int)$new_id,
						'type'	 => PDO::PARAM_INT
					),
					2	 => array(
						'value'	 => json_encode(array($tag)),
						'type'	 => PDO::PARAM_STR
					)
				);
			}
			$this->db->insert($sql, $valueset, __LINE__, __FILE__);
		}

		if ($append_ids)
		{
			$json_string = json_encode($tag);
			$sql = "UPDATE phpgw_vfs_filetags SET tags = tags || '$json_string'::jsonb"
				. " WHERE file_id IN (" . implode(',', $append_ids) . ")";
			$this->db->query($sql, __LINE__, __FILE__);
		}
	}
	/*
		 * See vfs_shared
		 */
	function file_exists($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$p = $this->path_parts(
			array(
				'string'	=> $data['string'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		if ($p->outside)
		{
			$rr = file_exists($p->real_full_path);

			return $rr;
		}

		$db2 = &$this->db2;
		$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_SELECT));
		$sql = "SELECT name FROM phpgw_vfs WHERE directory=:directory AND name=:name " . $extraSql['sql'];

		$stmt = $db2->prepare($sql);
		$params = array(':directory' => $p->fake_leading_dirs_clean, ':name' => $p->fake_name_clean);
		$params = array_merge($params, $extraSql['params']);

		$stmt->execute($params);

		if ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	/*
		 * See vfs_shared
		 */
	function get_size($data)
	{
		$size = parent::get_size($data);
		/*XXX Caeies : Not sure about this, but ... */
		/* If the virtual size is always 4096, we don't need this ... */
		/*			if($data['checksubdirs'])
			{
				$query = $this->db->query("SELECT size FROM phpgw_vfs WHERE directory='".$p->fake_leading_dirs_clean."' AND name='".$p->fake_name_clean."'" . $this->extra_sql(array('query_text' => VFS_SQL_SELECT)));
				$this->db->next_record();
				$size += $this->db->Record[0];
			}
*/
		return $size;
	}

	/* temporary wrapper function for not working Record function in adodb layer(ceb)*/
	function Record($attributes = array(), $row = array())
	{
		if (!$attributes)
		{
			$attributes = $this->attributes;
		}

		$values = array();
		if ($row)
		{
			foreach ($attributes as $attribute)
			{
				$values[$attribute] = $row[$attribute];
			}
		}
		return $values;
	}

	/*
		 * See vfs_shared
		 */
	function ls($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT),
			'checksubdirs'	=> true,
			'mime_type'	=> false,
			'nofiles'	=> false,
			'orderby'	=> 'directory'
		);

		$data = array_merge($this->default_values($data, $default_values), $data);
		//_debug_array($data);

		$p = $this->path_parts(
			array(
				'string'	=> $data['string'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		//_debug_array($p);

		$ftype = $this->file_type(array('string' => $p->fake_full_path, 'relatives' => array($p->mask)));
		/* If they pass us a file or 'nofiles' is set, return the info for $dir only */
		if (($ftype != 'Directory' || $data['nofiles']) && !$p->outside)
		{
			/* SELECT all, the, attributes */
			$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_SELECT));
			$sql = 'SELECT ' . implode(', ', $this->attributes)
				. " FROM phpgw_vfs WHERE directory=:directory AND name=:name "
				. $extraSql['sql'];

			$stmt = $this->db->prepare($sql);
			$params = array(':directory' => $p->fake_leading_dirs_clean, ':name' => $p->fake_name_clean);
			$params = array_merge($params, $extraSql['params']);

			$stmt->execute($params);

			if ($stmt->rowCount() == 0)
			{
				return array();
			}

			$record = $stmt->fetch(PDO::FETCH_ASSOC);
			//echo 'record: ' . _debug_array($record);

			/* We return an array of one array to maintain the standard */
			$rarray = array();
			//reset($this->attributes);
			$db2 = &$this->db2;
			//while(list($num, $attribute) = each($this->attributes))
			foreach ($this->attributes as $num => $attribute)
			{
				if ($attribute == 'mime_type' && !$record[$attribute])
				{
					$record[$attribute] = $this->get_ext_mime_type(
						array(
							'string' => $p->fake_name_clean
						)
					);

					if ($record[$attribute])
					{
						$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_SELECT));
						$sql = "UPDATE phpgw_vfs SET mime_type=:mime_type WHERE directory=:directory AND name=:name " . $extraSql['sql'];

						$stmt = $db2->prepare($sql);
						$params = array(':mime_type' => $record[$attribute], ':directory' => $p->fake_leading_dirs_clean, ':name' => $p->fake_name_clean);
						$params = array_merge($params, $extraSql['params']);

						$stmt->execute($params);
					}
				}

				$rarray[0][$attribute] = $record[$attribute];
			}

			return $rarray;
		}

		//WIP - this should recurse using the same options the virtual part of ls() does
		/* If $dir is outside the virutal root, we have to check the file system manually */
		if ($p->outside)
		{
			if (
				$this->file_type(array(
					'string'	=> $p->fake_full_path,
					'relatives'	=> array($p->mask)
				)) == 'Directory'
				&& !$data['nofiles']
			)
			{
				$dir_handle = opendir($p->real_full_path);
				while ($filename = readdir($dir_handle))
				{
					if ($filename == '.' || $filename == '..')
					{
						continue;
					}
					$rarray[] = $this->get_real_info(
						array(
							'string'	=> "{$p->real_full_path}/{$filename}",
							'relatives'	=> array($p->mask)
						)
					);
				}
			}
			else
			{
				$rarray[] = $this->get_real_info(
					array(
						'string'	=> $p->real_full_path,
						'relatives'	=> array($p->mask)
					)
				);
			}

			return $rarray;
		}

		/* $dir's not a file, is inside the virtual root, and they want to check subdirs */
		/* SELECT all, the, attributes FROM phpgw_vfs WHERE file=$dir */
		$sql = 'SELECT ' . implode(',', $this->attributes);

		$dir_clean = $this->clean_string(array('string' => $p->fake_full_path));
		$params = array();

		if ($data['checksubdirs'])
		{
			$sql .= " FROM phpgw_vfs WHERE directory LIKE :dir_clean";
			$params[':dir_clean'] = $dir_clean . '%';
		}
		else
		{
			$_attributes = $this->attributes;
			$_attributes[] = 'tags';

			$sql = "SELECT tags, phpgw_vfs.* FROM phpgw_vfs LEFT JOIN phpgw_vfs_filetags ON phpgw_vfs.file_id = phpgw_vfs_filetags.file_id WHERE directory = :dir_clean";
			$params[':dir_clean'] = $dir_clean;
		}

		$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_SELECT));
		$sql .= $extraSql['sql'];
		$params = array_merge($params, $extraSql['params']);

		if ($data['mime_type'])
		{
			$sql .= " AND mime_type=:mime_type";
			$params[':mime_type'] = $data['mime_type'];
		}

		if (!empty($data['orderby']))
		{
			$sql .= " ORDER BY {$data['orderby']}";
		}

		//	$params[':orderby'] = $data['orderby'];

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);

		$rarray = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$record = $this->Record($_attributes, $row);

			//_debug_array($record);
			/* Further checking on the directory.  This makes sure /home/user/test won't match /home/user/test22 */
			//	if(!@ereg("^{$p->fake_full_path}(/|$)", $record['directory']))
			if (!preg_match("/^" . str_replace('/', '\/', $p->fake_full_path) . "(\/|$)/", $record['directory']))
			{
				continue;
			}

			/* If they want only this directory, then $dir should end without a trailing / */
			//				if(!$data['checksubdirs'] && preg_match("/^{$p->fake_full_path}\//", $record['directory']))
			if (!$data['checksubdirs'] && preg_match("/^" . str_replace('/', '\/', $p->fake_full_path) . "\//", $record['directory']))
			{
				continue;
			}

			if (isset($this->attributes['mime_type']) && !isset($record['mime_type']))
			{
				$db2 = &$this->db2;
				$record['mime_type'] == $this->get_ext_mime_type(array('string' => $p->fake_name_clean));

				if ($record['mime_type'])
				{
					$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_SELECT));
					$sql = "UPDATE phpgw_vfs SET mime_type=:mime_type WHERE directory=:directory AND name=:name " . $extraSql['sql'];

					$stmt = $db2->prepare($sql);
					$params = array(':mime_type' => $record['mime_type'], ':directory' => $p->fake_leading_dirs_clean, ':name' => $p->fake_name_clean);
					$params = array_merge($params, $extraSql['params']);

					$stmt->execute($params);
				}
			}
			$rarray[] = $record;
		}
		return $rarray;
	}

	/*
		 * See vfs_shared
		 */
	function update_real($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$p = $this->path_parts(
			array(
				'string'	=> $data['string'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		if (file_exists($p->real_full_path))
		{
			if (is_dir($p->real_full_path))
			{
				$dir_handle = opendir($p->real_full_path);
				while ($filename = readdir($dir_handle))
				{
					if ($filename == '.' || $filename == '..')
					{
						continue;
					}

					$rarray[] = $this->get_real_info(
						array(
							'string'	=> "{$p->fake_full_path}/{$filename}",
							'relatives'	=> array(RELATIVE_NONE)
						)
					);
				}
			}
			else
			{
				$rarray[] = $this->get_real_info(
					array(
						'string'	=> $p->fake_full_path,
						'relatives'	=> array(RELATIVE_NONE)
					)
				);
			}

			if (!is_array($rarray))
			{
				$rarray = array();
			}

			//while(list($num, $file_array) = each($rarray))
			foreach ($rarray as $num => $file_array)
			{
				$p2 = $this->path_parts(
					array(
						'string'	=> "{$file_array['directory']}/{$file_array['name']}",
						'relatives'	=> array(RELATIVE_NONE)
					)
				);

				/* Note the mime_type.  This can be "Directory", which is how we create directories */
				$set_attributes_array = array(
					'size' => $file_array['size'],
					'mime_type' => $file_array['mime_type']
				);

				if (!$this->file_exists(array(
					'string'	=> $p2->fake_full_path,
					'relatives'	=> array(RELATIVE_NONE)
				)))
				{
					$_document_id = $this->touch(
						array(
							'string'	=> $p2->fake_full_path,
							'relatives'	=> array(RELATIVE_NONE)
						)
					);

					if (isset($this->fileoperation->external_ref) && $this->fileoperation->external_ref)
					{
						$set_attributes_array['external_id'] = $_document_id;
					}

					$this->set_attributes(
						array(
							'string'	=> $p2->fake_full_path,
							'relatives'	=> array(RELATIVE_NONE),
							'attributes'	=> $set_attributes_array
						)
					);
				}
				else
				{
					$this->set_attributes(
						array(
							'string'	=> $p2->fake_full_path,
							'relatives'	=> array(RELATIVE_NONE),
							'attributes'	=> $set_attributes_array
						)
					);
				}
			}
		}
	}

	/* Helper functions */

	/* This fetchs all available file system information for string(not using the database) */
	function get_real_info($data)
	{
		if (!is_array($data))
		{
			$data = array();
		}

		$default_values = array(
			'relatives'	=> array(RELATIVE_CURRENT)
		);

		$data = array_merge($this->default_values($data, $default_values), $data);

		$p = $this->path_parts(
			array(
				'string'	=> $data['string'],
				'relatives'	=> array($data['relatives'][0])
			)
		);

		if (is_dir($p->real_full_path))
		{
			$mime_type = 'Directory';
		}
		else
		{
			$mime_type = $this->get_ext_mime_type(
				array(
					'string'	=> $p->fake_name
				)
			);

			if ($mime_type)
			{
				$extraSql = $this->extra_sql(array('query_type' => VFS_SQL_SELECT));
				$sql = "UPDATE phpgw_vfs SET mime_type=:mime_type WHERE directory=:directory AND name=:name " . $extraSql['sql'];

				$stmt = $this->db->prepare($sql);
				$params = array(':mime_type' => $mime_type, ':directory' => $p->fake_leading_dirs_clean, ':name' => $p->fake_name_clean);
				$params = array_merge($params, $extraSql['params']);

				$stmt->execute($params);
			}
		}

		$size = filesize($p->real_full_path);
		$rarray = array(
			'directory' => $p->fake_leading_dirs,
			'name' => $p->fake_name,
			'size' => $size,
			'mime_type' => $mime_type
		);

		return ($rarray);
	}
}
