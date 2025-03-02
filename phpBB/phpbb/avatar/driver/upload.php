<?php
/**
*
* This file is part of the phpBB Forum Software package.
*
* @copyright (c) phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the docs/CREDITS.txt file.
*
*/

namespace phpbb\avatar\driver;

use bantu\IniGetWrapper\IniGetWrapper;
use phpbb\config\config;
use phpbb\controller\helper;
use phpbb\event\dispatcher_interface;
use phpbb\files\factory;
use phpbb\path_helper;
use phpbb\storage\exception\exception as storage_exception;
use phpbb\storage\storage;

/**
* Handles avatars uploaded to the board.
*/
class upload extends \phpbb\avatar\driver\driver
{
	/**
	 * @var helper
	 */
	private $controller_helper;

	/**
	 * @var storage
	 */
	protected $storage;

	/**
	* @var dispatcher_interface
	*/
	protected $dispatcher;

	/**
	 * @var factory
	 */
	protected $files_factory;

	/**
	 * @var IniGetWrapper
	 */
	protected $php_ini;

	/**
	 * Construct a driver object
	 *
	 * @param config $config phpBB configuration
	 * @param helper $controller_helper
	 * @param string $phpbb_root_path Path to the phpBB root
	 * @param string $php_ext PHP file extension
	 * @param storage $storage phpBB avatar storage
	 * @param path_helper $path_helper phpBB path helper
	 * @param dispatcher_interface $dispatcher phpBB Event dispatcher object
	 * @param factory $files_factory File classes factory
	 * @param IniGetWrapper $php_ini ini_get() wrapper
	 */
	public function __construct(config $config, helper $controller_helper, string $phpbb_root_path, string $php_ext, storage $storage, path_helper $path_helper, dispatcher_interface $dispatcher, factory $files_factory, IniGetWrapper $php_ini)
	{
		$this->config = $config;
		$this->controller_helper = $controller_helper;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
		$this->storage = $storage;
		$this->path_helper = $path_helper;
		$this->dispatcher = $dispatcher;
		$this->files_factory = $files_factory;
		$this->php_ini = $php_ini;
	}

	/**
	* {@inheritdoc}
	*/
	public function get_data($row)
	{
		return array(
			'src' => $this->controller_helper->route('phpbb_storage_avatar', ['file' => $row['avatar']]),
			'width' => $row['avatar_width'],
			'height' => $row['avatar_height'],
		);
	}

	/**
	* {@inheritdoc}
	*/
	public function prepare_form($request, $template, $user, $row, &$error)
	{
		if (!$this->can_upload())
		{
			return false;
		}

		$use_board = defined('PHPBB_USE_BOARD_URL_PATH') && PHPBB_USE_BOARD_URL_PATH;
		$web_path = $use_board ? generate_board_url() . '/' : $this->path_helper->get_web_root_path();

		$template->assign_vars([
			'AVATAR_ALLOWED_EXTENSIONS' => implode(',', preg_replace('/^/', '.', $this->allowed_extensions)),
			'AVATAR_UPLOAD_SIZE'		=> $this->config['avatar_filesize'],
			'T_ASSETS_PATH'				=> $web_path . '/assets',
		]);

		return true;
	}

