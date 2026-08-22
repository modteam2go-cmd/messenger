<?php

/**
 * Messenger — message CRUD
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\service;

class message_service
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

    /** @var conversation_service */
    protected $conversation;

    /** @var search_service */
    protected $search;

    /** @var attachment_service */
    protected $attachment;

    /** @var \phpbb\notification\manager */
    protected $notification_manager;

    /** @var string */
    protected $root_path;

    /** @var string */
    protected $php_ext;

    /** @var string */
    protected $table_prefix;

    /** @var string */
    protected $t_privmsgs;

    /** @var string */
    protected $t_privmsgs_to;

    /** @var string */
    protected $t_posts;

    /** @var string */
    protected $t_users;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        \phpbb\config\config $config,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        \phpbb\language\language $language,
        conversation_service $conversation,
        search_service $search,
        attachment_service $attachment,
        \phpbb\notification\manager $notification_manager,
        $root_path,
        $php_ext,
        $table_prefix
    ) {
        $this->db                   = $db;
        $this->config               = $config;
        $this->user                 = $user;
        $this->auth                 = $auth;
        $this->language             = $language;
        $this->conversation         = $conversation;
        $this->search               = $search;
        $this->attachment           = $attachment;
        $this->notification_manager = $notification_manager;
        $this->root_path            = $root_path;
        $this->php_ext      = $php_ext;
        $this->table_prefix = $table_prefix;

        $this->t_privmsgs    = $table_prefix . 'privmsgs';
        $this->t_privmsgs_to = $table_prefix . 'privmsgs_to';
        $this->t_posts       = $table_prefix . 'posts';
        $this->t_users       = $table_prefix . 'users';
    }

    public function can_use_messenger()
    {
        return !empty($this->config['messenger_enabled'])
            && !empty($this->user->data['is_registered'])
            && $this->auth->acl_get('u_readpm')
            && $this->auth->acl_get('u_messenger_use');
    }

    public function can_send_message()
    {
        return $this->can_use_messenger() && $this->auth->acl_get('u_sendpm');
    }

    public function can_upload_images()
    {
        return $this->can_send_message() && $this->attachment->can_upload_images();
    }

    public function can_show_image_upload()
    {
        return $this->can_send_message() && $this->attachment->can_show_image_upload();
    }

    public function get_post_author_id($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0)
        {
            return 0;
        }

        $sql = 'SELECT poster_id
                FROM ' . $this->t_posts . '
                WHERE post_id = ' . $post_id;
        $result = $this->db->sql_query($sql);
        $poster_id = (int) $this->db->sql_fetchfield('poster_id');
        $this->db->sql_freeresult($result);

        return $poster_id;
    }

    /**
     * Build a BBCode quote of a forum post, for prefilling the messenger compose box.
     * Returns an empty string when the post does not exist or the user may not read it.
     *
     * @return string
     */
    public function get_post_quote_text($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0)
        {
            return '';
        }

        $sql = 'SELECT p.post_id, p.forum_id, p.poster_id, p.post_time, p.post_text, p.bbcode_uid, u.username
                FROM ' . $this->t_posts . ' p
                LEFT JOIN ' . $this->t_users . ' u ON u.user_id = p.poster_id
                WHERE p.post_id = ' . $post_id;
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row || !$this->auth->acl_get('f_read', (int) $row['forum_id']))
        {
            return '';
        }

        if (!function_exists('generate_text_for_edit'))
        {
            include $this->root_path . 'includes/functions_content.' . $this->php_ext;
        }

        $for_edit = generate_text_for_edit(
            (string) $row['post_text'],
            (string) $row['bbcode_uid'],
            \OPTION_FLAG_BBCODE | \OPTION_FLAG_SMILIES | \OPTION_FLAG_LINKS
        );

        $text = html_entity_decode((string) $for_edit['text'], ENT_QUOTES, 'UTF-8');
        $author = html_entity_decode((string) ($row['username'] ?? ''), ENT_QUOTES, 'UTF-8');
        $author = str_replace('"', '', $author);

        return '[quote="' . $author . '" post_id=' . (int) $row['post_id']
            . ' time=' . (int) $row['post_time']
            . ' user_id=' . (int) $row['poster_id'] . ']'
            . "\n" . $text . "\n[/quote]\n";
    }

    /**
     * @return array{success: bool, msg_id?: int, error?: string}
     */
    public function send_message($recipient_id, $message_text, $subject = '', array $attachment_ids = [])
    {
        if (!$this->can_send_message())
        {
            return ['success' => false, 'error' => 'NOT_AUTHORISED'];
        }

        $recipient_id = (int) $recipient_id;
        if ($recipient_id <= 0)
        {
            return ['success' => false, 'error' => 'MESSENGER_INVALID_RECIPIENT'];
        }

        $attachment_data = $this->attachment->collect_orphan_attachments($attachment_ids);
        $message_text = trim((string) $message_text);
        $message_text = $this->attachment->append_attachment_bbcode($message_text, $attachment_data);

        if ($message_text === '')
        {
            return ['success' => false, 'error' => 'MESSENGER_EMPTY_MESSAGE'];
        }

        if (trim((string) $subject) === '')
        {
            $subject = $this->build_subject_from_message($message_text);
        }

        if (!function_exists('generate_text_for_storage'))
        {
            include $this->root_path . 'includes/functions_content.' . $this->php_ext;
        }

        if (!function_exists('submit_pm'))
        {
            include $this->root_path . 'includes/functions_privmsgs.' . $this->php_ext;
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

        // Guard against accidental double submits (double Enter, network retry):
        // if the identical message to this recipient was stored seconds ago, reuse it.
        $duplicate_id = $this->find_recent_duplicate($recipient_id, $message_text);
        if ($duplicate_id > 0)
        {
            return ['success' => true, 'msg_id' => $duplicate_id];
        }

        $pm_data = [
            'msg_id'                => 0,
            'from_user_id'          => (int) $this->user->data['user_id'],
            'from_user_ip'          => $this->user->ip,
            'from_username'         => $this->user->data['username'],
            'reply_from_root_level' => 0,
            'reply_from_msg_id'     => 0,
            'icon_id'               => 0,
            'enable_sig'            => false,
            'enable_bbcode'         => true,
            'enable_smilies'        => true,
            'enable_urls'           => true,
            'bbcode_bitfield'       => $bitfield,
            'bbcode_uid'            => $uid,
            'message'               => $message_text,
            'attachment_data'       => $attachment_data,
            'filename_data'         => [],
            'address_list'          => ['u' => [$recipient_id => 'to']],
        ];

        $msg_id = submit_pm('post', $subject, $pm_data);

        if (!$msg_id)
        {
            return ['success' => false, 'error' => 'MESSENGER_SEND_FAILED'];
        }

        if (!empty($attachment_data))
        {
            $this->attachment->link_attachments_to_message((int) $msg_id, $attachment_ids);
        }

        $this->search->index_message((int) $msg_id);

        return ['success' => true, 'msg_id' => (int) $msg_id];
    }

    /**
     * Send the same message as separate 1-on-1 PMs to multiple recipients.
     *
     * @param int[] $recipient_ids
     * @return array{success: bool, sent_count?: int, failed_count?: int, sent?: array<int, array<string, mixed>>, failed?: array<int, array<string, mixed>>, error?: string}
     */
    public function send_message_to_recipients(array $recipient_ids, $message_text, $subject = '')
    {
        $recipient_ids = array_values(array_unique(array_filter(array_map('intval', $recipient_ids))));
        if (empty($recipient_ids))
        {
            return ['success' => false, 'error' => 'MESSENGER_COMPOSE_MULTIPLE_RECIPIENTS_REQUIRED'];
        }

        $message_text = trim((string) $message_text);
        if ($message_text === '')
        {
            return ['success' => false, 'error' => 'MESSENGER_EMPTY_MESSAGE'];
        }

        $author_id = (int) $this->user->data['user_id'];
        $max_recipients = 50;
        if (count($recipient_ids) > $max_recipients)
        {
            $recipient_ids = array_slice($recipient_ids, 0, $max_recipients);
        }

        $sent = [];
        $failed = [];

        foreach ($recipient_ids as $recipient_id)
        {
            if ($recipient_id <= 0 || $recipient_id === $author_id)
            {
                continue;
            }

            $result = $this->send_message($recipient_id, $message_text, $subject);
            if (!empty($result['success']))
            {
                $sent[] = [
                    'recipient_id' => $recipient_id,
                    'msg_id'       => (int) $result['msg_id'],
                ];
                continue;
            }

            $failed[] = [
                'recipient_id' => $recipient_id,
                'error'        => (string) ($result['error'] ?? 'MESSENGER_SEND_FAILED'),
            ];
        }

        if (empty($sent))
        {
            return [
                'success' => false,
                'error'   => 'MESSENGER_SEND_FAILED',
                'failed'  => $failed,
            ];
        }

        return [
            'success'      => true,
            'sent_count'   => count($sent),
            'failed_count' => count($failed),
            'sent'         => $sent,
            'failed'       => $failed,
        ];
    }

    /**
     * Find a message with identical text sent to the same recipient within the last seconds.
     *
     * @return int msg_id of the duplicate, 0 if none
     */
    protected function find_recent_duplicate($recipient_id, $message_text, $window = 5)
    {
        $author_id = (int) $this->user->data['user_id'];

        $sql = 'SELECT p.msg_id
                FROM ' . $this->t_privmsgs . ' p
                INNER JOIN ' . $this->t_privmsgs_to . ' pt
                    ON pt.msg_id = p.msg_id
                    AND pt.user_id = ' . (int) $recipient_id . '
                WHERE p.author_id = ' . $author_id . '
                    AND p.message_time >= ' . (time() - (int) $window) . "
                    AND p.message_text = '" . $this->db->sql_escape($message_text) . "'
                ORDER BY p.msg_id DESC";
        $result = $this->db->sql_query_limit($sql, 1);
        $msg_id = (int) $this->db->sql_fetchfield('msg_id');
        $this->db->sql_freeresult($result);

        return $msg_id;
    }

    public function mark_chat_read($user_id, $partner_id)
    {
        $user_id    = (int) $user_id;
        $partner_id = (int) $partner_id;

        if ($user_id <= 0 || $partner_id <= 0)
        {
            return 0;
        }

        $sql = sprintf(
            'SELECT p.msg_id
             FROM %1$s p
             INNER JOIN %2$s pt
                 ON pt.msg_id = p.msg_id
                 AND pt.user_id = %3$d
                 AND pt.pm_deleted = 0
                 AND pt.pm_unread = 1
             WHERE p.author_id = %4$d',
            $this->t_privmsgs,
            $this->t_privmsgs_to,
            $user_id,
            $partner_id
        );
        $result = $this->db->sql_query($sql);

        $marked = 0;
        while ($row = $this->db->sql_fetchrow($result))
        {
            $msg_id = (int) $row['msg_id'];

            $sql = 'UPDATE ' . $this->t_privmsgs_to . "
                    SET pm_unread = 0,
                        pm_new = 0
                    WHERE msg_id = $msg_id
                        AND user_id = $user_id";
            $this->db->sql_query($sql);

            $sql = 'UPDATE ' . $this->t_privmsgs_to . "
                    SET pm_replied = 1
                    WHERE msg_id = $msg_id
                        AND author_id = $partner_id
                        AND folder_id = " . \PRIVMSGS_SENTBOX;
            $this->db->sql_query($sql);

            $marked++;
        }
        $this->db->sql_freeresult($result);

        $this->clear_partner_pm_notifications($user_id, $partner_id);

        if ($marked > 0)
        {
            $this->recalculate_user_unread_privmsg($user_id);
        }

        return $marked;
    }

    /**
     * Mark PM board notifications as read when the PM is already read in messenger.
     *
     * @param int $user_id 0 = all users
     * @return int
     */
    public function cleanup_stale_pm_notifications($user_id = 0)
    {
        $user_id = (int) $user_id;

        $type_id = $this->get_pm_notification_type_id();
        if (!$type_id)
        {
            return 0;
        }

        $sql = sprintf(
            'SELECT n.user_id, n.item_id
             FROM %1$snotifications n
             LEFT JOIN %2$s pt
                 ON pt.msg_id = n.item_id
                 AND pt.user_id = n.user_id
             WHERE n.notification_read = 0
                 AND n.notification_type_id = %3$d
                 AND (pt.msg_id IS NULL OR pt.pm_unread = 0 OR pt.pm_deleted = 1)',
            $this->table_prefix,
            $this->t_privmsgs_to,
            $type_id
        );

        if ($user_id > 0)
        {
            $sql .= ' AND n.user_id = ' . $user_id;
        }

        $result = $this->db->sql_query($sql);

        $by_user = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $uid = (int) $row['user_id'];
            if (!isset($by_user[$uid]))
            {
                $by_user[$uid] = [];
            }
            $by_user[$uid][] = (int) $row['item_id'];
        }
        $this->db->sql_freeresult($result);

        if (empty($by_user))
        {
            return 0;
        }

        $cleared = 0;
        foreach ($by_user as $uid => $msg_ids)
        {
            $msg_ids = array_values(array_unique($msg_ids));
            if (empty($msg_ids))
            {
                continue;
            }

            $this->notification_manager->mark_notifications('notification.type.pm', $msg_ids, $uid);
            $cleared += count($msg_ids);
        }

        return $cleared;
    }

    /**
     * Mark all unread PM board notifications as read (ACP one-time cleanup).
     *
     * @return array{notifications: int, privmsgs: int}
     */
    public function cleanup_all_pm_board_notifications()
    {
        $type_id = $this->get_pm_notification_type_id();
        $cleared = 0;

        if ($type_id)
        {
            $sql = 'SELECT COUNT(*) AS total
                    FROM ' . $this->table_prefix . 'notifications
                    WHERE notification_read = 0
                        AND notification_type_id = ' . $type_id;
            $result = $this->db->sql_query($sql);
            $cleared = (int) $this->db->sql_fetchfield('total');
            $this->db->sql_freeresult($result);

            if ($cleared > 0)
            {
                $sql = 'UPDATE ' . $this->table_prefix . 'notifications
                        SET notification_read = 1
                        WHERE notification_read = 0
                            AND notification_type_id = ' . $type_id;
                $this->db->sql_query($sql);
            }
        }

        $privmsgs_reset = $this->reset_all_privmsg_unread_flags();
        $this->recalculate_all_users_unread_privmsg();

        return [
            'notifications' => $cleared,
            'privmsgs'      => $privmsgs_reset,
        ];
    }

    public function count_unread_pms($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0)
        {
            return 0;
        }

        $sql = 'SELECT COUNT(*) AS total
                FROM ' . $this->t_privmsgs_to . '
                WHERE user_id = ' . $user_id . '
                    AND pm_unread = 1
                    AND pm_deleted = 0';
        $result = $this->db->sql_query($sql);
        $count = (int) $this->db->sql_fetchfield('total');
        $this->db->sql_freeresult($result);

        return $count;
    }

    public function recalculate_user_unread_privmsg($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0)
        {
            return 0;
        }

        $count = $this->count_unread_pms($user_id);

        $sql = 'UPDATE ' . $this->table_prefix . 'users
                SET user_unread_privmsg = ' . $count . '
                WHERE user_id = ' . $user_id;
        $this->db->sql_query($sql);

        if ((int) $this->user->data['user_id'] === $user_id)
        {
            $this->user->data['user_unread_privmsg'] = $count;
        }

        return $count;
    }

    public function reset_all_privmsg_unread_flags()
    {
        $sql = 'SELECT COUNT(*) AS total
                FROM ' . $this->t_privmsgs_to . '
                WHERE pm_unread = 1
                    AND pm_deleted = 0';
        $result = $this->db->sql_query($sql);
        $count = (int) $this->db->sql_fetchfield('total');
        $this->db->sql_freeresult($result);

        if ($count > 0)
        {
            $sql = 'UPDATE ' . $this->t_privmsgs_to . '
                    SET pm_unread = 0,
                        pm_new = 0
                    WHERE pm_unread = 1
                        AND pm_deleted = 0';
            $this->db->sql_query($sql);
        }

        return $count;
    }

    public function recalculate_all_users_unread_privmsg()
    {
        $sql = 'UPDATE ' . $this->table_prefix . 'users u
                SET user_unread_privmsg = (
                    SELECT COUNT(*)
                    FROM ' . $this->t_privmsgs_to . ' pt
                    WHERE pt.user_id = u.user_id
                        AND pt.pm_unread = 1
                        AND pt.pm_deleted = 0
                )';
        $this->db->sql_query($sql);

        if ((int) $this->user->data['user_id'] > 0)
        {
            $this->recalculate_user_unread_privmsg((int) $this->user->data['user_id']);
        }
    }

    protected function get_pm_notification_type_id()
    {
        $sql = 'SELECT notification_type_id
                FROM ' . $this->table_prefix . "notification_types
                WHERE notification_type_name = 'notification.type.pm'";
        $result = $this->db->sql_query($sql);
        $type_id = (int) $this->db->sql_fetchfield('notification_type_id');
        $this->db->sql_freeresult($result);

        return $type_id;
    }

    public function get_unread_notifications_count()
    {
        $notifications = $this->notification_manager->load_notifications('notification.method.board', [
            'count_unread' => true,
        ]);

        return (int) $notifications['unread_count'];
    }

    /**
     * Clear board notifications for all messages in a chat (covers orphaned notifications).
     */
    protected function clear_partner_pm_notifications($user_id, $partner_id)
    {
        $user_id    = (int) $user_id;
        $partner_id = (int) $partner_id;

        $sql = sprintf(
            'SELECT p.msg_id
             FROM %1$s p
             INNER JOIN %2$s pt
                 ON pt.msg_id = p.msg_id
                 AND pt.user_id = %3$d
                 AND pt.pm_deleted = 0
             WHERE p.author_id = %4$d',
            $this->t_privmsgs,
            $this->t_privmsgs_to,
            $user_id,
            $partner_id
        );
        $result = $this->db->sql_query($sql);

        $msg_ids = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $msg_ids[] = (int) $row['msg_id'];
        }
        $this->db->sql_freeresult($result);

        if (!empty($msg_ids))
        {
            $this->notification_manager->mark_notifications('notification.type.pm', $msg_ids, $user_id);
        }
    }

    protected function decrement_user_unread_privmsg($user_id, $count)
    {
        $user_id = (int) $user_id;
        $count   = (int) $count;

        if ($user_id <= 0 || $count <= 0)
        {
            return;
        }

        $sql = 'UPDATE ' . $this->table_prefix . 'users
                SET user_unread_privmsg = user_unread_privmsg - ' . $count . '
                WHERE user_id = ' . $user_id;
        $this->db->sql_query($sql);

        if ((int) $this->user->data['user_id'] === $user_id)
        {
            $this->user->data['user_unread_privmsg'] -= $count;

            if ($this->user->data['user_unread_privmsg'] < 0)
            {
                $sql = 'UPDATE ' . $this->table_prefix . 'users
                        SET user_unread_privmsg = 0
                        WHERE user_id = ' . $user_id;
                $this->db->sql_query($sql);

                $this->user->data['user_unread_privmsg'] = 0;
            }
        }
    }

    public function delete_message($user_id, $msg_id, $delete_for_both = false)
    {
        $user_id = (int) $user_id;
        $msg_id  = (int) $msg_id;

        if ($user_id <= 0 || $msg_id <= 0)
        {
            return false;
        }

        $sql = sprintf(
            'SELECT author_id
             FROM %s
             WHERE msg_id = %d',
            $this->t_privmsgs,
            $msg_id
        );
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row || (int) $row['author_id'] !== $user_id)
        {
            return false;
        }

        $folders = $this->get_message_folders($msg_id);
        if (!isset($folders[$user_id]))
        {
            return false;
        }

        if (!$this->remove_message_copy($user_id, $msg_id, $delete_for_both))
        {
            return false;
        }

        return true;
    }

    /**
     * Load BBCode source for editing an own message.
     *
     * @return array{success: bool, text?: string, error?: string}
     */
    public function get_message_edit_source($user_id, $msg_id)
    {
        $row = $this->load_editable_message((int) $user_id, (int) $msg_id);
        if ($row === null)
        {
            return ['success' => false, 'error' => 'MESSENGER_EDIT_FAILED'];
        }

        if (!$this->may_edit_loaded_message($row))
        {
            return ['success' => false, 'error' => 'MESSENGER_EDIT_AFTER_READ'];
        }

        if (!function_exists('generate_text_for_edit'))
        {
            include $this->root_path . 'includes/functions_content.' . $this->php_ext;
        }

        $for_edit = generate_text_for_edit(
            (string) $row['message_text'],
            (string) $row['bbcode_uid'],
            \OPTION_FLAG_BBCODE | \OPTION_FLAG_SMILIES | \OPTION_FLAG_LINKS
        );

        return [
            'success' => true,
            'text'    => html_entity_decode((string) ($for_edit['text'] ?? ''), ENT_QUOTES, 'UTF-8'),
        ];
    }

    /**
     * Update an own private message body.
     *
     * @return array{success: bool, msg_id?: int, partner_id?: int, error?: string}
     */
    public function edit_message($user_id, $msg_id, $message_text)
    {
        $user_id = (int) $user_id;
        $msg_id  = (int) $msg_id;
        $message_text = trim((string) $message_text);

        if ($user_id <= 0 || $msg_id <= 0)
        {
            return ['success' => false, 'error' => 'MESSENGER_EDIT_FAILED'];
        }

        if ($message_text === '')
        {
            return ['success' => false, 'error' => 'MESSENGER_EMPTY_MESSAGE'];
        }

        $row = $this->load_editable_message($user_id, $msg_id);
        if ($row === null)
        {
            return ['success' => false, 'error' => 'MESSENGER_EDIT_FAILED'];
        }

        if (!$this->may_edit_loaded_message($row))
        {
            return ['success' => false, 'error' => 'MESSENGER_EDIT_AFTER_READ'];
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

        $sql_ary = [
            'message_text'       => $message_text,
            'bbcode_uid'         => $uid,
            'bbcode_bitfield'    => $bitfield,
            'message_edit_time'  => time(),
            'message_edit_user'  => $user_id,
            'message_edit_count' => (int) $row['message_edit_count'] + 1,
        ];

        $sql = 'UPDATE ' . $this->t_privmsgs . '
                SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                WHERE msg_id = ' . $msg_id . '
                    AND author_id = ' . $user_id;
        $this->db->sql_query($sql);

        $this->search->index_message($msg_id);

        return [
            'success'    => true,
            'msg_id'     => $msg_id,
            'partner_id' => $this->resolve_message_partner_id($user_id, $msg_id),
        ];
    }

    /**
     * Soft-delete multiple direct chats for the current user.
     *
     * @param int[] $partner_ids
     * @return int[] Deleted partner IDs
     */
    public function delete_chats($user_id, array $partner_ids, $delete_for_both = false)
    {
        $user_id = (int) $user_id;
        $deleted_partners = [];

        foreach (array_values(array_unique(array_filter(array_map('intval', $partner_ids)))) as $partner_id)
        {
            if ($partner_id <= 0)
            {
                continue;
            }

            $this->delete_chat($user_id, $partner_id, $delete_for_both);
            $deleted_partners[] = $partner_id;
        }

        return $deleted_partners;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function load_editable_message($user_id, $msg_id)
    {
        $user_id = (int) $user_id;
        $msg_id  = (int) $msg_id;

        if ($user_id <= 0 || $msg_id <= 0)
        {
            return null;
        }

        $sql = sprintf(
            'SELECT p.msg_id, p.author_id, p.message_text, p.bbcode_uid, p.bbcode_bitfield,
                    p.message_edit_count,
                    (
                        SELECT MIN(pt_peer.pm_unread)
                        FROM %2$s pt_peer
                        WHERE pt_peer.msg_id = p.msg_id
                            AND pt_peer.user_id <> %3$d
                            AND pt_peer.pm_deleted = 0
                    ) AS peer_pm_unread
             FROM %1$s p
             INNER JOIN %2$s pt
                 ON pt.msg_id = p.msg_id
                 AND pt.user_id = %3$d
                 AND pt.pm_deleted = 0
             WHERE p.msg_id = %4$d
                 AND p.author_id = %3$d',
            $this->t_privmsgs,
            $this->t_privmsgs_to,
            $user_id,
            $msg_id
        );
        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function may_edit_loaded_message(array $row)
    {
        if (!empty($this->config['messenger_allow_edit_after_read']))
        {
            return true;
        }

        // No remaining peer copy → allow. Unread (1) → allow. Read (0) → deny.
        if (!array_key_exists('peer_pm_unread', $row) || $row['peer_pm_unread'] === null)
        {
            return true;
        }

        return (int) $row['peer_pm_unread'] === 1;
    }

    protected function resolve_message_partner_id($user_id, $msg_id)
    {
        $user_id = (int) $user_id;
        $msg_id  = (int) $msg_id;

        $sql = sprintf(
            'SELECT user_id
             FROM %s
             WHERE msg_id = %d
                 AND user_id <> %d
             ORDER BY user_id ASC',
            $this->t_privmsgs_to,
            $msg_id,
            $user_id
        );
        $result = $this->db->sql_query_limit($sql, 1);
        $partner_id = (int) $this->db->sql_fetchfield('user_id');
        $this->db->sql_freeresult($result);

        return $partner_id;
    }

    /**
     * @return array<int, int> user_id => folder_id
     */
    protected function get_message_folders($msg_id)
    {
        $msg_id = (int) $msg_id;
        if ($msg_id <= 0)
        {
            return [];
        }

        $sql = sprintf(
            'SELECT user_id, folder_id
             FROM %s
             WHERE msg_id = %d
                 AND pm_deleted = 0',
            $this->t_privmsgs_to,
            $msg_id
        );
        $result = $this->db->sql_query($sql);

        $folders = [];
        while ($row = $this->db->sql_fetchrow($result))
        {
            $folders[(int) $row['user_id']] = (int) $row['folder_id'];
        }
        $this->db->sql_freeresult($result);

        return $folders;
    }

    public function delete_chat($user_id, $partner_id, $delete_for_both = false)
    {
        $user_id    = (int) $user_id;
        $partner_id = (int) $partner_id;

        $deleted_any = false;

        foreach ($this->conversation->get_chat_msg_ids($user_id, $partner_id) as $msg_id)
        {
            if ($this->remove_message_copy((int) $user_id, (int) $msg_id, $delete_for_both))
            {
                $deleted_any = true;
            }
        }

        return $deleted_any;
    }

    protected function remove_message_copy($user_id, $msg_id, $delete_for_both = false)
    {
        $user_id = (int) $user_id;
        $msg_id  = (int) $msg_id;

        $folders = $this->get_message_folders($msg_id);
        if (empty($folders))
        {
            return false;
        }

        if ($delete_for_both && !empty($this->config['messenger_allow_delete_for_both']))
        {
            $deleted_any = $this->hide_message_for_users($msg_id);
            if ($deleted_any)
            {
                $this->search->remove_message($msg_id);
            }

            return $deleted_any;
        }

        if (!isset($folders[$user_id]))
        {
            return false;
        }

        return $this->hide_message_for_users($msg_id, [$user_id]);
    }

    /**
     * Hide a PM for specific users (or all participants) without touching others.
     *
     * phpBB delete_pm() marks every recipient deleted when the sender row is in
     * the outbox — avoid that for messenger "delete for me only".
     *
     * @param int        $msg_id
     * @param int[]|null $user_ids Participant IDs, or null for all
     */
    protected function hide_message_for_users($msg_id, array $user_ids = null)
    {
        $msg_id = (int) $msg_id;
        if ($msg_id <= 0)
        {
            return false;
        }

        $sql = sprintf(
            'UPDATE %s
             SET pm_deleted = 1
             WHERE msg_id = %d
                 AND pm_deleted = 0',
            $this->t_privmsgs_to,
            $msg_id
        );

        if ($user_ids !== null)
        {
            $user_ids = array_filter(array_map('intval', $user_ids));
            if (empty($user_ids))
            {
                return false;
            }

            $sql .= ' AND ' . $this->db->sql_in_set('user_id', $user_ids);
        }

        $this->db->sql_query($sql);

        return (bool) $this->db->sql_affectedrows();
    }

    protected function build_subject_from_message($message_text)
    {
        $text = trim((string) $message_text);
        if ($text === '')
        {
            return $this->language->lang('MESSENGER_DEFAULT_SUBJECT');
        }

        $text = preg_replace("#\r?\n#", ' ', $text);
        $text = preg_replace('#\s+#u', ' ', $text);

        if (utf8_strlen($text) > 45)
        {
            $text = utf8_substr($text, 0, 45) . '…';
        }

        return $text;
    }
}
