<?php

/**
 * Messenger — group chats for selected staff groups
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\migrations;

class add_group_chat extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'messenger_groups');
    }

    public static function depends_on()
    {
        return ['\negentiendertien\messenger\migrations\add_show_header_footer_setting'];
    }

    public function update_schema()
    {
        $p = $this->table_prefix;

        return [
            'add_tables' => [
                $p . 'messenger_groups' => [
                    'COLUMNS' => [
                        'group_id' => ['UINT', null, 'auto_increment'],
                        'group_title' => ['VCHAR:255', ''],
                        'creator_id' => ['UINT', 0],
                        'created_at' => ['TIMESTAMP', 0],
                        'updated_at' => ['TIMESTAMP', 0],
                        'last_message_time' => ['TIMESTAMP', 0],
                        'last_message_preview' => ['VCHAR:255', ''],
                    ],
                    'PRIMARY_KEY' => 'group_id',
                    'KEYS' => [
                        'updated' => ['INDEX', ['last_message_time']],
                    ],
                ],
                $p . 'messenger_group_members' => [
                    'COLUMNS' => [
                        'group_id' => ['UINT', 0],
                        'user_id' => ['UINT', 0],
                        'joined_at' => ['TIMESTAMP', 0],
                        'last_read_msg_id' => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => ['group_id', 'user_id'],
                    'KEYS' => [
                        'user_id' => ['INDEX', ['user_id']],
                    ],
                ],
                $p . 'messenger_group_messages' => [
                    'COLUMNS' => [
                        'message_id' => ['UINT', null, 'auto_increment'],
                        'group_id' => ['UINT', 0],
                        'author_id' => ['UINT', 0],
                        'message_text' => ['MTEXT', ''],
                        'message_time' => ['TIMESTAMP', 0],
                        'bbcode_uid' => ['VCHAR:8', ''],
                        'bbcode_bitfield' => ['VCHAR:255', ''],
                        'bbcode_options' => ['UINT:11', 7],
                    ],
                    'PRIMARY_KEY' => 'message_id',
                    'KEYS' => [
                        'group_time' => ['INDEX', ['group_id', 'message_time']],
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
                $p . 'messenger_group_messages',
                $p . 'messenger_group_members',
                $p . 'messenger_groups',
            ],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['messenger_group_chat_enabled', 1]],
            ['config.add', ['messenger_group_chat_groups', '']],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['messenger_group_chat_enabled']],
            ['config.remove', ['messenger_group_chat_groups']],
        ];
    }
}
