<?php

/**
 * Messenger — permissions for delete for me / delete for both
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\migrations;

class add_delete_permissions extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		$sql = 'SELECT auth_option_id
				FROM ' . $this->table_prefix . "acl_options
				WHERE auth_option = 'u_messenger_delete_me'";
		$result = $this->db->sql_query($sql);
		$installed = (bool) $this->db->sql_fetchfield('auth_option_id');
		$this->db->sql_freeresult($result);

		return $installed;
	}

	public static function depends_on()
	{
		return ['\negentiendertien\messenger\migrations\add_messenger_use_permission'];
	}

	public function update_data()
	{
		return [
			['permission.add', ['u_messenger_delete_me', true]],
			['permission.add', ['u_messenger_delete_both', true]],
			['permission.permission_set', ['REGISTERED', 'u_messenger_delete_me', 'group']],
			['permission.permission_set', ['REGISTERED', 'u_messenger_delete_both', 'group']],
			['permission.permission_set', ['ADMINISTRATORS', 'u_messenger_delete_me', 'group']],
			['permission.permission_set', ['ADMINISTRATORS', 'u_messenger_delete_both', 'group']],
		];
	}

	public function revert_data()
	{
		return [
			['permission.permission_set', ['REGISTERED', 'u_messenger_delete_me', 'group', false]],
			['permission.permission_set', ['REGISTERED', 'u_messenger_delete_both', 'group', false]],
			['permission.permission_set', ['ADMINISTRATORS', 'u_messenger_delete_me', 'group', false]],
			['permission.permission_set', ['ADMINISTRATORS', 'u_messenger_delete_both', 'group', false]],
			['permission.remove', ['u_messenger_delete_me']],
			['permission.remove', ['u_messenger_delete_both']],
		];
	}
}
