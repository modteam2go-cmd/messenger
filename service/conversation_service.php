<?php

/**
 * Messenger — conversation grouping
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\service;

class conversation_service
{
    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var \phpbb\config\config */
    protected $config;

    /** @var \phpbb\user */
    protected $user;

    /** @var \phpbb\auth\auth */
    protected $auth;

    /** @var \phpbb\avatar\manager */
    protected $avatar;

    /** @var pin_service */
    protected $pin;

    /** @var attachment_service */
    protected $attachment;

    /** @var string */
    protected $table_prefix;

    /** @var string */
    protected $t_privmsgs;

    /** @var string */
    protected $t_privmsgs_to;

    /** @var string */
    protected $t_users;

    /** @var string */
    protected $t_sessions;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        \phpbb\config\config $config,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        \phpbb\avatar\manager $avatar,
        pin_service $pin,
        attachment_service $attachment,
        $table_prefix
    ) {
        $this->db           = $db;
        $this->config       = $config;
        $this->user         = $user;
        $this->auth         = $auth;
        $this->avatar       = $avatar;
        $this->pin          = $pin;
        $this->attachment   = $attachment;
        $this->table_prefix = $table_prefix;

        $this->t_privmsgs    = $table_prefix . 'privmsgs';
        $this->t_privmsgs_to = $table_prefix . 'privmsgs_to';
        $this->t_users       = $table_prefix . 'users';
        $this->t_sessions    = $table_prefix . 'sessions';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_roster($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0)
        {
            return [];
        }

        $rows = $this->fetch_conversation_rows($user_id);
        $pinned = $this->pin->get_pinned_chat_ids($user_id);

        $roster = [];
        foreach ($rows as $row)
        {
            $partner_id = (int) $row['partner_id'];
            if ($partner_id <= 0 || $partner_id === $user_id)
            {
                continue;
            }

            if (!isset($roster[$partner_id]))
            {
                $roster[$partner_id] = [
                    'partner_id'    => $partner_id,
                    'last_time'     => 0,
                    'unread_count'  => 0,
                    'last_msg_id'   => 0,
                    'last_preview'  => '',
                    'last_author_id'=> 0,
                    'is_pinned'     => in_array($partner_id, $pinned, true),
                ];
            }

            $entry = &$roster[$partner_id];
            $msg_time = (int) $row['message_time'];

            if ($msg_time >= $entry['last_time'])
            {
                $entry['last_time']      = $msg_time;
                $entry['last_msg_id']    = (int) $row['msg_id'];
                $entry['last_preview']   = $this->build_preview($row);
                $entry['last_author_id'] = (int) $row['author_id'];
            }

            if ((int) $row['pm_unread'] === 1 && (int) $row['author_id'] !== $user_id)
            {
                $entry['unread_count']++;
            }
        }

        $this->enrich_partners($roster);

        usort($roster, function ($a, $b) {
            if ($a['is_pinned'] !== $b['is_pinned'])
            {
                return $a['is_pinned'] ? -1 : 1;
            }

            return $b['last_time'] <=> $a['last_time'];
        });

        return array_values($roster);
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, total: int, partner: array<string, mixed>|null, has_older: bool, oldest_msg_id: int}
     */
    public function get_chat($user_id, $partner_id, $page = 1, $per_page = 10)
    {
        $user_id    = (int) $user_id;
        $partner_id = (int) $partner_id;
        $per_page   = max(1, min(100, (int) $per_page));

        if ($user_id <= 0 || $partner_id <= 0)
        {
            return ['messages' => [], 'total' => 0, 'partner' => null, 'has_older' => false, 'oldest_msg_id' => 0];
        }

        $partner = $this->get_partner_profile($partner_id);
        if (!$partner)
        {
            return ['messages' => [], 'total' => 0, 'partner' => null, 'has_older' => false, 'oldest_msg_id' => 0];
        }

        $total = $this->count_chat_messages($user_id, $partner_id);
        $rows = $this->fetch_message_rows($user_id, $partner_id, $per_page);
        $messages = [];

        foreach ($rows as $row)
        {
            $messages[] = $this->format_message_row($row, $user_id);
        }

        $oldest_msg_id = !empty($messages) ? (int) $messages[0]['msg_id'] : 0;

        return [
            'messages'      => $messages,
            'total'         => $total,
            'partner'       => $partner,
            'has_older'     => $this->has_messages_before($user_id, $partner_id, $oldest_msg_id),
            'oldest_msg_id' => $oldest_msg_id,
        ];
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, has_older: bool, oldest_msg_id: int}
     */
    public function get_older_messages($user_id, $partner_id, $before_msg_id, $limit = 20)
    {
        $user_id       = (int) $user_id;
        $partner_id    = (int) $partner_id;
        $before_msg_id = (int) $before_msg_id;
        $limit         = max(1, min(100, (int) $limit));

        if ($user_id <= 0 || $partner_id <= 0 || $before_msg_id <= 0)
        {
            return ['messages' => [], 'has_older' => false, 'oldest_msg_id' => 0];
        }

        $rows = $this->fetch_message_rows($user_id, $partner_id, $limit, $before_msg_id);
        $messages = [];

        foreach ($rows as $row)
        {
            $messages[] = $this->format_message_row($row, $user_id);
        }

        $oldest_msg_id = !empty($messages) ? (int) $messages[0]['msg_id'] : 0;

        return [
            'messages'      => $messages,
            'has_older'     => $this->has_messages_before($user_id, $partner_id, $oldest_msg_id),
            'oldest_msg_id' => $oldest_msg_id,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_message($user_id, $partner_id, $msg_id)
    {
        $user_id    = (int) $user_id;
        $partner_id = (int) $partner_id;
        $msg_id     = (int) $msg_id;

        if ($user_id <= 0 || $partner_id <= 0 || $msg_id <= 0)
        {
            return null;
        }

        $sql = $this->build_message_select_sql($user_id, $partner_id) . '
                WHERE ' . $this->get_partner_sql($user_id, $partner_id) . '
                    AND p.msg_id = ' . $msg_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return null;
        }

        $message = $this->format_message_row($row, $user_id);

        return $message;
    }

    /**
     * @return array<int, int>
     */
    public function get_chat_msg_ids($user_id, $partner_id)
    {
        $user_id    = (int) $user_id;
        $partner_id = (int) $partner_id;

        if ($user_id <= 0 || $partner_id <= 0)
        {
            return [];
        }

        $sql = 'SELECT p.msg_id
                FROM ' . $this->t_privmsgs . ' p
                INNER JOIN ' . $this->t_privmsgs_to . ' pt
                    ON pt.msg_id = p.msg_id
                    AND pt.user_id = ' . $user_id . '
                    AND pt.pm_deleted = 0
                WHERE ' . $this->get_partner_sql($user_id, $partner_id) . '
                ORDER BY p.msg_id ASC';
        $result = $this->db->sql_query($sql);

        $ids = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $ids[] = (int) $row['msg_id'];
        }
        $this->db->sql_freeresult($result);

        return $ids;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_new_messages($user_id, $partner_id, $since_msg_id)
    {
        $user_id      = (int) $user_id;
        $partner_id   = (int) $partner_id;
        $since_msg_id = (int) $since_msg_id;

        if ($user_id <= 0 || $partner_id <= 0)
        {
            return [];
        }

        $sql = $this->build_message_select_sql($user_id, $partner_id) . '
                WHERE ' . $this->get_partner_sql($user_id, $partner_id) . '
                    AND p.msg_id > ' . $since_msg_id . '
                ORDER BY p.message_time ASC';
        $result = $this->db->sql_query($sql);

        $rows = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        $messages = [];
        foreach ($rows as $row)
        {
            $messages[] = $this->format_message_row($row, $user_id);
        }

        return $messages;
    }

    /**
     * Read-receipt status for own messages in a chat (for polling updates).
     *
     * @return array<int, array{msg_id: int, read_status: string}>
     */
    public function get_own_read_statuses($user_id, $partner_id)
    {
        $user_id    = (int) $user_id;
        $partner_id = (int) $partner_id;

        if ($user_id <= 0 || $partner_id <= 0)
        {
            return [];
        }

        $sql = 'SELECT p.msg_id, peer_pt.pm_unread AS peer_pm_unread
                FROM ' . $this->t_privmsgs . ' p
                INNER JOIN ' . $this->t_privmsgs_to . ' pt
                    ON pt.msg_id = p.msg_id
                    AND pt.user_id = ' . $user_id . '
                    AND pt.pm_deleted = 0
                LEFT JOIN ' . $this->t_privmsgs_to . ' peer_pt
                    ON peer_pt.msg_id = p.msg_id
                    AND peer_pt.user_id = ' . $partner_id . '
                    AND peer_pt.author_id = ' . $user_id . '
                    AND peer_pt.pm_deleted = 0
                WHERE ' . $this->get_partner_sql($user_id, $partner_id) . '
                    AND p.author_id = ' . $user_id . '
                ORDER BY p.msg_id ASC';
        $this->db->sql_query($sql);

        $statuses = [];
        while ($row = $this->db->sql_fetchrow())
        {
            $statuses[] = [
                'msg_id'      => (int) $row['msg_id'],
                'read_status' => $this->resolve_read_status($row),
            ];
        }
        $this->db->sql_freeresult();

        return $statuses;
    }

    /**
     * From message IDs currently shown in the UI, return those no longer visible.
     *
     * @param int   $user_id
     * @param int   $partner_id
     * @param int[] $msg_ids
     * @return int[]
     */
    public function get_invisible_message_ids($user_id, $partner_id, array $msg_ids)
    {
        $user_id    = (int) $user_id;
        $partner_id = (int) $partner_id;
        $msg_ids    = array_values(array_unique(array_filter(array_map('intval', $msg_ids))));

        if ($user_id <= 0 || $partner_id <= 0 || empty($msg_ids))
        {
            return [];
        }

        $sql = 'SELECT p.msg_id
                FROM ' . $this->t_privmsgs . ' p
                INNER JOIN ' . $this->t_privmsgs_to . ' pt
                    ON pt.msg_id = p.msg_id
                    AND pt.user_id = ' . $user_id . '
                    AND pt.pm_deleted = 0
                WHERE ' . $this->db->sql_in_set('p.msg_id', $msg_ids) . '
                    AND ' . $this->get_partner_sql($user_id, $partner_id);
        $result = $this->db->sql_query($sql);

        $still_visible = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $still_visible[(int) $row['msg_id']] = true;
        }
        $this->db->sql_freeresult($result);

        $invisible = [];
        foreach ($msg_ids as $msg_id)
        {
            if (!isset($still_visible[$msg_id]))
            {
                $invisible[] = $msg_id;
            }
        }

        return $invisible;
    }

    /**
     * Visible message IDs in a msg_id range for live chat sync.
     *
     * @return int[]
     */
    public function get_visible_message_ids_in_range($user_id, $partner_id, $min_msg_id, $max_msg_id)
    {
        $user_id     = (int) $user_id;
        $partner_id  = (int) $partner_id;
        $min_msg_id  = (int) $min_msg_id;
        $max_msg_id  = (int) $max_msg_id;

        if ($user_id <= 0 || $partner_id <= 0 || $min_msg_id <= 0 || $max_msg_id < $min_msg_id)
        {
            return [];
        }

        $sql = 'SELECT p.msg_id
                FROM ' . $this->t_privmsgs . ' p
                INNER JOIN ' . $this->t_privmsgs_to . ' pt
                    ON pt.msg_id = p.msg_id
                    AND pt.user_id = ' . $user_id . '
                    AND pt.pm_deleted = 0
                WHERE ' . $this->get_partner_sql($user_id, $partner_id) . '
                    AND p.msg_id >= ' . $min_msg_id . '
                    AND p.msg_id <= ' . $max_msg_id . '
                ORDER BY p.msg_id ASC';
        $result = $this->db->sql_query($sql);

        $ids = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $ids[] = (int) $row['msg_id'];
        }
        $this->db->sql_freeresult($result);

        return $ids;
    }

    public function get_partner_profile($partner_id)
    {
        $partner_id = (int) $partner_id;
        if ($partner_id <= 0)
        {
            return null;
        }

        $sql = sprintf(
            'SELECT user_id, username, user_colour, user_avatar, user_avatar_type,
                    user_avatar_width, user_avatar_height, user_lastvisit, user_regdate, user_type
             FROM %s
             WHERE user_id = %d',
            $this->t_users,
            (int) $partner_id
        );
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return [
                'user_id'       => $partner_id,
                'username'      => $this->user->lang('MESSENGER_UNKNOWN_USER'),
                'user_colour'   => '',
                'avatar'        => '',
                'last_visit'    => 0,
                'is_online'     => false,
                'regdate'       => 0,
                'is_deleted'    => true,
            ];
        }

        $avatar = $this->render_user_avatar($row);
        $presence = $this->get_presence_for_users([(int) $row['user_id']]);
        $user_presence = $presence[(int) $row['user_id']] ?? [
            'is_online'   => false,
            'last_active' => (int) $row['user_lastvisit'],
        ];

        return [
            'user_id'     => (int) $row['user_id'],
            'username'    => (string) $row['username'],
            'user_colour' => (string) $row['user_colour'],
            'avatar'      => $avatar,
            'last_visit'  => (int) $user_presence['last_active'],
            'is_online'   => !empty($user_presence['is_online']),
            'regdate'     => (int) $row['user_regdate'],
            'is_deleted'  => false,
            'is_bot'      => ((int) $row['user_type'] === \USER_IGNORE),
        ];
    }

    public function count_total_chats($user_id)
    {
        return count($this->get_roster($user_id));
    }

    public function count_total_messages($user_id)
    {
        $user_id = (int) $user_id;

        $sql = 'SELECT COUNT(DISTINCT p.msg_id) AS total
                FROM ' . $this->t_privmsgs . ' p
                INNER JOIN ' . $this->t_privmsgs_to . ' pt
                    ON pt.msg_id = p.msg_id
                    AND pt.user_id = ' . $user_id . '
                    AND pt.pm_deleted = 0';
        $result = $this->db->sql_query($sql);
        $total = (int) $this->db->sql_fetchfield('total');
        $this->db->sql_freeresult($result);

        return $total;
    }

    protected function fetch_conversation_rows($user_id)
    {
        $sql = sprintf(
            'SELECT p.msg_id, p.author_id, p.message_time, p.message_text,
                    p.message_subject, p.bbcode_bitfield, p.bbcode_uid,
                    pt.pm_unread,
                    CASE
                        WHEN p.author_id = %1$d THEN (
                            SELECT MIN(pt2.user_id)
                            FROM %3$s pt2
                            WHERE pt2.msg_id = p.msg_id
                                AND pt2.user_id <> %1$d
                        )
                        ELSE p.author_id
                    END AS partner_id
             FROM %2$s p
             INNER JOIN %3$s pt
                 ON pt.msg_id = p.msg_id
                 AND pt.user_id = %1$d
                 AND pt.pm_deleted = 0
             ORDER BY p.message_time DESC',
            (int) $user_id,
            $this->t_privmsgs,
            $this->t_privmsgs_to
        );
        $this->db->sql_query($sql);

        $rows = [];
        while ($row = $this->db->sql_fetchrow())
        {
            $rows[] = $row;
        }
        $this->db->sql_freeresult();

        return $rows;
    }

    protected function count_chat_messages($user_id, $partner_id)
    {
        $sql = 'SELECT COUNT(p.msg_id) AS total
                FROM ' . $this->t_privmsgs . ' p
                INNER JOIN ' . $this->t_privmsgs_to . ' pt
                    ON pt.msg_id = p.msg_id
                    AND pt.user_id = ' . (int) $user_id . '
                    AND pt.pm_deleted = 0
                WHERE ' . $this->get_partner_sql($user_id, $partner_id);
        $result = $this->db->sql_query($sql);
        $total = (int) $this->db->sql_fetchfield('total');
        $this->db->sql_freeresult($result);

        return $total;
    }

    protected function get_partner_sql($user_id, $partner_id)
    {
        $user_id    = (int) $user_id;
        $partner_id = (int) $partner_id;

        return sprintf(
            '(
            (p.author_id = %1$d AND EXISTS (
                SELECT 1 FROM %3$s pt_p
                WHERE pt_p.msg_id = p.msg_id AND pt_p.user_id = %2$d
            ))
            OR
            (p.author_id = %2$d AND EXISTS (
                SELECT 1 FROM %3$s pt_p
                WHERE pt_p.msg_id = p.msg_id AND pt_p.user_id = %1$d
            ))
        )',
            $user_id,
            $partner_id,
            $this->t_privmsgs_to
        );
    }

    protected function build_message_select_sql($user_id, $partner_id)
    {
        $user_id    = (int) $user_id;
        $partner_id = (int) $partner_id;

        return 'SELECT p.msg_id, p.author_id, p.message_time, p.message_subject, p.message_text,
                       p.bbcode_bitfield, p.bbcode_uid, p.message_attachment,
                       p.message_edit_time, p.message_edit_count,
                       pt.pm_unread, pt.pm_replied, pt.folder_id,
                       peer_pt.pm_unread AS peer_pm_unread,
                       u.username AS author_username
                FROM ' . $this->t_privmsgs . ' p
                INNER JOIN ' . $this->t_privmsgs_to . ' pt
                    ON pt.msg_id = p.msg_id
                    AND pt.user_id = ' . $user_id . '
                    AND pt.pm_deleted = 0
                INNER JOIN ' . $this->t_users . ' u
                    ON u.user_id = p.author_id
                LEFT JOIN ' . $this->t_privmsgs_to . ' peer_pt
                    ON peer_pt.msg_id = p.msg_id
                    AND peer_pt.user_id = ' . $partner_id . '
                    AND peer_pt.author_id = ' . $user_id . '
                    AND peer_pt.pm_deleted = 0';
    }

    /**
     * Messages edited after $since_edit_time within the loaded id range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_updated_messages($user_id, $partner_id, $since_edit_time, $sync_min = 0, $sync_max = 0)
    {
        $user_id         = (int) $user_id;
        $partner_id      = (int) $partner_id;
        $since_edit_time = (int) $since_edit_time;
        $sync_min        = (int) $sync_min;
        $sync_max        = (int) $sync_max;

        if ($user_id <= 0 || $partner_id <= 0 || $since_edit_time <= 0)
        {
            return [];
        }

        $sql = $this->build_message_select_sql($user_id, $partner_id) . '
                WHERE ' . $this->get_partner_sql($user_id, $partner_id) . '
                    AND p.message_edit_time > ' . $since_edit_time . '
                    AND p.message_edit_time > 0';

        if ($sync_min > 0 && $sync_max >= $sync_min)
        {
            $sql .= ' AND p.msg_id BETWEEN ' . $sync_min . ' AND ' . $sync_max;
        }

        $sql .= ' ORDER BY p.message_edit_time ASC';
        $result = $this->db->sql_query($sql);

        $messages = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $messages[] = $this->format_message_row($row, $user_id);
        }
        $this->db->sql_freeresult($result);

        return $messages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetch_message_rows($user_id, $partner_id, $limit, $before_msg_id = 0)
    {
        $user_id       = (int) $user_id;
        $partner_id    = (int) $partner_id;
        $limit         = max(1, (int) $limit);
        $before_msg_id = (int) $before_msg_id;

        $sql = $this->build_message_select_sql($user_id, $partner_id) . '
                WHERE ' . $this->get_partner_sql($user_id, $partner_id);

        if ($before_msg_id > 0)
        {
            $sql .= ' AND p.msg_id < ' . $before_msg_id;
        }

        $sql .= ' ORDER BY p.msg_id DESC';
        $result = $this->db->sql_query_limit($sql, $limit);

        $rows = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        return array_reverse($rows);
    }

    protected function has_messages_before($user_id, $partner_id, $msg_id)
    {
        $msg_id = (int) $msg_id;

        if ($msg_id <= 0)
        {
            return false;
        }

        $sql = 'SELECT 1
                FROM ' . $this->t_privmsgs . ' p
                INNER JOIN ' . $this->t_privmsgs_to . ' pt
                    ON pt.msg_id = p.msg_id
                    AND pt.user_id = ' . (int) $user_id . '
                    AND pt.pm_deleted = 0
                WHERE ' . $this->get_partner_sql((int) $user_id, (int) $partner_id) . '
                    AND p.msg_id < ' . $msg_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $exists = (bool) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $exists;
    }

    protected function enrich_partners(array &$roster)
    {
        if (empty($roster))
        {
            return;
        }

        $ids = array_keys($roster);
        $sql = 'SELECT user_id, username, user_colour, user_avatar, user_avatar_type,
                       user_avatar_width, user_avatar_height, user_lastvisit, user_type
                FROM ' . $this->t_users . '
                WHERE ' . $this->db->sql_in_set('user_id', $ids);
        $result = $this->db->sql_query($sql);

        $loaded_ids = [];

        while ($row = $this->db->sql_fetchrow($result))
        {
            $id = (int) $row['user_id'];
            if (!isset($roster[$id]))
            {
                continue;
            }

            $loaded_ids[] = $id;
            $roster[$id]['username']    = (string) $row['username'];
            $roster[$id]['user_colour'] = (string) $row['user_colour'];
            $roster[$id]['avatar']      = $this->render_user_avatar($row);
            $roster[$id]['last_visit']  = (int) $row['user_lastvisit'];
        }
        $this->db->sql_freeresult($result);

        if (!empty($loaded_ids))
        {
            $presence = $this->get_presence_for_users($loaded_ids);
            foreach ($loaded_ids as $id)
            {
                if (!isset($presence[$id]))
                {
                    continue;
                }

                $roster[$id]['last_visit'] = (int) $presence[$id]['last_active'];
                $roster[$id]['is_online']  = !empty($presence[$id]['is_online']);
            }
        }

        foreach ($roster as $id => &$entry)
        {
            if (empty($entry['username']))
            {
                $entry['username']   = $this->user->lang('MESSENGER_UNKNOWN_USER') . ' #' . $id;
                $entry['avatar']     = '';
                $entry['is_online']  = false;
                $entry['is_deleted'] = true;
            }
        }
    }

    protected function get_message_display_flags()
    {
        return \OPTION_FLAG_BBCODE | \OPTION_FLAG_SMILIES | \OPTION_FLAG_LINKS;
    }

    protected function render_user_avatar(array $row)
    {
        $avatar_data = \phpbb\avatar\manager::clean_row($row, 'user');
        $title = (string) ($row['username'] ?? '');

        return phpbb_get_avatar($avatar_data, $title);
    }

    /**
     * @param int[] $user_ids
     * @return array<int, array{is_online: bool, last_active: int}>
     */
    public function get_presence_for_users(array $user_ids)
    {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if (empty($user_ids))
        {
            return [];
        }

        $presence = [];
        $sql = 'SELECT user_id, user_lastvisit
                FROM ' . $this->t_users . '
                WHERE ' . $this->db->sql_in_set('user_id', $user_ids);
        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result))
        {
            $id = (int) $row['user_id'];
            $presence[$id] = [
                'is_online'   => false,
                'last_active' => (int) $row['user_lastvisit'],
            ];
        }
        $this->db->sql_freeresult($result);

        $sql = 'SELECT session_user_id, MAX(session_time) AS session_time
                FROM ' . $this->t_sessions . '
                WHERE ' . $this->db->sql_in_set('session_user_id', $user_ids) . '
                GROUP BY session_user_id';
        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result))
        {
            $id = (int) $row['session_user_id'];
            if (!isset($presence[$id]))
            {
                continue;
            }

            $presence[$id]['last_active'] = max(
                $presence[$id]['last_active'],
                (int) $row['session_time']
            );
        }
        $this->db->sql_freeresult($result);

        $online_interval = (int) $this->config['load_online_time'];
        $session_cutoff = time() - $online_interval;
        $can_view_hidden = $this->auth->acl_get('a_');

        $sql = 'SELECT session_user_id, MAX(session_time) AS session_time,
                       MAX(session_viewonline) AS session_viewonline
                FROM ' . $this->t_sessions . '
                WHERE ' . $this->db->sql_in_set('session_user_id', $user_ids) . '
                    AND session_time >= ' . (int) $session_cutoff . '
                GROUP BY session_user_id';
        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result))
        {
            $id = (int) $row['session_user_id'];
            if (!isset($presence[$id]))
            {
                continue;
            }

            $presence[$id]['last_active'] = max(
                $presence[$id]['last_active'],
                (int) $row['session_time']
            );

            $is_invisible = ((int) $row['session_viewonline'] === 1);
            if (!$is_invisible || $can_view_hidden)
            {
                $presence[$id]['is_online'] = true;
            }
        }
        $this->db->sql_freeresult($result);

        return $presence;
    }

    public function format_presence_text($last_active, $is_online)
    {
        if ($is_online)
        {
            return $this->user->lang('MESSENGER_ONLINE_NOW');
        }

        $last_active = (int) $last_active;
        if ($last_active <= 0)
        {
            return '';
        }

        return $this->user->lang(
            'MESSENGER_LAST_SEEN',
            $this->user->format_date($last_active, $this->user->dateformat)
        );
    }

    protected function build_preview(array $row)
    {
        global $phpbb_root_path, $phpEx;

        if (!function_exists('generate_text_for_display'))
        {
            include $phpbb_root_path . 'includes/functions_content.' . $phpEx;
        }

        $text = generate_text_for_display(
            (string) $row['message_text'],
            (string) ($row['bbcode_uid'] ?? ''),
            (string) ($row['bbcode_bitfield'] ?? ''),
            $this->get_message_display_flags()
        );

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('#\s+#u', ' ', $text));

        if ($text === '')
        {
            $text = $this->plain_text_from_storage((string) $row['message_text']);
        }

        if (utf8_strlen($text) > 120)
        {
            $text = utf8_substr($text, 0, 120) . '…';
        }

        return $text;
    }

    protected function plain_text_from_storage($text)
    {
        $text = preg_replace('#<[^>]+>#u', ' ', $text);
        $text = preg_replace('#\[.*?\]#s', '', $text);
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim(preg_replace('#\s+#u', ' ', $text));
    }

    /**
     * @return array<string, mixed>
     */
    public function format_message_row(array $row, $user_id)
    {
        $message = generate_text_for_display(
            $row['message_text'],
            $row['bbcode_uid'],
            $row['bbcode_bitfield'],
            $this->get_message_display_flags()
        );
        $message = self::rewrite_messenger_quote_links($message);
        $message = $this->attachment->append_attachments_to_html(
            (int) $row['msg_id'],
            $message,
            (string) $row['message_text'],
            (int) $row['author_id']
        );

        $is_own = ((int) $row['author_id'] === (int) $user_id);
        $read_status = $is_own ? $this->resolve_read_status($row) : null;
        $edit_time = (int) ($row['message_edit_time'] ?? 0);

        return [
            'msg_id'        => (int) $row['msg_id'],
            'author_id'     => (int) $row['author_id'],
            'author_username'=> (string) ($row['author_username'] ?? ''),
            'is_own'        => $is_own,
            'can_edit'      => $is_own && $this->can_edit_message_row($row),
            'message_html'  => $message,
            'message_plain' => $this->plain_text_from_storage((string) $row['message_text']),
            'message_time'  => (int) $row['message_time'],
            'time_formatted'=> $this->user->format_date((int) $row['message_time']),
            'is_unread'     => ((int) $row['pm_unread'] === 1),
            'read_status'   => $read_status,
            'is_read_by_peer'=> ($read_status === 'read'),
            'has_attachment'=> ((int) $row['message_attachment'] === 1),
            'is_edited'     => $edit_time > 0,
            'message_edit_time' => $edit_time,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function can_edit_message_row(array $row)
    {
        if (!empty($this->config['messenger_allow_edit_after_read']))
        {
            return true;
        }

        if (!array_key_exists('peer_pm_unread', $row) || $row['peer_pm_unread'] === null)
        {
            return true;
        }

        return (int) $row['peer_pm_unread'] === 1;
    }

    /**
     * Rewrite phpBB PM quote jump links to in-chat anchors.
     */
    public static function rewrite_messenger_quote_links($html)
    {
        if ($html === '' || stripos($html, '<blockquote') === false)
        {
            return $html;
        }

        return preg_replace_callback(
            '#href=(["\'])([^"\']+)\1#i',
            function (array $matches) {
                $href = html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8');
                if (stripos($href, 'viewtopic') !== false)
                {
                    return $matches[0];
                }

                $msg_id = 0;
                if (preg_match('~#(?:p|msg-)(\d+)$~i', $href, $found))
                {
                    $msg_id = (int) $found[1];
                }
                else if (preg_match('~[?&]p=(\d+)~', $href, $found))
                {
                    $msg_id = (int) $found[1];
                }

                if ($msg_id <= 0)
                {
                    return $matches[0];
                }

                if (stripos($href, 'ucp.') === false
                    && stripos($href, 'mode=view') === false
                    && !preg_match('~#(?:p|msg-)\d+$~i', $href))
                {
                    return $matches[0];
                }

                return 'href="#msg-' . $msg_id . '"';
            },
            $html
        );
    }

    /**
     * WhatsApp-style receipt: delivered (double gray) or read (double blue).
     *
     * @return string|null delivered|read
     */
    protected function resolve_read_status(array $row)
    {
        if (!array_key_exists('peer_pm_unread', $row) || $row['peer_pm_unread'] === null)
        {
            return 'delivered';
        }

        return ((int) $row['peer_pm_unread'] === 0) ? 'read' : 'delivered';
    }

    /**
     * @return array<string, mixed>
     */
    public function format_roster_entry(array $entry, $user_id)
    {
        $preview = (string) ($entry['last_preview'] ?? '');
        if ((int) ($entry['last_author_id'] ?? 0) === (int) $user_id && $preview !== '')
        {
            $preview = $this->user->lang('MESSENGER_YOU_PREFIX') . $preview;
        }

        $last_active = (int) ($entry['last_visit'] ?? 0);
        $is_online = !empty($entry['is_online']);

        return [
            'partner_id'      => (int) $entry['partner_id'],
            'username'        => (string) ($entry['username'] ?? ''),
            'user_colour'     => (string) ($entry['user_colour'] ?? ''),
            'avatar'          => (string) ($entry['avatar'] ?? ''),
            'last_time'       => (int) ($entry['last_time'] ?? 0),
            'time_formatted'  => !empty($entry['last_time'])
                ? $this->user->format_date((int) $entry['last_time'], $this->user->dateformat)
                : '',
            'preview'         => $preview,
            'unread_count'    => (int) ($entry['unread_count'] ?? 0),
            'is_pinned'       => !empty($entry['is_pinned']),
            'is_online'       => $is_online,
            'last_active'     => $last_active,
            'presence_text'   => $this->format_presence_text($last_active, $is_online),
            'chat_url'        => '',
        ];
    }
}
