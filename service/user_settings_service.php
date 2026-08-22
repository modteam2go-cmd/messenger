<?php

/**
 * Messenger — per-user UI settings
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\service;

class user_settings_service
{
    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var \phpbb\user */
    protected $user;

    /** @var string */
    protected $table_prefix;

    /** @var string */
    protected $t_user_settings;

    /** @var string */
    protected $wallpaper_dir;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        \phpbb\user $user,
        $table_prefix,
        $root_path
    ) {
        $this->db              = $db;
        $this->user            = $user;
        $this->table_prefix    = $table_prefix;
        $this->t_user_settings = $table_prefix . 'messenger_user_settings';
        $this->wallpaper_dir   = rtrim((string) $root_path, '/\\') . '/files/messenger_wallpapers';
    }

    /**
     * @return array<string, string>
     */
    public function get_wallpaper_presets()
    {
        return [
            'default'  => 'MESSENGER_WALLPAPER_DEFAULT',
            'classic'  => 'MESSENGER_WALLPAPER_CLASSIC',
            'sage'     => 'MESSENGER_WALLPAPER_SAGE',
            'sky'      => 'MESSENGER_WALLPAPER_SKY',
            'lavender' => 'MESSENGER_WALLPAPER_LAVENDER',
            'peach'    => 'MESSENGER_WALLPAPER_PEACH',
            'slate'    => 'MESSENGER_WALLPAPER_SLATE',
            'midnight' => 'MESSENGER_WALLPAPER_MIDNIGHT',
            'emerald'  => 'MESSENGER_WALLPAPER_EMERALD',
        ];
    }

    public function is_valid_preset($preset)
    {
        $preset = (string) $preset;

        return $preset === 'default' || $preset === 'custom' || isset($this->get_wallpaper_presets()[$preset]);
    }

    /**
     * @return array{wallpaper: string, custom_url: string, custom_file: string}
     */
    public function get_chat_wallpaper($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0)
        {
            return $this->empty_wallpaper();
        }

        $sql = 'SELECT chat_wallpaper, chat_wallpaper_file
                FROM ' . $this->t_user_settings . '
                WHERE user_id = ' . $user_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return $this->empty_wallpaper();
        }

        $wallpaper = (string) ($row['chat_wallpaper'] ?? '');
        $file = (string) ($row['chat_wallpaper_file'] ?? '');

        if ($wallpaper === 'custom' && $file !== '' && $this->is_owned_wallpaper_file($user_id, $file))
        {
            return [
                'wallpaper'   => 'custom',
                'custom_url'  => '',
                'custom_file' => $file,
            ];
        }

        if (!$this->is_valid_preset($wallpaper))
        {
            return $this->empty_wallpaper();
        }

        return [
            'wallpaper'   => $wallpaper === '' ? 'default' : $wallpaper,
            'custom_url'  => '',
            'custom_file' => '',
        ];
    }

    public function set_chat_wallpaper($user_id, $wallpaper)
    {
        $user_id = (int) $user_id;
        $wallpaper = (string) $wallpaper;

        if ($user_id <= 0 || !$this->is_valid_preset($wallpaper))
        {
            return false;
        }

        if ($wallpaper === 'default')
        {
            $this->delete_custom_wallpaper_file($user_id);
            $this->delete_user_settings($user_id);

            return true;
        }

        if ($wallpaper !== 'custom')
        {
            $this->delete_custom_wallpaper_file($user_id);
        }

        $sql_ary = [
            'chat_wallpaper'         => $wallpaper,
            'chat_wallpaper_file'    => $wallpaper === 'custom' ? $this->get_custom_wallpaper_filename($user_id) : '',
            'chat_wallpaper_updated' => time(),
        ];

        if ($this->user_settings_row_exists($user_id))
        {
            $sql = 'UPDATE ' . $this->t_user_settings . '
                SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                WHERE user_id = ' . $user_id;
            $this->db->sql_query($sql);
        }
        else
        {
            $sql_ary['user_id'] = $user_id;
            $sql = 'INSERT INTO ' . $this->t_user_settings . ' ' . $this->db->sql_build_array('INSERT', $sql_ary);
            $this->db->sql_query($sql);
        }

        return true;
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function save_custom_wallpaper($user_id, $binary)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || $binary === '' || strlen($binary) > 2097152)
        {
            return ['success' => false, 'error' => 'MESSENGER_WALLPAPER_UPLOAD_FAILED'];
        }

        if (!is_dir($this->wallpaper_dir) && !@mkdir($this->wallpaper_dir, 0755, true) && !is_dir($this->wallpaper_dir))
        {
            return ['success' => false, 'error' => 'MESSENGER_WALLPAPER_STORAGE_UNAVAILABLE'];
        }

        $filename = $this->get_custom_wallpaper_filename($user_id);
        $path = $this->wallpaper_dir . '/' . $filename;

        if (@file_put_contents($path, $binary) === false)
        {
            return ['success' => false, 'error' => 'MESSENGER_WALLPAPER_UPLOAD_FAILED'];
        }

        $sql_ary = [
            'chat_wallpaper'         => 'custom',
            'chat_wallpaper_file'    => $filename,
            'chat_wallpaper_updated' => time(),
        ];

        if ($this->user_settings_row_exists($user_id))
        {
            $sql = 'UPDATE ' . $this->t_user_settings . '
                SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                WHERE user_id = ' . $user_id;
            $this->db->sql_query($sql);
        }
        else
        {
            $sql_ary['user_id'] = $user_id;
            $sql = 'INSERT INTO ' . $this->t_user_settings . ' ' . $this->db->sql_build_array('INSERT', $sql_ary);
            $this->db->sql_query($sql);
        }

        return ['success' => true];
    }

    public function get_custom_wallpaper_path($user_id)
    {
        $settings = $this->get_chat_wallpaper((int) $user_id);
        if (($settings['wallpaper'] ?? '') !== 'custom' || empty($settings['custom_file']))
        {
            return '';
        }

        $path = $this->wallpaper_dir . '/' . $settings['custom_file'];
        if (!is_file($path))
        {
            return '';
        }

        return $path;
    }

    protected function get_custom_wallpaper_filename($user_id)
    {
        return 'wallpaper_' . (int) $user_id . '.jpg';
    }

    protected function is_owned_wallpaper_file($user_id, $filename)
    {
        return $filename === $this->get_custom_wallpaper_filename($user_id);
    }

    protected function delete_custom_wallpaper_file($user_id)
    {
        $path = $this->wallpaper_dir . '/' . $this->get_custom_wallpaper_filename((int) $user_id);
        if (is_file($path))
        {
            @unlink($path);
        }
    }

    protected function delete_user_settings($user_id)
    {
        $sql = 'DELETE FROM ' . $this->t_user_settings . '
            WHERE user_id = ' . (int) $user_id;
        $this->db->sql_query($sql);
    }

    protected function user_settings_row_exists($user_id)
    {
        $sql = 'SELECT user_id
                FROM ' . $this->t_user_settings . '
                WHERE user_id = ' . (int) $user_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $exists = (bool) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $exists;
    }

    /**
     * @return array{wallpaper: string, custom_url: string, custom_file: string}
     */
    protected function empty_wallpaper()
    {
        return [
            'wallpaper'   => 'default',
            'custom_url'  => '',
            'custom_file' => '',
        ];
    }
}
