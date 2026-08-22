<?php

/**
 * Messenger — one-time cleanup of stale PM notifications
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\migrations;

class cleanup_stale_pm_notifications extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['messenger_stale_notifications_cleaned'])
            && (int) $this->config['messenger_stale_notifications_cleaned'] > 0;
    }

    public static function depends_on()
    {
        return ['\negentiendertien\messenger\migrations\add_messenger_use_permission'];
    }

    public function update_data()
    {
        return [
            ['custom', [[$this, 'run_cleanup']]],
            ['config.add', ['messenger_stale_notifications_cleaned', time()]],
        ];
    }

    public function run_cleanup()
    {
        $p = $this->table_prefix;

        $sql = 'SELECT notification_type_id
                FROM ' . $p . "notification_types
                WHERE notification_type_name = 'notification.type.pm'";
        $result = $this->db->sql_query($sql);
        $type_id = (int) $this->db->sql_fetchfield('notification_type_id');
        $this->db->sql_freeresult($result);

        if (!$type_id)
        {
            return;
        }

        $sql = 'UPDATE ' . $p . 'notifications n
                INNER JOIN ' . $p . 'privmsgs_to pt
                    ON pt.msg_id = n.item_id
                    AND pt.user_id = n.user_id
                SET n.notification_read = 1
                WHERE n.notification_read = 0
                    AND n.notification_type_id = ' . $type_id . '
                    AND (pt.pm_unread = 0 OR pt.pm_deleted = 1)';
        $this->db->sql_query($sql);
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['messenger_stale_notifications_cleaned']],
        ];
    }
}
