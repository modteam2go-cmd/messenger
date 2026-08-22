<?php

/**
 * Messenger — member lookup for compose
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\service;

class member_service
{
    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var \phpbb\user */
    protected $user;

    /** @var \phpbb\auth\auth */
    protected $auth;

    /** @var string */
    protected $root_path;

    /** @var string */
    protected $php_ext;

    /** @var string */
    protected $t_users;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        $root_path,
        $php_ext,
        $table_prefix
    ) {
        $this->db        = $db;
        $this->user      = $user;
        $this->auth      = $auth;
        $this->root_path = $root_path;
        $this->php_ext   = $php_ext;
        $this->t_users   = $table_prefix . 'users';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search_members($user_id, $query, $limit = 15)
    {
        $user_id = (int) $user_id;
        $limit   = max(1, min(30, (int) $limit));
        $query   = trim((string) $query);

        if ($user_id <= 0 || $query === '')
        {
            return [];
        }

        $clean = utf8_clean_string($query);
        if ($clean === '')
        {
            return [];
        }

        $needle = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], utf8_strtolower($clean));
        $members = $this->query_members($user_id, $needle . '%', 'prefix', $limit);

        if (empty($members))
        {
            $members = $this->query_members($user_id, '%' . $needle . '%', 'contains', $limit);
        }

        if (empty($members))
        {
            $members = $this->query_members($user_id, $needle, 'exact', $limit);
        }

        return $members;
    }

    public function can_message_user($member_id, array $row = null)
    {
        $member_id = (int) $member_id;
        $user_id   = (int) $this->user->data['user_id'];

        if ($member_id <= 0 || $member_id === $user_id)
        {
            return false;
        }

        if ($row === null)
        {
            $sql = sprintf(
                'SELECT user_id, user_allow_pm, user_type, user_inactive_reason
                 FROM %s
                 WHERE user_id = %d',
                $this->t_users,
                $member_id
            );
            $result = $this->db->sql_query($sql);
            $row = $this->db->sql_fetchrow($result);
            $this->db->sql_freeresult($result);

            if (!$row)
            {
                return false;
            }
        }

        if ((int) $row['user_inactive_reason'] !== 0)
        {
            return false;
        }

        if (!in_array((int) $row['user_type'], [\USER_NORMAL, \USER_FOUNDER], true))
        {
            return false;
        }

        if (!$this->can_ignore_allow_pm() && (int) $row['user_allow_pm'] === 0)
        {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_member($member_id)
    {
        $member_id = (int) $member_id;
        if ($member_id <= 0 || !$this->can_message_user($member_id))
        {
            return null;
        }

        $sql = sprintf(
            'SELECT user_id, username, user_colour, user_avatar, user_avatar_type,
                    user_avatar_width, user_avatar_height, user_allow_pm, user_type, user_inactive_reason
             FROM %s
             WHERE user_id = %d',
            $this->t_users,
            $member_id
        );
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row)
        {
            return null;
        }

        return $this->format_member_row($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function query_members($user_id, $pattern, $mode, $limit)
    {
        $user_id = (int) $user_id;
        $pattern = (string) $pattern;

        if ($mode === 'exact')
        {
            $username_sql = $this->db->sql_lower_text('username_clean') . " = '" . $this->db->sql_escape($pattern) . "'";
        }
        else
        {
            $username_sql = $this->db->sql_lower_text('username_clean') . " LIKE '" . $this->db->sql_escape($pattern) . "'";
        }

        $sql = 'SELECT user_id, username, user_colour, user_avatar, user_avatar_type,
                       user_avatar_width, user_avatar_height, user_allow_pm, user_type, user_inactive_reason
                FROM ' . $this->t_users . '
                WHERE ' . $username_sql . '
                    AND user_id <> ' . $user_id . '
                    AND user_type IN (' . \USER_NORMAL . ', ' . \USER_FOUNDER . ')
                    AND user_inactive_reason = 0
                ORDER BY username ASC';
        $result = $this->db->sql_query_limit($sql, $limit);

        $members = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            if (!$this->can_message_user((int) $row['user_id'], $row))
            {
                continue;
            }

            $members[] = $this->format_member_row($row);
        }
        $this->db->sql_freeresult($result);

        return $members;
    }

    protected function can_ignore_allow_pm()
    {
        return $this->auth->acl_gets('a_', 'm_') || $this->auth->acl_getf_global('m_');
    }

    /**
     * @return array<string, mixed>
     */
    public function format_member_row(array $row)
    {
        $this->ensure_display_functions();

        $username = (string) $row['username'];
        $avatar   = '';

        if (function_exists('phpbb_get_avatar'))
        {
            $avatar_data = \phpbb\avatar\manager::clean_row($row, 'user');
            $avatar = phpbb_get_avatar($avatar_data, $username);
        }

        return [
            'user_id'     => (int) $row['user_id'],
            'username'    => $username,
            'user_colour' => (string) $row['user_colour'],
            'avatar'      => $avatar,
        ];
    }

    protected function ensure_display_functions()
    {
        if (function_exists('phpbb_get_avatar'))
        {
            return;
        }

        if (is_file($this->root_path . 'includes/functions_display.' . $this->php_ext))
        {
            include_once $this->root_path . 'includes/functions_display.' . $this->php_ext;
        }
    }
}
