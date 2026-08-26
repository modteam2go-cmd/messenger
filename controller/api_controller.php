<?php

/**
 * Messenger — JSON API
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

class api_controller
{
    /** @var \phpbb\config\config */
    protected $config;

    /** @var \phpbb\controller\helper */
    protected $helper;

    /** @var \phpbb\language\language */
    protected $language;

    /** @var \phpbb\request\request */
    protected $request;

    /** @var \phpbb\user */
    protected $user;

    /** @var \phpbb\auth\auth */
    protected $auth;

    /** @var \negentiendertien\messenger\service\conversation_service */
    protected $conversation;

    /** @var \negentiendertien\messenger\service\message_service */
    protected $message;

    /** @var \negentiendertien\messenger\service\attachment_service */
    protected $attachment;

    /** @var \negentiendertien\messenger\service\pin_service */
    protected $pin;

    /** @var \negentiendertien\messenger\service\search_service */
    protected $search;

    /** @var \negentiendertien\messenger\service\member_service */
    protected $member;

    /** @var \negentiendertien\messenger\service\group_service */
    protected $group;

    /** @var \negentiendertien\messenger\service\typing_service */
    protected $typing;

    /** @var \negentiendertien\messenger\service\user_settings_service */
    protected $user_settings;

    /** @var \negentiendertien\messenger\service\route_helper */
    protected $routes;

    /** @var \phpbb\notification\manager */
    protected $notification_manager;

    public function __construct(
        \phpbb\config\config $config,
        \phpbb\controller\helper $helper,
        \phpbb\language\language $language,
        \phpbb\request\request $request,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        \negentiendertien\messenger\service\conversation_service $conversation,
        \negentiendertien\messenger\service\message_service $message,
        \negentiendertien\messenger\service\attachment_service $attachment,
        \negentiendertien\messenger\service\pin_service $pin,
        \negentiendertien\messenger\service\search_service $search,
        \negentiendertien\messenger\service\member_service $member,
        \negentiendertien\messenger\service\group_service $group,
        \negentiendertien\messenger\service\typing_service $typing,
        \negentiendertien\messenger\service\user_settings_service $user_settings,
        \negentiendertien\messenger\service\route_helper $routes,
        \phpbb\notification\manager $notification_manager
    ) {
        $this->config               = $config;
        $this->helper               = $helper;
        $this->language             = $language;
        $this->request              = $request;
        $this->user                 = $user;
        $this->auth                 = $auth;
        $this->conversation         = $conversation;
        $this->message              = $message;
        $this->attachment           = $attachment;
        $this->pin                  = $pin;
        $this->search               = $search;
        $this->member               = $member;
        $this->group                = $group;
        $this->typing               = $typing;
        $this->user_settings        = $user_settings;
        $this->routes               = $routes;
        $this->notification_manager = $notification_manager;
    }

    public function roster()
    {
        $this->assert_can_use();

        $user_id = (int) $this->user->data['user_id'];

        return new JsonResponse([
            'chats'    => $this->build_roster_payload($user_id),
            'total'    => $this->conversation->count_total_chats($user_id),
            'messages' => $this->conversation->count_total_messages($user_id),
        ]);
    }

    public function chat($partner_id)
    {
        $this->assert_can_use();

        $user_id    = (int) $this->user->data['user_id'];
        $partner_id = (int) $partner_id;
        $since      = max(0, $this->request->variable('since', 0));
        $before     = max(0, $this->request->variable('before', 0));
        $limit      = max(1, min(100, $this->request->variable('limit', 20)));

        if ($since > 0)
        {
            $messages = $this->conversation->get_new_messages($user_id, $partner_id, $since);
            return new JsonResponse(['messages' => $messages]);
        }

        if ($before > 0)
        {
            $older = $this->conversation->get_older_messages($user_id, $partner_id, $before, $limit);
            return new JsonResponse($older);
        }

        $chat = $this->conversation->get_chat($user_id, $partner_id, 1, 10);
        if (!$chat['partner'])
        {
            return new JsonResponse(['error' => 'MESSENGER_CHAT_NOT_FOUND'], 404);
        }

        return new JsonResponse($chat);
    }

    public function send()
    {
        $this->assert_can_send();

        if (!check_link_hash($this->request->variable('hash', ''), 'messenger_send'))
        {
            return new JsonResponse(['success' => false, 'error' => 'FORM_INVALID'], 403);
        }

        $recipient_id = $this->request->variable('partner_id', 0);
        $message_text = $this->request->variable('message', '', true);
        $subject      = $this->request->variable('subject', '', true);
        $attachment_ids = $this->get_post_int_array('attachment_ids');

        $result = $this->message->send_message($recipient_id, $message_text, $subject, $attachment_ids);
        if (!$result['success'])
        {
            return new JsonResponse([
                'success' => false,
                'error'   => $result['error'],
            ], 400);
        }

        $user_id = (int) $this->user->data['user_id'];
        $sent = $this->conversation->get_message($user_id, (int) $recipient_id, (int) $result['msg_id']);

        return new JsonResponse([
            'success' => true,
            'msg_id'  => (int) $result['msg_id'],
            'message' => $sent,
        ]);
    }

    public function send_bulk()
    {
        $this->assert_can_send();

        if (!check_link_hash($this->request->variable('hash', ''), 'messenger_send'))
        {
            return new JsonResponse(['success' => false, 'error' => 'FORM_INVALID'], 403);
        }

        $recipient_ids = $this->get_post_int_array('recipient_ids');
        $message_text  = $this->request->variable('message', '', true);
        $subject       = $this->request->variable('subject', '', true);

        $valid_recipient_ids = [];
        foreach ($recipient_ids as $recipient_id)
        {
            if ($this->member->can_message_user($recipient_id))
            {
                $valid_recipient_ids[] = (int) $recipient_id;
            }
        }

        $result = $this->message->send_message_to_recipients($valid_recipient_ids, $message_text, $subject);
        if (!$result['success'])
        {
            $error = $result['error'] ?? 'MESSENGER_SEND_FAILED';

            return new JsonResponse([
                'success' => false,
                'error'   => $this->translate_error($error),
            ], 400);
        }

        return new JsonResponse([
            'success'      => true,
            'sent_count'   => (int) ($result['sent_count'] ?? 0),
            'failed_count' => (int) ($result['failed_count'] ?? 0),
        ]);
    }

    public function wallpaper_settings()
    {
        $this->assert_can_use();
        $user_id = (int) $this->user->data['user_id'];

        if ($this->request->is_set_post('hash'))
        {
            if (!check_link_hash($this->request->variable('hash', ''), 'messenger_wallpaper'))
            {
                return new JsonResponse(['success' => false, 'error' => 'FORM_INVALID'], 403);
            }

            $wallpaper = $this->request->variable('wallpaper', 'default', true);
            if (!$this->user_settings->set_chat_wallpaper($user_id, $wallpaper))
            {
                return new JsonResponse([
                    'success' => false,
                    'error'   => $this->translate_error('MESSENGER_WALLPAPER_SAVE_FAILED'),
                ], 400);
            }
        }

        $settings = $this->user_settings->get_chat_wallpaper($user_id);
        $presets = [];

        foreach ($this->user_settings->get_wallpaper_presets() as $id => $lang_key)
        {
            $presets[] = [
                'id'    => $id,
                'label' => $this->language->lang($lang_key),
            ];
        }

        $custom_url = '';
        if ($settings['wallpaper'] === 'custom')
        {
            $path = $this->user_settings->get_custom_wallpaper_path($user_id);
            if ($path !== '')
            {
                $custom_url = $this->helper->route('negentiendertien_messenger_wallpaper') . '?t=' . (int) @filemtime($path);
            }
        }

        return new JsonResponse([
            'success'     => true,
            'wallpaper'   => $settings['wallpaper'],
            'custom_url'  => $custom_url,
            'presets'     => $presets,
        ]);
    }

    public function wallpaper_upload()
    {
        $this->assert_can_use();

        if (!check_link_hash($this->request->variable('hash', ''), 'messenger_wallpaper'))
        {
            return new JsonResponse(['success' => false, 'error' => 'FORM_INVALID'], 403);
        }

        $image_data = trim($this->request->raw_variable('image_data', '', \phpbb\request\request_interface::POST));
        if ($image_data === '')
        {
            return new JsonResponse([
                'success' => false,
                'error'   => $this->translate_error('MESSENGER_WALLPAPER_UPLOAD_FAILED'),
            ], 400);
        }

        if (strpos($image_data, 'base64,') !== false)
        {
            $image_data = substr($image_data, (int) strrpos($image_data, 'base64,') + 7);
        }

        $binary = base64_decode(preg_replace('#\s+#', '', $image_data), true);
        if ($binary === false || $binary === '')
        {
            return new JsonResponse([
                'success' => false,
                'error'   => $this->translate_error('MESSENGER_WALLPAPER_UPLOAD_FAILED'),
            ], 400);
        }

        $user_id = (int) $this->user->data['user_id'];
        $result = $this->user_settings->save_custom_wallpaper($user_id, $binary);
        if (empty($result['success']))
        {
            $error = $result['error'] ?? 'MESSENGER_WALLPAPER_UPLOAD_FAILED';

            return new JsonResponse([
                'success' => false,
                'error'   => $this->translate_error($error),
            ], 400);
        }

        $path = $this->user_settings->get_custom_wallpaper_path($user_id);

        return new JsonResponse([
            'success'    => true,
            'wallpaper'  => 'custom',
            'custom_url' => $this->helper->route('negentiendertien_messenger_wallpaper') . '?t=' . (int) @filemtime($path),
        ]);
    }

    public function upload_image()
    {
        $this->assert_can_send();
        $this->language->add_lang('posting');
        $this->language->add_lang('common', 'negentiendertien/messenger');

        if (!check_link_hash($this->request->variable('hash', ''), 'messenger_upload'))
        {
            return new JsonResponse([
                'success' => false,
                'error'   => $this->translate_error('FORM_INVALID'),
            ], 403);
        }

        $result = $this->attachment->upload_image('fileupload');
        if (!$result['success'])
        {
            $error = $result['error'] ?? 'MESSENGER_UPLOAD_FAILED';
            $message = (strpos($error, 'MESSENGER_') === 0)
                ? $this->translate_error($error)
                : $error;

            return new JsonResponse([
                'success' => false,
                'error'   => $message,
            ], 400);
        }

        return new JsonResponse($result);
    }

    public function mark_read($partner_id)
    {
        $this->assert_can_use();

        $user_id = (int) $this->user->data['user_id'];
        $marked  = $this->message->mark_chat_read($user_id, (int) $partner_id);

        $notifications = $this->notification_manager->load_notifications('notification.method.board', [
            'count_unread' => true,
        ]);

        return new JsonResponse([
            'success'             => true,
            'marked'              => $marked,
            'notifications_count' => (int) $notifications['unread_count'],
            'unread_pm_count'     => $this->message->count_unread_pms($user_id),
        ]);
    }

    public function poll()
    {
        $this->assert_can_use();

        $user_id    = (int) $this->user->data['user_id'];
        $partner_id = max(0, $this->request->variable('partner_id', 0));
        $group_id   = max(0, $this->request->variable('group_id', 0));
        $since      = max(0, $this->request->variable('since', 0));

        $payload = [
            'roster' => $this->build_roster_payload($user_id, $partner_id, $group_id),
            'messages' => [],
        ];

        if ($group_id > 0)
        {
            if ($since > 0)
            {
                $payload['messages'] = $this->group->get_new_messages($user_id, $group_id, $since);
                if (!empty($payload['messages']))
                {
                    $this->group->mark_read($user_id, $group_id);
                }
            }

            $payload['typing_users'] = $this->build_group_typing_payload($user_id, $group_id);

            return $this->json_response($payload, true);
        }

        if ($partner_id > 0)
        {
            if ($since > 0)
            {
                $payload['messages'] = $this->conversation->get_new_messages($user_id, $partner_id, $since);
                if (!empty($payload['messages']))
                {
                    $this->message->mark_chat_read($user_id, $partner_id);
                }
            }

            $payload['read_statuses'] = $this->conversation->get_own_read_statuses($user_id, $partner_id);
            $payload['partner_typing'] = $this->typing->is_direct_typing($partner_id, $user_id);

            $sync_min = max(0, $this->request->variable('sync_min', 0));
            $sync_max = max(0, $this->request->variable('sync_max', 0));
            if ($sync_min > 0 && $sync_max >= $sync_min)
            {
                $payload['sync_messages'] = true;
                $payload['visible_msg_ids'] = $this->conversation->get_visible_message_ids_in_range(
                    $user_id,
                    $partner_id,
                    $sync_min,
                    $sync_max
                );
            }

            $since_edit = max(0, $this->request->variable('since_edit', 0));
            if ($since_edit > 0)
            {
                $payload['updated_messages'] = $this->conversation->get_updated_messages(
                    $user_id,
                    $partner_id,
                    $since_edit,
                    $sync_min,
                    $sync_max
                );
            }
        }

        return $this->json_response($payload, true);
    }

    public function typing()
    {
        $this->assert_can_use();

        $user_id    = (int) $this->user->data['user_id'];
        $partner_id = max(0, $this->request->variable('partner_id', 0));
        $group_id   = max(0, $this->request->variable('group_id', 0));

        if ($group_id > 0)
        {
            if (!$this->group->user_is_member($user_id, $group_id))
            {
                return new JsonResponse(['success' => false], 403);
            }

            $this->typing->set_group_typing($user_id, $group_id);
        }
        elseif ($partner_id > 0)
        {
            $partner = $this->conversation->get_partner_profile($partner_id);
            if (!$partner || !empty($partner['is_deleted']))
            {
                return new JsonResponse(['success' => false], 403);
            }

            $this->typing->set_direct_typing($user_id, $partner_id);
        }
        else
        {
            return new JsonResponse(['success' => false], 400);
        }

        return new JsonResponse(['success' => true]);
    }

    public function pin_chat($partner_id)
    {
        $this->assert_can_use();

        $pinned = $this->pin->toggle_chat_pin(
            (int) $this->user->data['user_id'],
            (int) $partner_id
        );

        return new JsonResponse(['success' => true, 'pinned' => $pinned]);
    }

    public function pin_message($msg_id)
    {
        $this->assert_can_use();

        $pinned = $this->pin->toggle_message_pin(
            (int) $this->user->data['user_id'],
            (int) $msg_id
        );

        return new JsonResponse(['success' => true, 'pinned' => $pinned]);
    }

    public function delete_message($msg_id)
    {
        $this->assert_can_use();

        $delete_for_both = $this->request->variable('for_both', 0) === 1;
        $this->assert_can_delete($delete_for_both);

        $deleted = $this->message->delete_message(
            (int) $this->user->data['user_id'],
            (int) $msg_id,
            $delete_for_both
        );

        return new JsonResponse(['success' => $deleted]);
    }

    public function edit_message($msg_id)
    {
        $this->assert_can_use();

        $user_id = (int) $this->user->data['user_id'];
        $msg_id  = (int) $msg_id;

        if (!$this->request->is_set_post('message'))
        {
            $source = $this->message->get_message_edit_source($user_id, $msg_id);
            if (empty($source['success']))
            {
                $error = $source['error'] ?? 'MESSENGER_EDIT_FAILED';

                return new JsonResponse([
                    'success' => false,
                    'error'   => $this->translate_error($error),
                ], $error === 'MESSENGER_EDIT_AFTER_READ' ? 403 : 400);
            }

            return new JsonResponse([
                'success' => true,
                'text'    => $source['text'],
            ]);
        }

        $this->assert_can_send();

        if (!check_link_hash($this->request->variable('hash', ''), 'messenger_send'))
        {
            return new JsonResponse(['success' => false, 'error' => 'FORM_INVALID'], 403);
        }

        $result = $this->message->edit_message(
            $user_id,
            $msg_id,
            $this->request->variable('message', '', true)
        );

        if (empty($result['success']))
        {
            $error = $result['error'] ?? 'MESSENGER_EDIT_FAILED';

            return new JsonResponse([
                'success' => false,
                'error'   => $this->translate_error($error),
            ], $error === 'MESSENGER_EDIT_AFTER_READ' ? 403 : 400);
        }

        $partner_id = (int) ($result['partner_id'] ?? 0);
        $message = $partner_id > 0
            ? $this->conversation->get_message($user_id, $partner_id, (int) $result['msg_id'])
            : null;

        return new JsonResponse([
            'success' => true,
            'msg_id'  => (int) $result['msg_id'],
            'message' => $message,
        ]);
    }

    public function delete_chat($partner_id)
    {
        $this->assert_can_use();

        $delete_for_both = $this->request->variable('for_both', 0) === 1;
        $this->assert_can_delete($delete_for_both);

        $this->message->delete_chat(
            (int) $this->user->data['user_id'],
            (int) $partner_id,
            $delete_for_both
        );

        return new JsonResponse(['success' => true]);
    }

    public function delete_chats()
    {
        $this->assert_can_use();

        $user_id = (int) $this->user->data['user_id'];
        $delete_for_both = $this->request->variable('for_both', 0) === 1;
        $this->assert_can_delete($delete_for_both);

        $partner_ids = $this->get_post_int_array('partner_ids');
        $group_ids = $this->get_post_int_array('group_ids');

        $deleted_partners = $this->message->delete_chats($user_id, $partner_ids, $delete_for_both);
        $deleted_groups = [];

        foreach ($group_ids as $group_id)
        {
            if ($this->group->leave_group($user_id, $group_id))
            {
                $deleted_groups[] = (int) $group_id;
            }
        }

        return new JsonResponse([
            'success'           => true,
            'deleted_partners'  => $deleted_partners,
            'deleted_groups'    => $deleted_groups,
        ]);
    }

    public function search()
    {
        $this->assert_can_use();

        $query      = $this->request->variable('q', '', true);
        $partner_id = $this->request->variable('partner_id', 0);

        $results = $this->search->search(
            (int) $this->user->data['user_id'],
            $query,
            (int) $partner_id
        );

        $payload = [];
        foreach ($results as $result)
        {
            $payload[] = array_merge($result, [
                'time_formatted' => $this->user->format_date((int) $result['message_time']),
                'chat_url'       => $this->routes->chat_url((int) $result['partner_id']) . '#msg-' . (int) $result['msg_id'],
            ]);
        }

        return new JsonResponse(['results' => $payload]);
    }

    public function members()
    {
        $this->assert_can_send();

        $query = $this->request->variable('q', '', true);
        $members = $this->member->search_members(
            (int) $this->user->data['user_id'],
            $query
        );

        $payload = [];
        foreach ($members as $member)
        {
            $payload[] = array_merge($member, [
                'chat_url' => $this->routes->chat_url((int) $member['user_id']),
            ]);
        }

        return new JsonResponse(['members' => $payload]);
    }

    public function group_members()
    {
        $this->assert_can_send();

        if (!$this->group->is_enabled())
        {
            return new JsonResponse(['members' => []]);
        }

        $query = $this->request->variable('q', '', true);
        $members = $this->group->search_staff_members(
            (int) $this->user->data['user_id'],
            $query
        );

        return new JsonResponse(['members' => $members]);
    }

    public function create_group()
    {
        $this->assert_can_send();
        $this->language->add_lang('common', 'negentiendertien/messenger');

        if (!check_link_hash($this->request->variable('hash', ''), 'messenger_group_create'))
        {
            return new JsonResponse([
                'success' => false,
                'error'   => $this->language->lang('FORM_INVALID'),
            ], 403);
        }

        $title = $this->request->variable('title', '', true);
        $member_ids = $this->get_post_int_array('member_ids');

        $result = $this->group->create_group(
            (int) $this->user->data['user_id'],
            $title,
            $member_ids
        );

        if (!$result['success'])
        {
            return new JsonResponse([
                'success' => false,
                'error'   => $this->translate_error($result['error']),
            ], 400);
        }

        $group_id = (int) $result['group_id'];

        return new JsonResponse([
            'success'  => true,
            'group_id' => $group_id,
            'chat_url' => $this->routes->group_url($group_id),
        ]);
    }

    public function group_chat($group_id)
    {
        $this->assert_can_use();

        $user_id  = (int) $this->user->data['user_id'];
        $group_id = (int) $group_id;
        $since    = max(0, $this->request->variable('since', 0));
        $before   = max(0, $this->request->variable('before', 0));
        $limit    = max(1, min(100, $this->request->variable('limit', 20)));

        if ($since > 0)
        {
            return new JsonResponse([
                'messages' => $this->group->get_new_messages($user_id, $group_id, $since),
            ]);
        }

        if ($before > 0)
        {
            return new JsonResponse($this->group->get_older_messages($user_id, $group_id, $before, $limit));
        }

        $chat = $this->group->get_group($user_id, $group_id, 1, 30);
        if (!$chat)
        {
            return new JsonResponse(['error' => 'MESSENGER_CHAT_NOT_FOUND'], 404);
        }

        return new JsonResponse($chat);
    }

    public function group_send($group_id)
    {
        $this->assert_can_send();

        if (!check_link_hash($this->request->variable('hash', ''), 'messenger_send'))
        {
            return new JsonResponse(['success' => false, 'error' => 'FORM_INVALID'], 403);
        }

        $message_text = $this->request->variable('message', '', true);
        $result = $this->group->send_message((int) $group_id, (int) $this->user->data['user_id'], $message_text);

        if (!$result['success'])
        {
            return new JsonResponse([
                'success' => false,
                'error'   => $result['error'],
            ], 400);
        }

        $sent = $this->group->get_message(
            (int) $this->user->data['user_id'],
            (int) $group_id,
            (int) $result['message_id']
        );

        return new JsonResponse([
            'success' => true,
            'msg_id'  => (int) $result['message_id'],
            'message' => $sent,
        ]);
    }

    public function group_mark_read($group_id)
    {
        $this->assert_can_use();

        $user_id = (int) $this->user->data['user_id'];
        $this->group->mark_read($user_id, (int) $group_id);

        return new JsonResponse(['success' => true]);
    }

    public function group_delete($group_id)
    {
        $this->assert_can_use();

        $deleted = $this->group->leave_group(
            (int) $this->user->data['user_id'],
            (int) $group_id
        );

        return new JsonResponse(['success' => $deleted]);
    }

    protected function build_roster_payload($user_id, $active_partner_id = 0, $active_group_id = 0)
    {
        $user_id           = (int) $user_id;
        $active_partner_id = (int) $active_partner_id;
        $active_group_id   = (int) $active_group_id;
        $rows = [];

        foreach ($this->conversation->get_roster($user_id) as $entry)
        {
            $row = $this->conversation->format_roster_entry($entry, $user_id);
            $row['chat_type'] = 'direct';
            $row['group_id'] = 0;
            $row['chat_url'] = $this->routes->chat_url((int) $row['partner_id']);

            if ($active_partner_id > 0 && (int) $row['partner_id'] === $active_partner_id)
            {
                $row['unread_count'] = 0;
            }

            $rows[] = $row;
        }

        foreach ($this->group->get_roster($user_id) as $entry)
        {
            $entry['chat_url'] = $this->routes->group_url((int) $entry['group_id']);

            if ($active_group_id > 0 && (int) $entry['group_id'] === $active_group_id)
            {
                $entry['unread_count'] = 0;
            }

            $rows[] = $entry;
        }

        usort($rows, function ($a, $b) {
            return ((int) ($b['last_time'] ?? 0)) <=> ((int) ($a['last_time'] ?? 0));
        });

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function build_group_typing_payload($user_id, $group_id)
    {
        $user_id  = (int) $user_id;
        $group_id = (int) $group_id;

        if (!$this->group->user_is_member($user_id, $group_id))
        {
            return [];
        }

        $members = $this->group->get_member_users($group_id);
        $member_ids = array_keys($members);
        $typing_ids = $this->typing->get_group_typing_user_ids($group_id, $user_id, $member_ids);

        $payload = [];
        foreach ($typing_ids as $typing_id)
        {
            if (!isset($members[$typing_id]))
            {
                continue;
            }

            $payload[] = [
                'user_id'  => (int) $typing_id,
                'username' => (string) $members[$typing_id],
            ];
        }

        return $payload;
    }

    protected function assert_can_use()
    {
        $this->language->add_lang('common', 'negentiendertien/messenger');

        if (!$this->message->can_use_messenger())
        {
            throw new \phpbb\exception\http_exception(403, 'MESSENGER_NO_ACCESS');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function json_response(array $payload, $no_cache = false)
    {
        $response = new JsonResponse($payload);

        if ($no_cache)
        {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }

    protected function assert_can_send()
    {
        $this->assert_can_use();

        if (!$this->message->can_send_message())
        {
            throw new \phpbb\exception\http_exception(403, 'MESSENGER_NO_SEND');
        }
    }

    protected function assert_can_delete($delete_for_both = false)
    {
        if ($delete_for_both)
        {
            if (!$this->message->can_delete_for_both())
            {
                throw new \phpbb\exception\http_exception(403, 'MESSENGER_NO_DELETE_BOTH');
            }

            return;
        }

        if (!$this->message->can_delete_for_me())
        {
            throw new \phpbb\exception\http_exception(403, 'MESSENGER_NO_DELETE');
        }
    }

    /**
     * @return int[]
     */
    protected function get_post_int_array($name)
    {
        if ($this->request->is_set($name, \phpbb\request\request_interface::POST))
        {
            $value = $this->request->raw_variable($name, [], \phpbb\request\request_interface::POST);
        }
        else
        {
            $value = $this->request->variable($name, [0]);
        }

        if (!is_array($value))
        {
            $value = [$value];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }

    protected function translate_error($error)
    {
        $error = (string) $error;
        if ($error === '')
        {
            return $this->language->lang('MESSENGER_GROUP_CREATE_FAILED');
        }

        $translated = $this->language->lang($error);
        return ($translated === $error) ? $error : $translated;
    }
}
