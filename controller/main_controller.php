<?php

/**
 * Messenger — HTML controllers
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\controller;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class main_controller
{
    /** @var \phpbb\controller\helper */
    protected $helper;

    /** @var \phpbb\language\language */
    protected $language;

    /** @var \phpbb\request\request */
    protected $request;

    /** @var \phpbb\user */
    protected $user;

    /** @var \negentiendertien\messenger\service\display_service */
    protected $display;

    /** @var \negentiendertien\messenger\service\route_helper */
    protected $routes;

    /** @var \negentiendertien\messenger\service\message_service */
    protected $message;

    /** @var \negentiendertien\messenger\service\user_settings_service */
    protected $user_settings;

    public function __construct(
        \phpbb\controller\helper $helper,
        \phpbb\language\language $language,
        \phpbb\request\request $request,
        \phpbb\user $user,
        \negentiendertien\messenger\service\display_service $display,
        \negentiendertien\messenger\service\route_helper $routes,
        \negentiendertien\messenger\service\message_service $message,
        \negentiendertien\messenger\service\user_settings_service $user_settings
    ) {
        $this->helper        = $helper;
        $this->language      = $language;
        $this->request       = $request;
        $this->user          = $user;
        $this->display       = $display;
        $this->routes        = $routes;
        $this->message       = $message;
        $this->user_settings = $user_settings;
    }

    public function roster()
    {
        $partner_id = (int) $this->request->variable('partner_id', 0);
        $group_id   = (int) $this->request->variable('group_id', 0);
        $compose    = (int) $this->request->variable('compose', 0);
        $quote_post = (int) $this->request->variable('quote_post', 0);

        if ($this->routes->is_ucp_mode())
        {
            if ($partner_id > 0)
            {
                redirect($this->routes->chat_url($partner_id, $quote_post));
            }

            if ($group_id > 0)
            {
                redirect($this->routes->group_url($group_id));
            }

            if ($compose)
            {
                redirect($this->routes->compose_url());
            }

            redirect($this->routes->roster_url());
        }

        if ($partner_id > 0)
        {
            $title = $this->display->render_chat($partner_id);

            return $this->helper->render(
                '@negentiendertien_messenger/messenger_app.html',
                $title
            );
        }

        if ($group_id > 0)
        {
            $title = $this->display->render_group($group_id);

            return $this->helper->render(
                '@negentiendertien_messenger/messenger_app.html',
                $title
            );
        }

        if ($compose)
        {
            $title = $this->display->render_compose();

            return $this->helper->render(
                '@negentiendertien_messenger/messenger_app.html',
                $title
            );
        }

        $title = $this->display->render_roster();

        return $this->helper->render(
            '@negentiendertien_messenger/messenger_app.html',
            $title
        );
    }

    /**
     * Legacy path: /messenger/chat/{id} → /messenger?partner_id={id}
     */
    public function chat($partner_id)
    {
        redirect($this->routes->chat_url(
            (int) $partner_id,
            (int) $this->request->variable('quote_post', 0)
        ));
    }

    /**
     * Legacy path: /messenger/group/{id} → /messenger?group_id={id}
     */
    public function group($group_id)
    {
        redirect($this->routes->group_url((int) $group_id));
    }

    /**
     * Legacy path: /messenger/compose → /messenger?compose=1
     */
    public function compose()
    {
        redirect($this->routes->compose_url());
    }

    public function pinned()
    {
        if ($this->routes->is_ucp_mode())
        {
            redirect($this->routes->roster_url());
        }

        $title = $this->display->render_pinned();

        return $this->helper->render(
            '@negentiendertien_messenger/messenger_roster.html',
            $title
        );
    }

    public function wallpaper()
    {
        if (!$this->message->can_use_messenger())
        {
            throw new \phpbb\exception\http_exception(403, 'MESSENGER_NO_ACCESS');
        }

        $user_id = (int) $this->user->data['user_id'];
        $path = $this->user_settings->get_custom_wallpaper_path($user_id);
        if ($path === '')
        {
            throw new \phpbb\exception\http_exception(404, 'MESSENGER_WALLPAPER_NOT_FOUND');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/jpeg');
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }
}
