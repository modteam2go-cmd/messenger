<?php

/**
 * Messenger — per-user chat wallpaper setting
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\migrations;

class add_user_wallpaper extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'messenger_user_settings');
    }

    public static function depends_on()
    {
        return ['\negentiendertien\messenger\migrations\install'];
    }

    public function update_schema()
    {
        $p = $this->table_prefix;

        return [
            'add_tables' => [
                $p . 'messenger_user_settings' => [
                    'COLUMNS' => [
                        'user_id'               => ['UINT', 0],
                        'chat_wallpaper'        => ['VCHAR:64', ''],
                        'chat_wallpaper_file'   => ['VCHAR:255', ''],
                        'chat_wallpaper_updated'=> ['TIMESTAMP', 0],
                    ],
                    'PRIMARY_KEY' => 'user_id',
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'messenger_user_settings',
            ],
        ];
    }
}
