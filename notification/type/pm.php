<?php

/**
 * Messenger — PM notification URLs
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\notification\type;

class pm extends \phpbb\notification\type\pm
{
    /**
     * {@inheritdoc}
     */
    public function get_url()
    {
        $messenger_url = $this->build_messenger_url();
        if ($messenger_url !== '')
        {
            return $messenger_url;
        }

        return parent::get_url();
    }

    /**
     * @return string
     */
    protected function build_messenger_url()
    {
        if (!$this->can_use_messenger())
        {
            return '';
        }

        $helper = $this->get_controller_helper();
        if (!$helper)
        {
            return '';
        }

        $partner_id = (int) $this->get_data('from_user_id');
        if ($partner_id <= 0)
        {
            return '';
        }

        return $this->get_route_helper()->chat_url($partner_id);
    }

    /**
     * @return \negentiendertien\messenger\service\route_helper|null
     */
    protected function get_route_helper()
    {
        global $phpbb_container;

        if (!$phpbb_container || !$phpbb_container->has('negentiendertien.messenger.service.route'))
        {
            return null;
        }

        return $phpbb_container->get('negentiendertien.messenger.service.route');
    }

    /**
     * @return \phpbb\controller\helper|null
     */
    protected function get_controller_helper()
    {
        global $phpbb_container;

        if (!$phpbb_container || !$phpbb_container->has('controller.helper'))
        {
            return null;
        }

        return $phpbb_container->get('controller.helper');
    }

    /**
     * @return bool
     */
    protected function can_use_messenger()
    {
        return !empty($this->config['messenger_enabled'])
            && !empty($this->user->data['is_registered'])
            && $this->auth->acl_get('u_readpm')
            && $this->auth->acl_get('u_messenger_use');
    }
}
