<?php

/**
 * Messenger — event listener
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\event;

use negentiendertien\messenger\service\route_helper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class main_listener implements EventSubscriberInterface
{
    /** @var \phpbb\config\config */
    protected $config;

    /** @var \phpbb\controller\helper */
    protected $helper;

    /** @var \phpbb\template\template */
    protected $template;

    /** @var \phpbb\user */
    protected $user;

    /** @var \phpbb\auth\auth */
    protected $auth;

    /** @var \phpbb\request\request */
    protected $request;

    /** @var \phpbb\language\language */
    protected $language;

    /** @var \negentiendertien\messenger\service\message_service */
    protected $message;

    /** @var \negentiendertien\messenger\service\route_helper */
    protected $routes;

    public function __construct(
        \phpbb\config\config $config,
        \phpbb\controller\helper $helper,
        \phpbb\template\template $template,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        \phpbb\request\request $request,
        \phpbb\language\language $language,
        \negentiendertien\messenger\service\message_service $message,
        \negentiendertien\messenger\service\route_helper $routes
    ) {
        $this->config   = $config;
        $this->helper   = $helper;
        $this->template = $template;
        $this->user     = $user;
        $this->auth     = $auth;
        $this->request  = $request;
        $this->language = $language;
        $this->message  = $message;
        $this->routes   = $routes;
    }

    public static function getSubscribedEvents()
    {
        return [
            'core.user_setup'                    => [['load_language', 0], ['redirect_ucp_messenger_standalone_early', 999]],
            'core.page_header'                   => 'redirect_legacy_pm',
            'core.page_header_after'             => 'override_pm_links',
            'core.ucp_display_module_before'     => 'ucp_display_module_before',
            'core.modify_module_row'             => 'modify_ucp_module_row',
            'core.permissions'                   => 'add_permissions',
            'core.memberlist_prepare_profile_data' => 'modify_profile_pm_link',
            'core.viewtopic_modify_post_row'     => 'modify_post_pm_link',
        ];
    }

    public function load_language($event)
    {
        $lang_set_ext = $event['lang_set_ext'];
        $lang_set_ext[] = [
            'ext_name' => 'negentiendertien/messenger',
            'lang_set' => 'common',
        ];
        $lang_set_ext[] = [
            'ext_name' => 'negentiendertien/messenger',
            'lang_set' => 'permissions_messenger',
        ];
        $event['lang_set_ext'] = $lang_set_ext;
    }

    public function override_pm_links()
    {
        if (!$this->is_active())
        {
            return;
        }

        $roster_url = $this->routes->wants_ucp_mode()
            ? $this->routes->roster_url()
            : $this->routes->standalone_roster_url();
        $unread_pm_count = $this->message->recalculate_user_unread_privmsg((int) $this->user->data['user_id']);

        $this->template->assign_vars([
            'U_PRIVATEMSGS'           => $roster_url,
            'U_MESSENGER'             => $roster_url,
            'S_MESSENGER_ACTIVE'      => true,
            'PRIVATE_MESSAGE_COUNT'   => $unread_pm_count,
            'S_USER_UNREAD_PRIVMSG'   => $unread_pm_count,
        ]);
    }

    public function modify_profile_pm_link($event)
    {
        if (!$this->can_route_pm_to_messenger())
        {
            return;
        }

        $data = $event['data'] ?? [];
        $user_id = (int) ($data['user_id'] ?? 0);
        if ($user_id <= 0 || $user_id === (int) $this->user->data['user_id'])
        {
            return;
        }

        $template_data = $event['template_data'];
        if (empty($template_data['U_PM']))
        {
            return;
        }

        $template_data['U_PM'] = $this->routes->chat_url($user_id);
        $event['template_data'] = $template_data;
    }

    public function modify_post_pm_link($event)
    {
        if (!$this->should_override_pm_links())
        {
            return;
        }

        $author_id = (int) ($event['poster_id'] ?? 0);
        if ($author_id <= 0)
        {
            $author_id = (int) ($event['row']['user_id'] ?? 0);
        }

        if ($author_id <= 0 || $author_id === ANONYMOUS || $author_id === (int) $this->user->data['user_id'])
        {
            return;
        }

        $post_row = $event['post_row'];
        if (empty($post_row['U_PM']))
        {
            return;
        }

        $post_id = (int) ($event['row']['post_id'] ?? 0);
        $post_row['U_PM'] = $this->routes->chat_url($author_id, $post_id);
        $event['post_row'] = $post_row;
    }

    public function redirect_ucp_messenger_standalone_early()
    {
        if (!$this->is_ucp_request())
        {
            return;
        }

        $this->redirect_messenger_from_ucp_if_needed(
            $this->request->variable('i', ''),
            $this->request->variable('mode', '')
        );
    }

    public function redirect_legacy_pm()
    {
        if (!$this->is_active())
        {
            return;
        }

        $module = $this->request->variable('i', '');
        if ($module !== 'pm' && $module !== 'ucp_pm')
        {
            return;
        }

        $mode = $this->request->variable('mode', '');

        if ($mode === 'compose')
        {
            if (!$this->message->can_send_message())
            {
                redirect($this->routes->roster_url());
            }

            $partner_id    = (int) $this->request->variable('u', 0);
            $quote_post_id = 0;

            if ($this->request->variable('action', '') === 'quotepost')
            {
                $quote_post_id = (int) $this->request->variable('p', 0);
                if ($partner_id <= 0)
                {
                    $partner_id = $this->message->get_post_author_id($quote_post_id);
                }
            }

            if ($partner_id > 0 && $partner_id !== (int) $this->user->data['user_id'])
            {
                redirect($this->routes->chat_url($partner_id, $quote_post_id));
            }

            redirect($this->routes->compose_url());
        }

        if ($module === 'pm')
        {
            if ($mode === 'view' || $mode === 'sentbox' || $mode === 'drafts' || $mode === 'options')
            {
                redirect($this->routes->roster_url());
            }

            return;
        }

        redirect($this->routes->roster_url());
    }

    public function ucp_display_module_before($event)
    {
        if (!$this->routes->wants_ucp_mode() && $this->routes->is_messenger_module_id($event['id']))
        {
            redirect($this->routes->standalone_url_for_request($this->request));
        }

        if (!$this->routes->wants_ucp_mode())
        {
            return;
        }

        if (!$this->is_active())
        {
            return;
        }

        if (!$this->routes->matches_ucp_module_request($event['id']))
        {
            return;
        }

        if (!$this->routes->has_ucp_module())
        {
            redirect($this->routes->standalone_roster_url());
        }
    }

    public function modify_ucp_module_row($event)
    {
        if ($this->routes->wants_ucp_mode())
        {
            return;
        }

        $row = $event['row'];
        $is_messenger_module = $row['module_basename'] === route_helper::UCP_MODULE_BASENAME
            || in_array($row['module_langname'], ['MESSENGER_TITLE', 'MESSENGER_COMPOSE'], true);

        if (!$is_messenger_module)
        {
            return;
        }

        $module_row = $event['module_row'];
        $module_row['display'] = false;
        $event['module_row'] = $module_row;
    }

    public function add_permissions($event)
    {
        $permissions = $event['permissions'];
        $permissions['a_messenger_manage'] = [
            'lang' => 'ACL_A_MESSENGER_MANAGE',
            'cat'  => 'misc',
        ];
        $permissions['u_messenger_use'] = [
            'lang' => 'ACL_U_MESSENGER_USE',
            'cat'  => 'pm',
        ];
        $permissions['u_messenger_delete_me'] = [
            'lang' => 'ACL_U_MESSENGER_DELETE_ME',
            'cat'  => 'pm',
        ];
        $permissions['u_messenger_delete_both'] = [
            'lang' => 'ACL_U_MESSENGER_DELETE_BOTH',
            'cat'  => 'pm',
        ];
        $event['permissions'] = $permissions;
    }

    protected function is_active()
    {
        return $this->message->can_use_messenger();
    }

    protected function is_ucp_request()
    {
        foreach (['SCRIPT_NAME', 'PHP_SELF'] as $key)
        {
            $script = (string) $this->request->server($key, '');
            if ($script === '')
            {
                continue;
            }

            $script = basename(str_replace('\\', '/', strtok($script, '?')));

            if ($script === 'ucp.php')
            {
                return true;
            }
        }

        return false;
    }

    protected function redirect_messenger_from_ucp_if_needed($id, $mode)
    {
        if ($this->routes->wants_ucp_mode())
        {
            return;
        }

        if (!$this->routes->is_messenger_module_id($id))
        {
            return;
        }

        redirect($this->routes->standalone_url_for_request($this->request));
    }

    protected function can_route_pm_to_messenger()
    {
        return $this->message->can_send_message();
    }

    protected function should_override_pm_links()
    {
        return $this->can_route_pm_to_messenger()
            && !empty($this->config['messenger_visible_pm_link']);
    }
}