	/**
	* {@inheritdoc}
	*/
	public function process_form($request, $template, $user, $row, &$error)
	{
		if (!$this->can_upload())
		{
			return false;
		}

		/** @var \phpbb\files\upload $upload */
		$upload = $this->files_factory->get('upload')
			->set_error_prefix('AVATAR_')
			->set_allowed_extensions($this->allowed_extensions)
			->set_max_filesize($this->config['avatar_filesize'])
			->set_allowed_dimensions(
				$this->config['avatar_min_width'],
				$this->config['avatar_min_height'],
				$this->config['avatar_max_width'],
				$this->config['avatar_max_height'])
			->set_disallowed_content((isset($this->config['mime_triggers']) ? explode('|', $this->config['mime_triggers']) : false));

		$upload_file = $request->file('avatar_upload_file');

		if (empty($upload_file['name']))
		{
			return false;
		}

		/** @var \phpbb\files\filespec_storage $file */
		$file = $upload->handle_upload('files.types.form_storage', 'avatar_upload_file');

		$prefix = $this->config['avatar_salt'] . '_';
		$file->clean_filename('avatar', $prefix, $row['id']);

		// If there was an error during upload, then abort operation
		if (count($file->error))
		{
			$file->remove($this->storage);
			$error = $file->error;
			return false;
		}

		$filedata = array(
			'filename'			=> $file->get('filename'),
			'filesize'			=> $file->get('filesize'),
			'mimetype'			=> $file->get('mimetype'),
			'extension'			=> $file->get('extension'),
			'physical_filename'	=> $file->get('realname'),
			'real_filename'		=> $file->get('uploadname'),
		);

		/**
		* Before moving new file in place (and eventually overwriting the existing avatar with the newly uploaded avatar)
		*
		* @event core.avatar_driver_upload_move_file_before
		* @var	array	filedata			Array containing uploaded file data
		* @var	\phpbb\files\filespec file	Instance of filespec class
		* @var	string	prefix				Prefix for the avatar filename
		* @var	array	row					Array with avatar row data
		* @var	array	error				Array of errors, if filled in by this event file will not be moved
		* @since 3.1.6-RC1
		* @changed 3.1.9-RC1 Added filedata
		* @changed 3.2.3-RC1 Added file
		*/
		$vars = array(
			'filedata',
			'file',
			'prefix',
			'row',
			'error',
		);
		extract($this->dispatcher->trigger_event('core.avatar_driver_upload_move_file_before', compact($vars)));

		unset($filedata);

		if (!count($error))
		{
			// Move file and overwrite any existing image
			$file->move_file($this->storage, true);
		}

		// If there was an error during move, then clean up leftovers
		$error = array_merge($error, $file->error);
		if (count($error))
		{
			$file->remove($this->storage);
			return false;
		}

		// Delete current avatar if not overwritten
		$ext = substr(strrchr($row['avatar'], '.'), 1);
		if ($ext && $ext !== $file->get('extension'))
		{
			$this->delete($row);
		}

		return array(
			'avatar' => $row['id'] . '_' . time() . '.' . $file->get('extension'),
			'avatar_width' => $file->get('width'),
			'avatar_height' => $file->get('height'),
		);
	}

	/**
	* {@inheritdoc}
	*/
	public function prepare_form_acp($user)
	{
		return array(
			'avatar_filesize'		=> array('lang' => 'MAX_FILESIZE',			'validate' => 'int:0',	'type' => 'number:0', 'explain' => true, 'append' => ' ' . $user->lang['BYTES']),
		);
	}

	/**
	* {@inheritdoc}
	*/
	public function delete($row)
	{
		$error = array();
		$prefix = $this->config['avatar_salt'] . '_';
		$ext = substr(strrchr($row['avatar'], '.'), 1);
		$filename = $prefix . $row['id'] . '.' . $ext;

		/**
		* Before deleting an existing avatar
		*
		* @event core.avatar_driver_upload_delete_before
		* @var	string	prefix				Prefix for the avatar filename
		* @var	array	row					Array with avatar row data
		* @var	array	error				Array of errors, if filled in by this event file will not be deleted
		* @since 3.1.6-RC1
		* @changed 3.3.0-a1					Remove destination
		*/
		$vars = array(
			'prefix',
			'row',
			'error',
		);
		extract($this->dispatcher->trigger_event('core.avatar_driver_upload_delete_before', compact($vars)));

		if (!count($error) && $this->storage->exists($filename))
		{
			try
			{
				$this->storage->delete($filename);
				return true;
			}
			catch (storage_exception $e)
			{
				// Fail is covered by return statement below
			}
		}

		return false;
	}

	/**
	* {@inheritdoc}
	*/
	public function get_template_name()
	{
		return 'ucp_avatar_options_upload.html';
	}

	/**
	* Check if user is able to upload an avatar to a temporary folder
	*
	* @return bool True if user can upload, false if not
	*/
	protected function can_upload()
	{
		return $this->php_ini->getBool('file_uploads');
	}
}
