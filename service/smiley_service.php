<?php

/**
 * Messenger — forum smilies for compose
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\service;

class smiley_service
{
    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var \phpbb\config\config */
    protected $config;

    /** @var \phpbb\path_helper */
    protected $path_helper;

    /** @var string */
    protected $t_smilies;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        \phpbb\config\config $config,
        \phpbb\path_helper $path_helper,
        $table_prefix
    ) {
        $this->db          = $db;
        $this->config      = $config;
        $this->path_helper = $path_helper;
        $this->t_smilies   = $table_prefix . 'smilies';
    }

    /**
     * @return array<int, array{code: string, url: string, width: int, height: int}>
     */
    public function get_posting_smilies()
    {
        $sql = 'SELECT code, smiley_url, smiley_width, smiley_height
                FROM ' . $this->t_smilies . '
                WHERE display_on_posting = 1
                ORDER BY smiley_order ASC';
        $result = $this->db->sql_query($sql);

        $smilies_path = trim((string) $this->config['smilies_path'], '/');
        $web_root = $this->path_helper->get_web_root_path();
        $smilies = [];
        $seen_files = [];

        while ($row = $this->db->sql_fetchrow($result))
        {
            $file = trim((string) $row['smiley_url']);
            if ($file === '' || isset($seen_files[$file]))
            {
                continue;
            }

            $seen_files[$file] = true;
            $url = $web_root . $smilies_path . '/' . str_replace(' ', '%20', $file);

            $smilies[] = [
                'code'   => (string) $row['code'],
                'url'    => $this->path_helper->update_web_root_path($url),
                'width'  => max(1, (int) $row['smiley_width']),
                'height' => max(1, (int) $row['smiley_height']),
            ];
        }
        $this->db->sql_freeresult($result);

        return $smilies;
    }
}
