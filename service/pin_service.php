<?php

/**
 * Messenger — pin chats and messages
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\service;

class pin_service
{
    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var string */
    protected $t_chat_pins;

    /** @var string */
    protected $t_message_pins;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        $table_prefix
    ) {
        $this->db = $db;
        $this->t_chat_pins    = $table_prefix . 'messenger_chat_pins';
        $this->t_message_pins = $table_prefix . 'messenger_message_pins';
    }

    /**
     * @return int[]
     */
    public function get_pinned_chat_ids($user_id)
    {
        $user_id = (int) $user_id;
        $ids = [];

        $sql = sprintf(
            'SELECT partner_id
             FROM %s
             WHERE user_id = %d
             ORDER BY pinned_at DESC',
            $this->t_chat_pins,
            $user_id
        );
        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result))
        {
            $ids[] = (int) $row['partner_id'];
        }
        $this->db->sql_freeresult($result);

        return $ids;
    }

    public function toggle_chat_pin($user_id, $partner_id)
    {
        $user_id    = (int) $user_id;
        $partner_id = (int) $partner_id;

        if ($this->is_chat_pinned($user_id, $partner_id))
        {
            $sql = sprintf(
                'DELETE FROM %s
                 WHERE user_id = %d
                     AND partner_id = %d',
                $this->t_chat_pins,
                $user_id,
                $partner_id
            );
            $this->db->sql_query($sql);

            return false;
        }

        $sql = 'INSERT INTO ' . $this->t_chat_pins . ' ' . $this->db->sql_build_array('INSERT', [
            'user_id'    => $user_id,
            'partner_id' => $partner_id,
            'pinned_at'  => time(),
        ]);
        $this->db->sql_query($sql);

        return true;
    }

    public function is_chat_pinned($user_id, $partner_id)
    {
        $sql = 'SELECT 1 AS found
                FROM ' . $this->t_chat_pins . '
                WHERE user_id = ' . (int) $user_id . '
                    AND partner_id = ' . (int) $partner_id;
        $result = $this->db->sql_query($sql);
        $found = (bool) $this->db->sql_fetchfield('found');
        $this->db->sql_freeresult($result);

        return $found;
    }

    public function toggle_message_pin($user_id, $msg_id)
    {
        $user_id = (int) $user_id;
        $msg_id  = (int) $msg_id;

        if ($this->is_message_pinned($user_id, $msg_id))
        {
            $sql = sprintf(
                'DELETE FROM %s
                 WHERE user_id = %d
                     AND msg_id = %d',
                $this->t_message_pins,
                $user_id,
                $msg_id
            );
            $this->db->sql_query($sql);

            return false;
        }

        $sql = 'INSERT INTO ' . $this->t_message_pins . ' ' . $this->db->sql_build_array('INSERT', [
            'user_id'   => $user_id,
            'msg_id'    => $msg_id,
            'pinned_at' => time(),
        ]);
        $this->db->sql_query($sql);

        return true;
    }

    public function is_message_pinned($user_id, $msg_id)
    {
        $sql = 'SELECT 1 AS found
                FROM ' . $this->t_message_pins . '
                WHERE user_id = ' . (int) $user_id . '
                    AND msg_id = ' . (int) $msg_id;
        $result = $this->db->sql_query($sql);
        $found = (bool) $this->db->sql_fetchfield('found');
        $this->db->sql_freeresult($result);

        return $found;
    }
}
