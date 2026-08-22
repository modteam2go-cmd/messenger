<?php

/**
 * Messenger — user permission for messenger access
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\migrations;

class add_messenger_use_permission extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        $sql = 'SELECT auth_option_id
                FROM ' . $this->table_prefix . "acl_options
                WHERE auth_option = 'u_messenger_use'";
        $result = $this->db->sql_query($sql);
        $installed = (bool) $this->db->sql_fetchfield('auth_option_id');
        $this->db->sql_freeresult($result);

        return $installed;
    }

    public static function depends_on()
    {
        return ['\negentiendertien\messenger\migrations\install'];
    }

    public function update_data()
    {
        return [
            ['permission.add', ['u_messenger_use', true]],
            ['permission.permission_set', ['ADMINISTRATORS', 'u_messenger_use', 'group']],
        ];
    }

    public function revert_data()
    {
        return [
            ['permission.permission_set', ['ADMINISTRATORS', 'u_messenger_use', 'group', false]],
            ['permission.remove', ['u_messenger_use']],
        ];
    }
}
