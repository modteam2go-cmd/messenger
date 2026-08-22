<?php

/**
 * Messenger — group chats (staff / selected phpBB groups)
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\service;

class group_service
{
    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var \phpbb\config\config */
    protected $config;

    /** @var \phpbb\user */
    protected $user;

    /** @var \phpbb\auth\auth */
    protected $auth;

    /** @var \phpbb\language\language */
    protected $language;

    /** @var \phpbb\notification\manager */
    protected $notification_manager;

    const NOTIFICATION_TYPE = 'negentiendertien.messenger.notification.type.group_message';

    /** @var string */
    protected $root_path;

    /** @var string */
    protected $php_ext;

    /** @var string */
    protected $t_groups;

    /** @var string */
    protected $t_members;

    /** @var string */
    protected $t_messages;

    /** @var string */
    protected $t_users;

    /** @var string */
    protected $t_user_group;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        \phpbb\config\config $config,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        \phpbb\language\language $language,
        \phpbb\notification\manager $notification_manager,
        $root_path,
        $php_ext,
        $table_prefix
    ) {
        $this->db        = $db;
        $this->config    = $config;
        $this->user      = $user;
        $this->auth      = $auth;
        $this->language  = $language;
        $this->notification_manager = $notification_manager;
        $this->root_path = $root_path;
        $this->php_ext   = $php_ext;

        $this->t_groups      = $table_prefix . 'messenger_groups';
        $this->t_members     = $table_prefix . 'messenger_group_members';
        $this->t_messages    = $table_prefix . 'messenger_group_messages';
        $this->t_users       = $table_prefix . 'users';
        $this->t_user_group  = $table_prefix . 'user_group';
    }

    public function is_enabled()
    {
        return !empty($this->config['messenger_group_chat_enabled'])
            && !empty($this->get_allowed_group_ids());
    }

    public function can_user_start_group_chat($user_id)
    {
        return $this->is_enabled() && (int) $user_id > 0;
    }

    /**
     * @return int[]
     */
    public function get_allowed_group_ids()
    {
        $raw = trim((string) ($this->config['messenger_group_chat_groups'] ?? ''));
        if ($raw === '')
        {
            return [];
        }

        $ids = array_filter(array_map('intval', explode(',', $raw)));
        $ids = array_values(array_unique(array_filter($ids, function ($id) {
            return $id > 0;
        })));

        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    public function user_is_in_allowed_groups($user_id)
    {
        $user_id = (int) $user_id;
        $group_ids = $this->get_allowed_group_ids();

        if ($user_id <= 0 || empty($group_ids))
        {
            return false;
        }

        $sql = 'SELECT user_id
                FROM ' . $this->t_user_group . '
                WHERE user_id = ' . $user_id . '
                    AND ' . $this->db->sql_in_set('group_id', $group_ids) . '
                    AND user_pending = 0';
        $result = $this->db->sql_query($sql);
        $found = (bool) $this->db->sql_fetchfield('user_id');
        $this->db->sql_freeresult($result);

        return $found;
    }

    /**
     * @param int[] $user_ids
     */
    public function all_users_in_allowed_groups(array $user_ids)
    {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if (empty($user_ids))
        {
            return false;
        }

        foreach ($user_ids as $user_id)
        {
            if (!$this->user_is_in_allowed_groups($user_id))
            {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search_staff_members($viewer_id, $query, $limit = 15)
    {
        $viewer_id = (int) $viewer_id;
        $limit     = max(1, min(30, (int) $limit));
        $query     = trim((string) $query);
        $group_ids = $this->get_allowed_group_ids();

        if (!$this->is_enabled() || $viewer_id <= 0 || $query === '' || empty($group_ids))
        {
            return [];
        }

        $clean = utf8_clean_string($query);
        if ($clean === '')
        {
            return [];
        }

        $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], utf8_strtolower($clean));

        return $this->query_staff_members($viewer_id, $group_ids, $needle . '%', $limit);
    }

    /**
     * @param int[] $group_ids
     * @return array<int, array<string, mixed>>
     */
    protected function query_staff_members($viewer_id, array $group_ids, $pattern, $limit)
    {
        $sql = 'SELECT DISTINCT u.user_id, u.username, u.user_colour,
                       u.user_avatar, u.user_avatar_type, u.user_avatar_width, u.user_avatar_height
                FROM ' . $this->t_users . ' u
                INNER JOIN ' . $this->t_user_group . ' ug
                    ON ug.user_id = u.user_id
                    AND ' . $this->db->sql_in_set('ug.group_id', $group_ids) . '
                    AND ug.user_pending = 0
                WHERE u.user_id <> ' . (int) $viewer_id . '
                    AND u.user_type IN (' . \USER_NORMAL . ', ' . \USER_FOUNDER . ')
                    AND u.user_inactive_reason = 0
                    AND LOWER(u.username_clean) LIKE \'' . $this->db->sql_escape($pattern) . '\'
                ORDER BY u.username_clean ASC';
        $result = $this->db->sql_query_limit($sql, $limit);

        $members = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $members[] = $this->format_member_row($row);
        }
        $this->db->sql_freeresult($result);

        return $members;
    }

    /**
     * @return array<string, mixed>
     */
    protected function format_member_row(array $row)
    {
        return [
            'user_id'     => (int) $row['user_id'],
            'username'    => (string) $row['username'],
            'user_colour' => (string) $row['user_colour'],
            'avatar'      => phpbb_get_user_avatar($row),
        ];
    }

    /**
     * @param int[] $member_ids
     * @return array{success: bool, group_id?: int, error?: string}
     */
    public function create_group($creator_id, $title, array $member_ids)
    {
        $this->language->add_lang('common', 'negentiendertien/messenger');

        if (!$this->can_user_start_group_chat($creator_id))
        {
            return ['success' => false, 'error' => 'MESSENGER_GROUP_CHAT_DISABLED'];
        }

        $creator_id = (int) $creator_id;
        $title      = trim((string) $title);
        $member_ids = array_values(array_unique(array_filter(array_map('intval', $member_ids))));

        if ($creator_id <= 0)
        {
            return ['success' => false, 'error' => 'NOT_AUTHORISED'];
        }

        if ($title === '')
        {
            $title = $this->build_default_group_title($member_ids);
        }

        if (utf8_strlen($title) > 255)
        {
            $title = utf8_substr($title, 0, 255);
        }

        if (empty($member_ids))
        {
            return ['success' => false, 'error' => 'MESSENGER_GROUP_MEMBERS_REQUIRED'];
        }

        if (!$this->all_users_in_allowed_groups($member_ids))
        {
            return ['success' => false, 'error' => 'MESSENGER_GROUP_INVALID_MEMBERS'];
        }

        $member_ids[] = $creator_id;
        $member_ids = array_values(array_unique($member_ids));

        $now = time();

        $sql = 'INSERT INTO ' . $this->t_groups . ' ' . $this->db->sql_build_array('INSERT', [
            'group_title'           => $title,
            'creator_id'            => $creator_id,
            'created_at'            => $now,
            'updated_at'            => $now,
            'last_message_time'     => $now,
            'last_message_preview'  => '',
        ]);
        $this->db->sql_query($sql);
        $group_id = (int) $this->db->sql_nextid();

        foreach ($member_ids as $member_id)
        {
            $sql = 'INSERT INTO ' . $this->t_members . ' ' . $this->db->sql_build_array('INSERT', [
                'group_id'          => $group_id,
                'user_id'           => (int) $member_id,
                'joined_at'         => $now,
                'last_read_msg_id'  => 0,
            ]);
            $this->db->sql_query($sql);
        }

        return ['success' => true, 'group_id' => $group_id];
    }

    /**
     * @param int[] $member_ids
     */
    protected function build_default_group_title(array $member_ids)
    {
        if (empty($member_ids))
        {
            return $this->language->lang('MESSENGER_GROUP_TITLE_DEFAULT');
        }

        $sql = 'SELECT username
                FROM ' . $this->t_users . '
                WHERE ' . $this->db->sql_in_set('user_id', $member_ids) . '
                ORDER BY username_clean ASC';
        $result = $this->db->sql_query($sql);

        $names = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $names[] = (string) $row['username'];
        }
        $this->db->sql_freeresult($result);

        if (empty($names))
        {
            return $this->language->lang('MESSENGER_GROUP_TITLE_DEFAULT');
        }

        $title = implode(', ', $names);
        if (utf8_strlen($title) > 255)
        {
            $title = utf8_substr($title, 0, 252) . '...';
        }

        return $title;
    }

    /**
     * Leave a group chat. When the last member leaves, the group and its messages are removed.
     */
    public function leave_group($user_id, $group_id)
    {
        $user_id  = (int) $user_id;
        $group_id = (int) $group_id;

        if (!$this->user_is_member($user_id, $group_id))
        {
            return false;
        }

        $sql = 'DELETE FROM ' . $this->t_members . '
                WHERE group_id = ' . $group_id . '
                    AND user_id = ' . $user_id;
        $this->db->sql_query($sql);

        $this->notification_manager->mark_notifications_by_parent(self::NOTIFICATION_TYPE, $group_id, $user_id);

        $sql = 'SELECT COUNT(user_id) AS remaining
                FROM ' . $this->t_members . '
                WHERE group_id = ' . $group_id;
        $result = $this->db->sql_query($sql);
        $remaining = (int) $this->db->sql_fetchfield('remaining');
        $this->db->sql_freeresult($result);

        if ($remaining === 0)
        {
            $this->delete_group_notifications($group_id);
            $this->db->sql_query('DELETE FROM ' . $this->t_messages . ' WHERE group_id = ' . $group_id);
            $this->db->sql_query('DELETE FROM ' . $this->t_groups . ' WHERE group_id = ' . $group_id);
        }

        return true;
    }

    protected function delete_group_notifications($group_id)
    {
        $sql = 'SELECT message_id
                FROM ' . $this->t_messages . '
                WHERE group_id = ' . (int) $group_id;
        $result = $this->db->sql_query($sql);

        $message_ids = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $message_ids[] = (int) $row['message_id'];
        }
        $this->db->sql_freeresult($result);

        if (!empty($message_ids))
        {
            $this->notification_manager->delete_notifications(self::NOTIFICATION_TYPE, $message_ids);
        }
    }

    public function user_is_member($user_id, $group_id)
    {
        $sql = 'SELECT user_id
                FROM ' . $this->t_members . '
                WHERE group_id = ' . (int) $group_id . '
                    AND user_id = ' . (int) $user_id;
        $result = $this->db->sql_query($sql);
        $found = (bool) $this->db->sql_fetchfield('user_id');
        $this->db->sql_freeresult($result);

        return $found;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_roster($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !$this->is_enabled())
        {
            return [];
        }

        $sql = 'SELECT g.group_id, g.group_title, g.last_message_time, g.last_message_preview,
                       gm.last_read_msg_id,
                       (
                           SELECT COUNT(*)
                           FROM ' . $this->t_messages . ' m
                           WHERE m.group_id = g.group_id
                               AND m.message_id > gm.last_read_msg_id
                               AND m.author_id <> ' . $user_id . '
                       ) AS unread_count,
                       (
                           SELECT COUNT(*)
                           FROM ' . $this->t_members . ' gm2
                           WHERE gm2.group_id = g.group_id
                       ) AS member_count
                FROM ' . $this->t_groups . ' g
                INNER JOIN ' . $this->t_members . ' gm
                    ON gm.group_id = g.group_id
                    AND gm.user_id = ' . $user_id . '
                ORDER BY g.last_message_time DESC';
        $result = $this->db->sql_query($sql);

        $rows = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $rows[] = $this->format_roster_row($row);
        }
        $this->db->sql_freeresult($result);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function format_roster_row(array $row)
    {
        $preview = (string) $row['last_message_preview'];
        if ($preview === '')
        {
            $preview = $this->language->lang('MESSENGER_GROUP_NO_MESSAGES');
        }

        return [
            'chat_type'      => 'group',
            'group_id'       => (int) $row['group_id'],
            'partner_id'     => 0,
            'username'       => (string) $row['group_title'],
            'user_colour'    => '',
            'avatar'         => '',
            'last_time'      => (int) $row['last_message_time'],
            'time_formatted' => $this->user->format_date((int) $row['last_message_time']),
            'preview'        => $preview,
            'unread_count'   => (int) $row['unread_count'],
            'is_pinned'      => false,
            'is_online'      => false,
            'member_count'   => (int) $row['member_count'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_group($user_id, $group_id, $page = 1, $limit = 30)
    {
        $user_id  = (int) $user_id;
        $group_id = (int) $group_id;
        $page     = max(1, (int) $page);
        $limit    = max(1, min(100, (int) $limit));

        if (!$this->user_is_member($user_id, $group_id))
        {
            return null;
        }

        $sql = 'SELECT group_id, group_title, creator_id, created_at, last_message_time
                FROM ' . $this->t_groups . '
                WHERE group_id = ' . $group_id;
        $result = $this->db->sql_query($sql);
        $group_row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$group_row)
        {
            return null;
        }

        $total = $this->count_messages($group_id);
        $offset = max(0, $total - ($page * $limit));
        $fetch = min($limit, max(0, $total - (($page - 1) * $limit)));

        $messages = $this->fetch_messages($group_id, $user_id, $offset, $fetch);
        $oldest_msg_id = !empty($messages) ? (int) $messages[0]['msg_id'] : 0;

        return [
            'chat_type'     => 'group',
            'group_id'      => $group_id,
            'group_title'   => (string) $group_row['group_title'],
            'member_count'  => $this->count_members($group_id),
            'members'       => $this->get_member_names($group_id),
            'messages'      => $messages,
            'total'         => $total,
            'oldest_msg_id' => $oldest_msg_id,
            'has_older'     => $offset > 0,
        ];
    }

    public function count_messages($group_id)
    {
        $sql = 'SELECT COUNT(*) AS total
                FROM ' . $this->t_messages . '
                WHERE group_id = ' . (int) $group_id;
        $result = $this->db->sql_query($sql);
        $total = (int) $this->db->sql_fetchfield('total');
        $this->db->sql_freeresult($result);

        return $total;
    }

    public function count_members($group_id)
    {
        $sql = 'SELECT COUNT(*) AS total
                FROM ' . $this->t_members . '
                WHERE group_id = ' . (int) $group_id;
        $result = $this->db->sql_query($sql);
        $total = (int) $this->db->sql_fetchfield('total');
        $this->db->sql_freeresult($result);

        return $total;
    }

    /**
     * @return string[]
     */
    public function get_member_names($group_id)
    {
        $sql = 'SELECT u.username
                FROM ' . $this->t_members . ' gm
                INNER JOIN ' . $this->t_users . ' u ON u.user_id = gm.user_id
                WHERE gm.group_id = ' . (int) $group_id . '
                ORDER BY u.username_clean ASC';
        $result = $this->db->sql_query($sql);

        $names = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $names[] = (string) $row['username'];
        }
        $this->db->sql_freeresult($result);

        return $names;
    }

    /**
     * @return array<int, string> user_id => username
     */
    public function get_member_users($group_id)
    {
        $sql = 'SELECT gm.user_id, u.username
                FROM ' . $this->t_members . ' gm
                INNER JOIN ' . $this->t_users . ' u ON u.user_id = gm.user_id
                WHERE gm.group_id = ' . (int) $group_id . '
                ORDER BY u.username_clean ASC';
        $result = $this->db->sql_query($sql);

        $users = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $users[(int) $row['user_id']] = (string) $row['username'];
        }
        $this->db->sql_freeresult($result);

        return $users;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetch_messages($group_id, $user_id, $offset, $limit)
    {
        if ($limit <= 0)
        {
            return [];
        }

        $sql = 'SELECT m.message_id, m.group_id, m.author_id, m.message_text, m.message_time,
                       m.bbcode_uid, m.bbcode_bitfield, m.bbcode_options,
                       u.username AS author_username
                FROM ' . $this->t_messages . ' m
                LEFT JOIN ' . $this->t_users . ' u ON u.user_id = m.author_id
                WHERE m.group_id = ' . (int) $group_id . '
                ORDER BY m.message_time ASC, m.message_id ASC';
        $result = $this->db->sql_query_limit($sql, $limit, $offset);

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
    public function get_new_messages($user_id, $group_id, $since_msg_id)
    {
        $since_msg_id = (int) $since_msg_id;
        if (!$this->user_is_member((int) $user_id, (int) $group_id))
        {
            return [];
        }

        $sql = 'SELECT m.message_id, m.group_id, m.author_id, m.message_text, m.message_time,
                       m.bbcode_uid, m.bbcode_bitfield, m.bbcode_options,
                       u.username AS author_username
                FROM ' . $this->t_messages . ' m
                LEFT JOIN ' . $this->t_users . ' u ON u.user_id = m.author_id
                WHERE m.group_id = ' . (int) $group_id . '
                    AND m.message_id > ' . $since_msg_id . '
                ORDER BY m.message_time ASC, m.message_id ASC';
        $result = $this->db->sql_query($sql);

        $messages = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $messages[] = $this->format_message_row($row, (int) $user_id);
        }
        $this->db->sql_freeresult($result);

        return $messages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_older_messages($user_id, $group_id, $before_msg_id, $limit = 20)
    {
        if (!$this->user_is_member((int) $user_id, (int) $group_id))
        {
            return ['messages' => [], 'has_older' => false, 'oldest_msg_id' => 0];
        }

        $limit = max(1, min(100, (int) $limit));
        $before_msg_id = (int) $before_msg_id;

        $sql = 'SELECT m.message_id, m.group_id, m.author_id, m.message_text, m.message_time,
                       m.bbcode_uid, m.bbcode_bitfield, m.bbcode_options,
                       u.username AS author_username
                FROM ' . $this->t_messages . ' m
                LEFT JOIN ' . $this->t_users . ' u ON u.user_id = m.author_id
                WHERE m.group_id = ' . (int) $group_id . '
                    AND m.message_id < ' . $before_msg_id . '
                ORDER BY m.message_time DESC, m.message_id DESC';
        $result = $this->db->sql_query_limit($sql, $limit);

        $rows = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        $rows = array_reverse($rows);
        $messages = [];
        foreach ($rows as $row)
        {
            $messages[] = $this->format_message_row($row, (int) $user_id);
        }

        $oldest_msg_id = !empty($messages) ? (int) $messages[0]['msg_id'] : 0;
        $has_older = false;
        if ($oldest_msg_id > 0)
        {
            $sql = 'SELECT message_id
                    FROM ' . $this->t_messages . '
                    WHERE group_id = ' . (int) $group_id . '
                        AND message_id < ' . $oldest_msg_id;
            $result = $this->db->sql_query_limit($sql, 1);
            $has_older = (bool) $this->db->sql_fetchfield('message_id');
            $this->db->sql_freeresult($result);
        }

        return [
            'messages'      => $messages,
            'has_older'     => $has_older,
            'oldest_msg_id' => $oldest_msg_id,
        ];
    }

    /**
     * @return array{success: bool, message_id?: int, error?: string}
     */
    public function send_message($group_id, $user_id, $message_text)
    {
        $group_id = (int) $group_id;
        $user_id  = (int) $user_id;
        $message_text = trim((string) $message_text);

        if (!$this->is_enabled() || !$this->user_is_member($user_id, $group_id))
        {
            return ['success' => false, 'error' => 'NOT_AUTHORISED'];
        }

        if ($message_text === '')
        {
            return ['success' => false, 'error' => 'MESSENGER_EMPTY_MESSAGE'];
        }

        if (!function_exists('generate_text_for_storage'))
        {
            include $this->root_path . 'includes/functions_content.' . $this->php_ext;
        }

        $uid = $bitfield = $options = '';
        generate_text_for_storage(
            $message_text,
            $uid,
            $bitfield,
            $options,
            true,
            true,
            true
        );

        $now = time();

        // Guard against accidental double submits (double Enter, network retry).
        $sql = 'SELECT message_id
                FROM ' . $this->t_messages . '
                WHERE group_id = ' . $group_id . '
                    AND author_id = ' . $user_id . '
                    AND message_time >= ' . ($now - 5) . "
                    AND message_text = '" . $this->db->sql_escape($message_text) . "'
                ORDER BY message_id DESC";
        $result = $this->db->sql_query_limit($sql, 1);
        $duplicate_id = (int) $this->db->sql_fetchfield('message_id');
        $this->db->sql_freeresult($result);

        if ($duplicate_id > 0)
        {
            return ['success' => true, 'message_id' => $duplicate_id];
        }

        $sql = 'INSERT INTO ' . $this->t_messages . ' ' . $this->db->sql_build_array('INSERT', [
            'group_id'        => $group_id,
            'author_id'       => $user_id,
            'message_text'    => $message_text,
            'message_time'    => $now,
            'bbcode_uid'      => $uid,
            'bbcode_bitfield' => $bitfield,
            'bbcode_options'  => (int) $options,
        ]);
        $this->db->sql_query($sql);
        $message_id = (int) $this->db->sql_nextid();

        $preview = $this->plain_text_from_storage($message_text);
        if (utf8_strlen($preview) > 120)
        {
            $preview = utf8_substr($preview, 0, 120) . '…';
        }

        $sql = 'UPDATE ' . $this->t_groups . '
                SET last_message_time = ' . $now . ',
                    last_message_preview = \'' . $this->db->sql_escape($preview) . '\',
                    updated_at = ' . $now . '
                WHERE group_id = ' . $group_id;
        $this->db->sql_query($sql);

        $this->notify_members($group_id, $user_id, $message_id);

        return ['success' => true, 'message_id' => $message_id];
    }

    /**
     * Send a board (bell) notification to all group members except the author.
     */
    protected function notify_members($group_id, $author_id, $message_id)
    {
        $group_id  = (int) $group_id;
        $author_id = (int) $author_id;

        $recipients = array_diff(array_keys($this->get_member_users($group_id)), [$author_id]);
        if (empty($recipients))
        {
            return;
        }

        $sql = 'SELECT group_title
                FROM ' . $this->t_groups . '
                WHERE group_id = ' . $group_id;
        $result = $this->db->sql_query($sql);
        $group_title = (string) $this->db->sql_fetchfield('group_title');
        $this->db->sql_freeresult($result);

        $this->notification_manager->add_notifications(self::NOTIFICATION_TYPE, [
            'message_id'  => (int) $message_id,
            'group_id'    => $group_id,
            'author_id'   => $author_id,
            'group_title' => $group_title,
            'recipients'  => array_values($recipients),
        ]);
    }

    public function mark_read($user_id, $group_id)
    {
        if (!$this->user_is_member((int) $user_id, (int) $group_id))
        {
            return false;
        }

        $sql = 'SELECT MAX(message_id) AS last_id
                FROM ' . $this->t_messages . '
                WHERE group_id = ' . (int) $group_id;
        $result = $this->db->sql_query($sql);
        $last_id = (int) $this->db->sql_fetchfield('last_id');
        $this->db->sql_freeresult($result);

        if ($last_id <= 0)
        {
            return true;
        }

        $sql = 'UPDATE ' . $this->t_members . '
                SET last_read_msg_id = ' . $last_id . '
                WHERE group_id = ' . (int) $group_id . '
                    AND user_id = ' . (int) $user_id;
        $this->db->sql_query($sql);

        $this->notification_manager->mark_notifications_by_parent(self::NOTIFICATION_TYPE, (int) $group_id, (int) $user_id);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_message($user_id, $group_id, $message_id)
    {
        if (!$this->user_is_member((int) $user_id, (int) $group_id))
        {
            return null;
        }

        $sql = 'SELECT m.message_id, m.group_id, m.author_id, m.message_text, m.message_time,
                       m.bbcode_uid, m.bbcode_bitfield, m.bbcode_options,
                       u.username AS author_username
                FROM ' . $this->t_messages . ' m
                LEFT JOIN ' . $this->t_users . ' u ON u.user_id = m.author_id
                WHERE m.group_id = ' . (int) $group_id . '
                    AND m.message_id = ' . (int) $message_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return null;
        }

        $message = $this->format_message_row($row, (int) $user_id);

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    protected function format_message_row(array $row, $user_id)
    {
        if (!function_exists('generate_text_for_display'))
        {
            include $this->root_path . 'includes/functions_content.' . $this->php_ext;
        }

        $message = generate_text_for_display(
            $row['message_text'],
            $row['bbcode_uid'],
            $row['bbcode_bitfield'],
            isset($row['bbcode_options'])
                ? (int) $row['bbcode_options']
                : (\OPTION_FLAG_BBCODE | \OPTION_FLAG_SMILIES | \OPTION_FLAG_LINKS)
        );
        $message = \negentiendertien\messenger\service\conversation_service::rewrite_messenger_quote_links($message);

        return [
            'msg_id'          => (int) $row['message_id'],
            'author_id'       => (int) $row['author_id'],
            'author_username' => (string) ($row['author_username'] ?? ''),
            'is_own'          => ((int) $row['author_id'] === (int) $user_id),
            'message_html'    => $message,
            'message_plain'   => $this->plain_text_from_storage((string) $row['message_text']),
            'message_time'    => (int) $row['message_time'],
            'time_formatted'  => $this->user->format_date((int) $row['message_time']),
            'read_status'     => null,
            'has_attachment'  => false,
        ];
    }

    protected function plain_text_from_storage($text)
    {
        $text = preg_replace('#<[^>]+>#u', ' ', $text);
        $text = preg_replace('#\[.*?\]#s', '', $text);
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim(preg_replace('#\s+#u', ' ', $text));
    }
}
