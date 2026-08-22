<?php

/**
 * Messenger — ACP module
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\acp;

class main_module
{
    public $u_action;
    public $tpl_name;
    public $page_title;

    public function main($id, $mode)
    {
        global $language, $template, $request, $config, $db;

        $language->add_lang('info_acp_messenger', 'negentiendertien/messenger');
        $this->page_title = $language->lang('ACP_MESSENGER_TITLE');
        $this->tpl_name   = 'acp_messenger_settings';

        \add_form_key('messenger_settings');
        \add_form_key('messenger_cleanup', '_CLEANUP');

        if ($request->is_set_post('cleanup_notifications') && $request->variable('cleanup_notifications', 0))
        {
            if (!\check_form_key('messenger_cleanup'))
            {
                trigger_error($language->lang('FORM_INVALID') . \adm_back_link($this->u_action), E_USER_WARNING);
            }

            global $phpbb_container;

            /** @var \negentiendertien\messenger\service\message_service $message */
            $message = $phpbb_container->get('negentiendertien.messenger.service.message');
            $cleared = $message->cleanup_all_pm_board_notifications();

            trigger_error($language->lang(
                'ACP_MESSENGER_NOTIFICATIONS_CLEANED',
                (int) $cleared['notifications'],
                (int) $cleared['privmsgs']
            ) . \adm_back_link($this->u_action));
        }

        if ($request->is_set_post('submit'))
        {
            if (!\check_form_key('messenger_settings'))
            {
                trigger_error($language->lang('FORM_INVALID') . \adm_back_link($this->u_action), E_USER_WARNING);
            }

            $config->set('messenger_enabled', $request->variable('messenger_enabled', 0));
            $config->set('messenger_poll_interval', max(3, min(60, (int) $request->variable('messenger_poll_interval', 8))));
            $config->set('messenger_allow_edit_after_read', $request->variable('messenger_allow_edit_after_read', 0));
            $config->set('messenger_allow_delete_for_both', $request->variable('messenger_allow_delete_for_both', 0));
            $config->set('messenger_visible_pm_link', $request->variable('messenger_visible_pm_link', 0));
            $config->set('messenger_show_header_footer', $request->variable('messenger_show_header_footer', 1));
            $config->set('messenger_ucp_mode', $request->variable('messenger_ucp_mode', 0));
            $config->set('messenger_group_chat_enabled', $request->variable('messenger_group_chat_enabled', 0));

            global $phpbb_container;

            /** @var \negentiendertien\messenger\service\ucp_module_installer $ucp_module */
            $ucp_module = $phpbb_container->get('negentiendertien.messenger.service.ucp_module');
            if (!empty($config['messenger_ucp_mode']))
            {
                if (!$ucp_module->is_installed())
                {
                    $ucp_module->install();
                }
                else
                {
                    $ucp_module->purge_legacy_extra_modes();
                }

                $ucp_module->set_enabled(true);
            }

            $selected_groups = $request->variable('messenger_group_chat_groups', [0]);
            $selected_groups = array_values(array_unique(array_filter(array_map('intval', $selected_groups))));
            $config->set('messenger_group_chat_groups', implode(',', $selected_groups));

            trigger_error($language->lang('ACP_MESSENGER_SAVED') . \adm_back_link($this->u_action));
        }

        $selected_group_ids = array_filter(array_map('intval', explode(',', (string) $config['messenger_group_chat_groups'])));

        global $phpbb_container;

        /** @var \phpbb\group\helper $group_helper */
        $group_helper = $phpbb_container->get('group_helper');

        $sql = 'SELECT group_id, group_name
                FROM ' . GROUPS_TABLE . '
                WHERE group_type = ' . GROUP_SPECIAL . '
                ORDER BY group_name ASC';
        $result = $db->sql_query($sql);
        $groups = [];
        while ($row = $db->sql_fetchrow($result))
        {
            $groups[] = [
                'GROUP_ID'   => (int) $row['group_id'],
                'GROUP_NAME' => $group_helper->get_name($row['group_name']),
                'S_SELECTED' => in_array((int) $row['group_id'], $selected_group_ids, true),
            ];
        }
        $db->sql_freeresult($result);

        // Sort on the translated name instead of the internal key.
        usort($groups, function ($a, $b) {
            return strcasecmp($a['GROUP_NAME'], $b['GROUP_NAME']);
        });

        foreach ($groups as $group_row)
        {
            $template->assign_block_vars('group_chat_groups', $group_row);
        }

        $template->assign_vars([
            'U_ACTION'                          => $this->u_action,
            'S_MESSENGER_ENABLED'               => (bool) $config['messenger_enabled'],
            'MESSENGER_POLL_INTERVAL'           => (int) $config['messenger_poll_interval'],
            'S_MESSENGER_ALLOW_EDIT_AFTER_READ' => (bool) $config['messenger_allow_edit_after_read'],
            'S_MESSENGER_ALLOW_DELETE_FOR_BOTH' => (bool) $config['messenger_allow_delete_for_both'],
            'S_MESSENGER_VISIBLE_PM_LINK'       => (bool) $config['messenger_visible_pm_link'],
            'S_MESSENGER_SHOW_HEADER_FOOTER'    => (bool) $config['messenger_show_header_footer'],
            'S_MESSENGER_UCP_MODE'              => (bool) $config['messenger_ucp_mode'],
            'S_MESSENGER_GROUP_CHAT_ENABLED'    => (bool) $config['messenger_group_chat_enabled'],
            'S_SEARCH_INDEX_BUILT'              => (bool) $config['messenger_search_index_built'],
        ]);
    }
}
