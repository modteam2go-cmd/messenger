<?php

/**
 * Messenger — shared page rendering for routes and UCP
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\service;

class display_service
{
    /** @var \phpbb\config\config */
    protected $config;

    /** @var \phpbb\controller\helper */
    protected $helper;

    /** @var \phpbb\language\language */
    protected $language;

    /** @var \phpbb\request\request */
    protected $request;

    /** @var \phpbb\template\template */
    protected $template;

    /** @var \phpbb\user */
    protected $user;

    /** @var \negentiendertien\messenger\service\conversation_service */
    protected $conversation;

    /** @var \negentiendertien\messenger\service\message_service */
    protected $message;

    /** @var \negentiendertien\messenger\service\member_service */
    protected $member;

    /** @var \negentiendertien\messenger\service\smiley_service */
    protected $smiley;

    /** @var \negentiendertien\messenger\service\group_service */
    protected $group;

    /** @var \negentiendertien\messenger\service\user_settings_service */
    protected $user_settings;

    /** @var \negentiendertien\messenger\service\route_helper */
    protected $routes;

    public function __construct(
        \phpbb\config\config $config,
        \phpbb\controller\helper $helper,
        \phpbb\language\language $language,
        \phpbb\request\request $request,
        \phpbb\template\template $template,
        \phpbb\user $user,
        \negentiendertien\messenger\service\conversation_service $conversation,
        \negentiendertien\messenger\service\message_service $message,
        \negentiendertien\messenger\service\member_service $member,
        \negentiendertien\messenger\service\smiley_service $smiley,
        \negentiendertien\messenger\service\group_service $group,
        \negentiendertien\messenger\service\user_settings_service $user_settings,
        \negentiendertien\messenger\service\route_helper $routes
    ) {
        $this->config        = $config;
        $this->helper        = $helper;
        $this->language      = $language;
        $this->request       = $request;
        $this->template      = $template;
        $this->user          = $user;
        $this->conversation  = $conversation;
        $this->message       = $message;
        $this->member        = $member;
        $this->smiley        = $smiley;
        $this->group         = $group;
        $this->user_settings = $user_settings;
        $this->routes        = $routes;
    }

    public function render_roster($ucp = false)
    {
        $this->assert_can_use();

        $user_id = (int) $this->user->data['user_id'];
        $this->assign_roster($user_id, 0);
        $this->assign_common_vars($ucp);
        $this->template->assign_vars([
            'S_MESSENGER_ROSTER' => true,
            'MSG_COUNT'          => $this->conversation->count_total_messages($user_id),
            'U_COMPOSE'          => $this->routes->compose_url(),
            'U_PINNED'           => $this->routes->pinned_url(),
        ]);

        return $this->language->lang('MESSENGER_TITLE');
    }

    public function render_chat($partner_id, $ucp = false)
    {
        $this->assert_can_use();

        $user_id    = (int) $this->user->data['user_id'];
        $partner_id = (int) $partner_id;

        $chat = $this->conversation->get_chat($user_id, $partner_id, 1, 10);
        if (!$chat['partner'])
        {
            throw new \phpbb\exception\http_exception(404, 'MESSENGER_CHAT_NOT_FOUND');
        }

        $this->assign_roster($user_id, $partner_id);
        $this->message->mark_chat_read($user_id, $partner_id);

        $last_msg_id = 0;
        foreach ($chat['messages'] as $message)
        {
            $last_msg_id = max($last_msg_id, (int) $message['msg_id']);
            $this->template->assign_block_vars('message_row', [
                'MSG_ID'          => $message['msg_id'],
                'AUTHOR_ID'       => $message['author_id'],
                'AUTHOR_NAME'     => $message['author_username'],
                'MESSAGE_PLAIN'   => $message['message_plain'],
                'S_IS_OWN'        => $message['is_own'],
                'S_CAN_EDIT'      => !empty($message['can_edit']),
                'S_IS_EDITED'     => !empty($message['is_edited']),
                'MESSAGE'         => $message['message_html'],
                'TIME'            => $message['time_formatted'],
                'MESSAGE_TIME'    => (int) ($message['message_time'] ?? 0),
                'READ_STATUS'     => $message['read_status'] ?? '',
                'S_READ'          => ($message['read_status'] ?? '') === 'read',
                'S_DELIVERED'     => ($message['read_status'] ?? '') === 'delivered',
                'S_HAS_ATTACHMENT'=> $message['has_attachment'],
            ]);
        }

        $quote_post_id = (int) $this->request->variable('quote_post', 0);
        $prefill = $quote_post_id > 0 ? $this->message->get_post_quote_text($quote_post_id) : '';

        $partner = $chat['partner'];
        $this->assign_common_vars($ucp);
        $this->template->assign_vars([
            'S_MESSENGER_CHAT'   => true,
            'MESSENGER_PREFILL'  => $prefill,
            'PARTNER_ID'         => $partner_id,
            'PARTNER_NAME'       => $partner['username'],
            'PARTNER_COLOUR'     => $partner['user_colour'],
            'PARTNER_AVATAR'     => $partner['avatar'],
            'PARTNER_IS_ONLINE'  => !empty($partner['is_online']),
            'PARTNER_PRESENCE'   => $this->conversation->format_presence_text(
                (int) $partner['last_visit'],
                !empty($partner['is_online'])
            ),
            'MSG_TOTAL'          => (int) $chat['total'],
            'MSG_COUNT'          => $this->conversation->count_total_messages($user_id),
            'LAST_MSG_ID'        => $last_msg_id,
            'OLDEST_MSG_ID'      => (int) ($chat['oldest_msg_id'] ?? 0),
            'S_HAS_OLDER'        => !empty($chat['has_older']),
            'MESSENGER_SEND_HASH'=> generate_link_hash('messenger_send'),
            'U_ROSTER'           => $this->routes->roster_url(),
            'U_COMPOSE'          => $this->routes->compose_url(),
            'U_PINNED'           => $this->routes->pinned_url(),
            'U_PARTNER_PROFILE'  => $this->routes->profile_url($partner_id),
            'U_MESSENGER_API_CHAT' => $this->helper->route('negentiendertien_messenger_api_chat', [
                'partner_id' => $partner_id,
            ]),
            'U_MESSENGER_API_READ' => $this->helper->route('negentiendertien_messenger_api_read', [
                'partner_id' => $partner_id,
            ]),
            'MESSENGER_SMILIES_JSON' => json_encode(
                $this->smiley->get_posting_smilies(),
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            ),
        ]);

        return $this->language->lang('MESSENGER_TITLE') . ' / ' . $partner['username'];
    }

    public function render_group($group_id, $ucp = false)
    {
        $this->assert_can_use();

        $user_id  = (int) $this->user->data['user_id'];
        $group_id = (int) $group_id;

        $chat = $this->group->get_group($user_id, $group_id, 1, 30);
        if (!$chat)
        {
            throw new \phpbb\exception\http_exception(404, 'MESSENGER_CHAT_NOT_FOUND');
        }

        $this->assign_roster($user_id, 0, $group_id);
        $this->group->mark_read($user_id, $group_id);

        $last_msg_id = 0;
        foreach ($chat['messages'] as $message)
        {
            $last_msg_id = max($last_msg_id, (int) $message['msg_id']);
            $this->template->assign_block_vars('message_row', [
                'MSG_ID'          => $message['msg_id'],
                'AUTHOR_ID'       => $message['author_id'],
                'AUTHOR_NAME'     => $message['author_username'],
                'MESSAGE_PLAIN'   => $message['message_plain'],
                'S_IS_OWN'        => $message['is_own'],
                'MESSAGE'         => $message['message_html'],
                'TIME'            => $message['time_formatted'],
                'MESSAGE_TIME'    => (int) ($message['message_time'] ?? 0),
                'READ_STATUS'     => '',
                'S_READ'          => false,
                'S_DELIVERED'     => false,
                'S_HAS_ATTACHMENT'=> false,
                'S_SHOW_AUTHOR'   => true,
            ]);
        }

        $this->assign_common_vars($ucp);
        $this->template->assign_vars([
            'S_MESSENGER_GROUP'   => true,
            'GROUP_ID'            => $group_id,
            'GROUP_TITLE'         => $chat['group_title'],
            'GROUP_MEMBER_COUNT'  => (int) $chat['member_count'],
            'GROUP_MEMBERS'       => implode(', ', $chat['members']),
            'MSG_TOTAL'           => (int) $chat['total'],
            'MSG_COUNT'           => $this->conversation->count_total_messages($user_id),
            'LAST_MSG_ID'         => $last_msg_id,
            'OLDEST_MSG_ID'       => (int) ($chat['oldest_msg_id'] ?? 0),
            'S_HAS_OLDER'         => !empty($chat['has_older']),
            'MESSENGER_SEND_HASH' => generate_link_hash('messenger_send'),
            'MESSENGER_GROUP_CREATE_HASH' => generate_link_hash('messenger_group_create'),
            'U_ROSTER'            => $this->routes->roster_url(),
            'U_COMPOSE'           => $this->routes->compose_url(),
            'U_MESSENGER_API_GROUP' => $this->helper->route('negentiendertien_messenger_api_group_chat', [
                'group_id' => $group_id,
            ]),
            'U_MESSENGER_API_GROUP_SEND' => $this->helper->route('negentiendertien_messenger_api_group_send', [
                'group_id' => $group_id,
            ]),
            'U_MESSENGER_API_GROUP_READ' => $this->helper->route('negentiendertien_messenger_api_group_read', [
                'group_id' => $group_id,
            ]),
            'MESSENGER_SMILIES_JSON' => json_encode(
                $this->smiley->get_posting_smilies(),
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            ),
        ]);

        return $this->language->lang('MESSENGER_TITLE') . ' / ' . $chat['group_title'];
    }

    public function render_pinned($ucp = false)
    {
        $this->assert_can_use();
        $this->assign_common_vars($ucp);
        $this->template->assign_vars([
            'S_MESSENGER_PINNED' => true,
            'U_ROSTER'           => $this->routes->roster_url(),
        ]);

        return $this->language->lang('MESSENGER_PINNED_ALL');
    }

    public function render_compose($ucp = false)
    {
        $this->assert_can_send();

        $to_id = (int) $this->request->variable('to', 0);
        if ($to_id > 0)
        {
            if (!$this->member->can_message_user($to_id))
            {
                throw new \phpbb\exception\http_exception(403, 'MESSENGER_INVALID_RECIPIENT');
            }

            redirect($this->routes->chat_url($to_id));
        }

        $user_id = (int) $this->user->data['user_id'];
        $this->assign_roster($user_id, 0);

        $this->assign_common_vars($ucp);
        $this->template->assign_vars([
            'S_MESSENGER_COMPOSE'        => true,
            'S_MESSENGER_GROUP_CHAT'     => $this->group->is_enabled(),
            'MSG_COUNT'                  => $this->conversation->count_total_messages($user_id),
            'MESSENGER_GROUP_CREATE_HASH'=> generate_link_hash('messenger_group_create'),
            'MESSENGER_SEND_HASH'        => generate_link_hash('messenger_send'),
            'U_COMPOSE'                  => $this->routes->compose_url(),
            'U_PINNED'                   => $this->routes->pinned_url(),
            'U_ROSTER'                   => $this->routes->roster_url(),
            'U_MESSENGER_CHAT_TEMPLATE'  => $this->routes->chat_template_url(),
        ]);

        return $this->language->lang('MESSENGER_COMPOSE');
    }

    protected function assign_roster($user_id, $active_partner_id = 0, $active_group_id = 0)
    {
        $user_id           = (int) $user_id;
        $active_partner_id = (int) $active_partner_id;
        $active_group_id   = (int) $active_group_id;
        $rows              = [];

        foreach ($this->conversation->get_roster($user_id) as $entry)
        {
            $formatted = $this->conversation->format_roster_entry($entry, $user_id);
            $formatted['chat_type'] = 'direct';
            $formatted['group_id'] = 0;
            $rows[] = $formatted;
        }

        foreach ($this->group->get_roster($user_id) as $entry)
        {
            $rows[] = $entry;
        }

        usort($rows, function ($a, $b) {
            return ((int) ($b['last_time'] ?? 0)) <=> ((int) ($a['last_time'] ?? 0));
        });

        foreach ($rows as $formatted)
        {
            $is_group = (($formatted['chat_type'] ?? 'direct') === 'group');
            $chat_url = $is_group
                ? $this->routes->group_url((int) $formatted['group_id'])
                : $this->routes->chat_url((int) $formatted['partner_id']);

            $this->template->assign_block_vars('chat_row', [
                'PARTNER_ID'     => (int) ($formatted['partner_id'] ?? 0),
                'GROUP_ID'       => (int) ($formatted['group_id'] ?? 0),
                'S_IS_GROUP'     => $is_group,
                'MEMBER_COUNT'   => (int) ($formatted['member_count'] ?? 0),
                'USERNAME'       => $formatted['username'],
                'USER_COLOUR'    => $formatted['user_colour'],
                'AVATAR'         => $formatted['avatar'],
                'TIME_FORMATTED' => $formatted['time_formatted'],
                'LAST_TIME'      => (int) ($formatted['last_time'] ?? 0),
                'PREVIEW'        => $formatted['preview'],
                'UNREAD_COUNT'   => $formatted['unread_count'],
                'IS_PINNED'      => $formatted['is_pinned'],
                'IS_ONLINE'      => $formatted['is_online'],
                'S_IS_ACTIVE'    => $is_group
                    ? ((int) $formatted['group_id'] === $active_group_id)
                    : ((int) $formatted['partner_id'] === $active_partner_id),
                'CHAT_URL'       => $chat_url,
            ]);
        }

        $this->template->assign_vars([
            'CHAT_COUNT' => count($rows),
        ]);
    }

    protected function assign_common_vars($ucp = false)
    {
        $this->language->add_lang('common', 'negentiendertien/messenger');

        if ($ucp)
        {
            $this->template->append_var('BODY_CLASS', 'msgr-ucp');
        }
        elseif (empty($this->config['messenger_show_header_footer']))
        {
            $this->template->append_var('BODY_CLASS', 'msgr-standalone');
        }
        else
        {
            $this->template->append_var('BODY_CLASS', 'msgr-embedded');
        }

        $this->template->assign_vars([
            'S_MESSENGER'            => true,
            'S_MESSENGER_UCP'        => $ucp,
            'S_MESSENGER_SHOW_HEADER_FOOTER' => $ucp || !empty($this->config['messenger_show_header_footer']),
            'MESSENGER_POLL_INTERVAL'=> (int) $this->config['messenger_poll_interval'],
            'U_MESSENGER_ROSTER'     => $this->routes->roster_url(),
            'U_MESSENGER_API_ROSTER' => $this->helper->route('negentiendertien_messenger_api_roster'),
            'U_MESSENGER_API_SEND'   => $this->helper->route('negentiendertien_messenger_api_send'),
            'U_MESSENGER_API_SEND_BULK' => $this->helper->route('negentiendertien_messenger_api_send_bulk'),
            'U_MESSENGER_API_UPLOAD' => $this->helper->route('negentiendertien_messenger_api_upload'),
            'S_MESSENGER_IMAGE_UPLOAD' => $this->message->can_upload_images(),
            'S_MESSENGER_SHOW_IMAGE_UPLOAD' => $this->message->can_show_image_upload(),
            'MESSENGER_UPLOAD_HASH'  => generate_link_hash('messenger_upload'),
            'U_MESSENGER_API_POLL'   => $this->helper->route('negentiendertien_messenger_api_poll'),
            'U_MESSENGER_API_TYPING' => $this->helper->route('negentiendertien_messenger_api_typing'),
            'U_MESSENGER_API_SEARCH' => $this->helper->route('negentiendertien_messenger_api_search'),
            'U_MESSENGER_API_MEMBERS'=> $this->helper->route('negentiendertien_messenger_api_members'),
            'U_MESSENGER_API_GROUP_MEMBERS'=> $this->helper->route('negentiendertien_messenger_api_group_members'),
            'U_MESSENGER_API_GROUP_CREATE'=> $this->helper->route('negentiendertien_messenger_api_group_create'),
            'U_MESSENGER_GROUP_TEMPLATE' => $this->routes->group_template_url(),
            'S_MESSENGER_GROUP_CHAT' => $this->group->is_enabled(),
            'MESSENGER_GROUP_CREATE_HASH' => generate_link_hash('messenger_group_create'),
            'U_MESSENGER_API_PIN_CHAT' => $this->helper->route('negentiendertien_messenger_api_pin_chat', [
                'partner_id' => 0,
            ]),
            'U_MESSENGER_API_DELETE_CHAT' => $this->helper->route('negentiendertien_messenger_api_delete_chat', [
                'partner_id' => 0,
            ]),
            'U_MESSENGER_API_DELETE_CHATS' => $this->helper->route('negentiendertien_messenger_api_delete_chats'),
            'U_MESSENGER_API_GROUP_DELETE' => $this->helper->route('negentiendertien_messenger_api_group_delete', [
                'group_id' => 0,
            ]),
            'U_MESSENGER_API_DELETE_MESSAGE' => $this->helper->route('negentiendertien_messenger_api_delete_message', [
                'msg_id' => 0,
            ]),
            'U_MESSENGER_API_EDIT_MESSAGE' => $this->helper->route('negentiendertien_messenger_api_edit_message', [
                'msg_id' => 0,
            ]),
            'U_MESSENGER_CHAT_TEMPLATE' => $this->routes->chat_template_url(),
            'CURRENT_USER_ID'        => (int) $this->user->data['user_id'],
            'CURRENT_USERNAME'       => $this->user->data['username'],
            'U_MESSENGER_API_READ_TEMPLATE' => $this->helper->route('negentiendertien_messenger_api_read', [
                'partner_id' => 0,
            ]),
            'S_MESSENGER_ALLOW_DELETE_BOTH' => !empty($this->config['messenger_allow_delete_for_both']),
            'MESSENGER_SEND_HASH'          => generate_link_hash('messenger_send'),
            'NOTIFICATIONS_COUNT'           => $this->message->get_unread_notifications_count(),
            'UNREAD_PM_COUNT'               => (int) $this->user->data['user_unread_privmsg'],
            'U_MESSENGER_WALLPAPER'         => $this->helper->route('negentiendertien_messenger_wallpaper'),
            'U_MESSENGER_API_WALLPAPER'     => $this->helper->route('negentiendertien_messenger_api_wallpaper'),
            'U_MESSENGER_API_WALLPAPER_UPLOAD' => $this->helper->route('negentiendertien_messenger_api_wallpaper_upload'),
            'MESSENGER_WALLPAPER_HASH'      => generate_link_hash('messenger_wallpaper'),
            'S_MESSENGER_GIPHY'            => true,
            'MESSENGER_GIPHY_JSON'         => json_encode(
                $this->get_giphy_config(),
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            ),
        ]);

        $this->assign_wallpaper_vars((int) $this->user->data['user_id']);
    }

    /**
     * Board-wide Giphy settings from phpbbstudio/gif (when installed).
     *
     * @return array{enabled: bool, apiKey: string, rating: string, lang: string, limit: int, original: bool, autoImage: bool, miniViewport: bool}
     */
    protected function get_giphy_config()
    {
        $rating = (string) ($this->config['studio_giphy_rating'] ?? 'g');
        if ($rating === '' || $rating === 'none')
        {
            $rating = 'g';
        }

        $limit = (int) ($this->config['studio_giphy_offset'] ?? 25);
        if ($limit < 1)
        {
            $limit = 25;
        }
        elseif ($limit > 50)
        {
            $limit = 50;
        }

        return [
            'enabled'      => true,
            'apiKey'       => (string) ($this->config['studio_giphy_apikey'] ?? ''),
            'rating'       => $rating,
            'lang'         => (string) ($this->config['studio_giphy_lang'] ?? 'nl'),
            'limit'        => $limit,
            'original'     => !empty($this->config['studio_giphy_original']),
            'autoImage'    => !empty($this->config['studio_giphy_autoimage']),
            'miniViewport' => empty($this->config['studio_giphy_viewport']),
        ];
    }

    protected function assign_wallpaper_vars($user_id)
    {
        $wallpaper = $this->user_settings->get_chat_wallpaper($user_id);
        $presets = [];

        foreach ($this->user_settings->get_wallpaper_presets() as $id => $lang_key)
        {
            $presets[] = [
                'id'    => $id,
                'label' => $this->language->lang($lang_key),
            ];
        }

        $custom_url = '';
        if ($wallpaper['wallpaper'] === 'custom')
        {
            $custom_url = $this->helper->route('negentiendertien_messenger_wallpaper');
            if (!empty($wallpaper['custom_file']))
            {
                $custom_url .= '?t=' . (int) @filemtime($this->user_settings->get_custom_wallpaper_path($user_id));
            }
        }

        $this->template->assign_vars([
            'CHAT_WALLPAPER'                 => $wallpaper['wallpaper'],
            'CHAT_WALLPAPER_CUSTOM_URL'      => $custom_url,
            'MESSENGER_WALLPAPER_PRESETS_JSON'=> json_encode(
                $presets,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            ),
        ]);
    }

    protected function assert_can_use()
    {
        $this->language->add_lang('common', 'negentiendertien/messenger');

        if (!$this->message->can_use_messenger())
        {
            throw new \phpbb\exception\http_exception(403, 'MESSENGER_NO_ACCESS');
        }

        $this->message->cleanup_stale_pm_notifications((int) $this->user->data['user_id']);
        $this->message->recalculate_user_unread_privmsg((int) $this->user->data['user_id']);
    }

    protected function assert_can_send()
    {
        $this->assert_can_use();

        if (!$this->message->can_send_message())
        {
            throw new \phpbb\exception\http_exception(403, 'MESSENGER_NO_SEND');
        }
    }
}
