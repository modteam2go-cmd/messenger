<?php

/**
 * Messenger — message search
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\service;

class search_service
{
    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var \phpbb\config\config */
    protected $config;

    /** @var string */
    protected $root_path;

    /** @var string */
    protected $php_ext;

    /** @var string */
    protected $t_search;

    /** @var string */
    protected $t_privmsgs;

    /** @var string */
    protected $t_privmsgs_to;

    /** @var string */
    protected $t_users;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        \phpbb\config\config $config,
        $root_path,
        $php_ext,
        $table_prefix
    ) {
        $this->db           = $db;
        $this->config       = $config;
        $this->root_path    = $root_path;
        $this->php_ext      = $php_ext;

        $this->t_search      = $table_prefix . 'messenger_search_index';
        $this->t_privmsgs    = $table_prefix . 'privmsgs';
        $this->t_privmsgs_to = $table_prefix . 'privmsgs_to';
        $this->t_users       = $table_prefix . 'users';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search($user_id, $query, $partner_id = 0, $limit = 25)
    {
        $user_id    = (int) $user_id;
        $partner_id = (int) $partner_id;
        $limit      = max(1, min(50, (int) $limit));
        $query      = trim((string) $query);

        if ($user_id <= 0 || $query === '' || utf8_strlen($query) < 2)
        {
            return [];
        }

        $indexed = $this->search_index($user_id, $query, $partner_id, $limit);
        if (!empty($indexed))
        {
            return $indexed;
        }

        return $this->search_direct($user_id, $query, $partner_id, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function search_index($user_id, $query, $partner_id, $limit)
    {
        if (empty($this->config['messenger_search_index_built']))
        {
            return [];
        }

        $like = $this->build_like($query);

        $sql = sprintf(
            'SELECT si.msg_id, si.partner_id, si.search_text, p.message_time, p.author_id,
                    u.username AS partner_username, u.user_colour AS partner_colour
             FROM %1$s si
             INNER JOIN %2$s p ON p.msg_id = si.msg_id
             INNER JOIN %3$s u ON u.user_id = si.partner_id
             WHERE si.user_id = %4$d
                 AND %5$s LIKE %6$s',
            $this->t_search,
            $this->t_privmsgs,
            $this->t_users,
            $user_id,
            $this->db->sql_lower_text('si.search_text'),
            $this->db->sql_like_expression($like)
        );

        if ($partner_id > 0)
        {
            $sql .= sprintf(' AND si.partner_id = %d', $partner_id);
        }

        $sql .= ' ORDER BY p.message_time DESC';

        return $this->fetch_search_rows($sql, $limit, true);
    }

    /**
     * Direct PM search — works without a pre-built index.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function search_direct($user_id, $query, $partner_id, $limit)
    {
        $like = $this->build_like($query);

        $sql = 'SELECT p.msg_id, p.author_id, p.message_time, p.message_text, p.bbcode_uid, p.bbcode_bitfield,
                       IF(p.author_id = ' . $user_id . ', pt_other.user_id, p.author_id) AS partner_id,
                       u.username AS partner_username, u.user_colour AS partner_colour
                FROM ' . $this->t_privmsgs . ' p
                INNER JOIN ' . $this->t_privmsgs_to . ' pt
                    ON pt.msg_id = p.msg_id
                    AND pt.user_id = ' . $user_id . '
                    AND pt.pm_deleted = 0
                INNER JOIN ' . $this->t_privmsgs_to . ' pt_other
                    ON pt_other.msg_id = p.msg_id
                    AND pt_other.user_id <> ' . $user_id . '
                    AND pt_other.pm_deleted = 0
                INNER JOIN ' . $this->t_users . ' u
                    ON u.user_id = IF(p.author_id = ' . $user_id . ', pt_other.user_id, p.author_id)
                WHERE ' . $this->db->sql_lower_text('p.message_text') . " LIKE '" . $this->db->sql_escape($like) . "'";

        if ($partner_id > 0)
        {
            $sql .= ' AND IF(p.author_id = ' . $user_id . ', pt_other.user_id, p.author_id) = ' . $partner_id;
        }

        $sql .= ' ORDER BY p.message_time DESC';

        return $this->fetch_search_rows($sql, $limit, false, true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetch_search_rows($sql, $limit, $use_index_text, $dedupe = false)
    {
        $result = $this->db->sql_query_limit($sql, $dedupe ? ($limit * 3) : $limit);
        $rows = [];
        $seen = [];

        while ($row = $this->db->sql_fetchrow($result))
        {
            $msg_id = (int) $row['msg_id'];
            if ($dedupe)
            {
                if (isset($seen[$msg_id]))
                {
                    continue;
                }
                $seen[$msg_id] = true;
            }
            if ($use_index_text)
            {
                $snippet = (string) $row['search_text'];
            }
            else
            {
                $snippet = $this->extract_search_text(
                    (string) $row['message_text'],
                    (string) ($row['bbcode_uid'] ?? ''),
                    (string) ($row['bbcode_bitfield'] ?? '')
                );
            }

            if (utf8_strlen($snippet) > 160)
            {
                $snippet = utf8_substr($snippet, 0, 160) . '…';
            }

            $rows[] = [
                'msg_id'           => (int) $row['msg_id'],
                'partner_id'       => (int) $row['partner_id'],
                'author_id'        => (int) $row['author_id'],
                'message_time'     => (int) $row['message_time'],
                'partner_username' => (string) $row['partner_username'],
                'partner_colour'   => (string) ($row['partner_colour'] ?? ''),
                'snippet'          => $snippet,
            ];
        }
        $this->db->sql_freeresult($result);

        return $rows;
    }

    protected function build_like($query)
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], utf8_strtolower($query)) . '%';
    }

    public function rebuild_index()
    {
        $this->db->sql_query('DELETE FROM ' . $this->t_search);

        $sql = 'SELECT p.msg_id
                FROM ' . $this->t_privmsgs . ' p
                ORDER BY p.msg_id ASC';
        $result = $this->db->sql_query($sql);

        while ($row = $this->db->sql_fetchrow($result))
        {
            $this->index_message((int) $row['msg_id']);
        }
        $this->db->sql_freeresult($result);

        $this->config->set('messenger_search_index_built', 1);
    }

    public function index_message($msg_id)
    {
        $msg_id = (int) $msg_id;
        if ($msg_id <= 0)
        {
            return;
        }

        $sql = sprintf(
            'SELECT p.msg_id, p.author_id, p.message_text, p.bbcode_uid, p.bbcode_bitfield
             FROM %s p
             WHERE p.msg_id = %d',
            $this->t_privmsgs,
            $msg_id
        );
        $result = $this->db->sql_query($sql);
        $message = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$message)
        {
            $this->remove_message($msg_id);
            return;
        }

        $search_text = $this->extract_search_text(
            (string) $message['message_text'],
            (string) $message['bbcode_uid'],
            (string) $message['bbcode_bitfield']
        );

        if ($search_text === '')
        {
            $this->remove_message($msg_id);
            return;
        }

        $author_id = (int) $message['author_id'];
        $participants = $this->get_message_participants($msg_id, $author_id);

        $this->remove_message($msg_id);

        $now = time();
        foreach ($participants as $viewer_id => $partner_id)
        {
            $sql = 'INSERT INTO ' . $this->t_search . ' ' . $this->db->sql_build_array('INSERT', [
                'msg_id'      => $msg_id,
                'user_id'     => $viewer_id,
                'partner_id'  => $partner_id,
                'search_text' => $search_text,
                'indexed_at'  => $now,
            ]);
            $this->db->sql_query($sql);
        }
    }

    public function remove_message($msg_id)
    {
        $msg_id = (int) $msg_id;
        if ($msg_id <= 0)
        {
            return;
        }

        $sql = sprintf(
            'DELETE FROM %s
             WHERE msg_id = %d',
            $this->t_search,
            $msg_id
        );
        $this->db->sql_query($sql);
    }

    /**
     * @return array<int, int> viewer_id => partner_id
     */
    protected function get_message_participants($msg_id, $author_id)
    {
        $msg_id    = (int) $msg_id;
        $author_id = (int) $author_id;

        $sql = sprintf(
            'SELECT user_id
             FROM %s
             WHERE msg_id = %d
                 AND pm_deleted = 0',
            $this->t_privmsgs_to,
            $msg_id
        );
        $result = $this->db->sql_query($sql);

        $user_ids = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $user_ids[] = (int) $row['user_id'];
        }
        $this->db->sql_freeresult($result);

        $user_ids = array_values(array_unique(array_filter($user_ids)));
        if (count($user_ids) < 2)
        {
            return [];
        }

        $participants = [];
        foreach ($user_ids as $viewer_id)
        {
            $partner_id = ($viewer_id === $author_id)
                ? (int) current(array_values(array_diff($user_ids, [$author_id])))
                : $author_id;

            if ($partner_id > 0 && $partner_id !== $viewer_id)
            {
                $participants[$viewer_id] = $partner_id;
            }
        }

        return $participants;
    }

    protected function extract_search_text($message_text, $bbcode_uid, $bbcode_bitfield)
    {
        if (!function_exists('generate_text_for_display'))
        {
            include $this->root_path . 'includes/functions_content.' . $this->php_ext;
        }

        $text = generate_text_for_display(
            $message_text,
            $bbcode_uid,
            $bbcode_bitfield,
            OPTION_FLAG_BBCODE + OPTION_FLAG_SMILIES + OPTION_FLAG_LINKS
        );

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('#\s+#u', ' ', $text));

        if ($text === '')
        {
            $text = preg_replace('#\[.*?\]#s', ' ', $message_text);
            $text = trim(preg_replace('#\s+#u', ' ', $text));
        }

        return utf8_strtolower($text);
    }
}
