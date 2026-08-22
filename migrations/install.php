<?php

/**
 * Messenger — installatie
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\migrations;

class install extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'messenger_chat_pins');
    }

    public static function depends_on()
    {
        return ['\phpbb\db\migration\data\v330\v330'];
    }

    public function update_schema()
    {
        $p = $this->table_prefix;

        return [
            'add_tables' => [
                $p . 'messenger_chat_pins' => [
                    'COLUMNS' => [
                        'user_id'    => ['UINT', 0],
                        'partner_id' => ['UINT', 0],
                        'pinned_at'  => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => ['user_id', 'partner_id'],
                    'KEYS' => [
                        'idx_user_pinned' => ['INDEX', ['user_id', 'pinned_at']],
                    ],
                ],

                $p . 'messenger_message_pins' => [
                    'COLUMNS' => [
                        'user_id'   => ['UINT', 0],
                        'msg_id'    => ['UINT', 0],
                        'pinned_at' => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => ['user_id', 'msg_id'],
                    'KEYS' => [
                        'idx_user_pinned' => ['INDEX', ['user_id', 'pinned_at']],
                        'idx_msg'         => ['INDEX', ['msg_id']],
                    ],
                ],

                $p . 'messenger_favorites' => [
                    'COLUMNS' => [
                        'user_id'  => ['UINT', 0],
                        'msg_id'   => ['UINT', 0],
                        'saved_at' => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => ['user_id', 'msg_id'],
                    'KEYS' => [
                        'idx_user_saved' => ['INDEX', ['user_id', 'saved_at']],
                    ],
                ],

                $p . 'messenger_search_index' => [
                    'COLUMNS' => [
                        'msg_id'      => ['UINT', 0],
                        'user_id'     => ['UINT', 0],
                        'partner_id'  => ['UINT', 0],
                        'search_text' => ['MTEXT', ''],
                        'indexed_at'  => ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => ['msg_id', 'user_id'],
                    'KEYS' => [
                        'idx_user_partner' => ['INDEX', ['user_id', 'partner_id']],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        $p = $this->table_prefix;

        return [
            'drop_tables' => [
                $p . 'messenger_search_index',
                $p . 'messenger_favorites',
                $p . 'messenger_message_pins',
                $p . 'messenger_chat_pins',
            ],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['messenger_enabled', 1]],
            ['config.add', ['messenger_poll_interval', 8]],
            ['config.add', ['messenger_subject_optional', 1]],
            ['config.add', ['messenger_allow_edit_after_read', 0]],
            ['config.add', ['messenger_allow_delete_for_both', 1]],
            ['config.add', ['messenger_visible_pm_link', 1]],
            ['config.add', ['messenger_search_index_built', 0]],

            ['module.add', [
                'acp',
                'ACP_CAT_DOT_MODS',
                'ACP_MESSENGER_TITLE',
            ]],
            ['module.add', [
                'acp',
                'ACP_MESSENGER_TITLE',
                [
                    'module_basename' => '\negentiendertien\messenger\acp\main_module',
                    'modes'           => ['settings'],
                ],
            ]],

            ['permission.add', ['a_messenger_manage', true]],
            ['permission.permission_set', ['ADMINISTRATORS', 'a_messenger_manage', 'group']],

            ['permission.add', ['u_messenger_use', true]],
            ['permission.permission_set', ['ADMINISTRATORS', 'u_messenger_use', 'group']],
        ];
    }

    public function revert_data()
    {
        return [
            ['custom', [[$this, 'safe_remove_acp_modules']]],
            ['permission.permission_set', ['ADMINISTRATORS', 'a_messenger_manage', 'group', false]],
            ['permission.remove', ['a_messenger_manage']],
            ['permission.permission_set', ['ADMINISTRATORS', 'u_messenger_use', 'group', false]],
            ['permission.remove', ['u_messenger_use']],
            ['config.remove', ['messenger_search_index_built']],
            ['config.remove', ['messenger_visible_pm_link']],
            ['config.remove', ['messenger_allow_delete_for_both']],
            ['config.remove', ['messenger_allow_edit_after_read']],
            ['config.remove', ['messenger_subject_optional']],
            ['config.remove', ['messenger_poll_interval']],
            ['config.remove', ['messenger_enabled']],
        ];
    }

    public function safe_remove_acp_modules()
    {
        $basename = '\\negentiendertien\\messenger\\acp\\main_module';

        $sql = 'DELETE FROM ' . $this->table_prefix . "modules
                WHERE module_class = 'acp'
                  AND module_basename = '" . $this->db->sql_escape($basename) . "'";
        $this->db->sql_query($sql);

        $sql = 'DELETE FROM ' . $this->table_prefix . "modules
                WHERE module_class = 'acp'
                  AND module_langname = 'ACP_MESSENGER_TITLE'";
        $this->db->sql_query($sql);
    }
}
