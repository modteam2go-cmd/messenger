<?php

/**
 * Messenger — group chat message notification
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\notification\type;

class group_message extends \phpbb\notification\type\base
{
    /** @var \phpbb\user_loader */
    protected $user_loader;

    /** @var \phpbb\config\config */
    protected $config;

    /**
     * @var array{lang: string, group: string}
     */
    static public $notification_option = [
        'lang'  => 'NOTIFICATION_TYPE_MESSENGER_GROUP',
        'group' => 'NOTIFICATION_GROUP_MISCELLANEOUS',
    ];

    public function set_config(\phpbb\config\config $config)
    {
        $this->config = $config;
    }

    public function set_user_loader(\phpbb\user_loader $user_loader)
    {
        $this->user_loader = $user_loader;
    }

    /**
     * {@inheritdoc}
     */
    public function get_type()
    {
        return 'negentiendertien.messenger.notification.type.group_message';
    }

    /**
     * {@inheritdoc}
     */
    public function is_available()
    {
        return !empty($this->config['messenger_enabled'])
            && !empty($this->config['messenger_group_chat_enabled'])
            && $this->auth->acl_get('u_messenger_use');
    }

    /**
     * {@inheritdoc}
     */
    static public function get_item_id($data)
    {
        return (int) $data['message_id'];
    }

    /**
     * {@inheritdoc}
     */
    static public function get_item_parent_id($data)
    {
        return (int) $data['group_id'];
    }

    /**
     * {@inheritdoc}
     */
    public function find_users_for_notification($data, $options = [])
    {
        $options = array_merge([
            'ignore_users' => [],
        ], $options);

        $recipients = array_map('intval', (array) ($data['recipients'] ?? []));
        $recipients = array_diff($recipients, [(int) $data['author_id']]);
        $recipients = array_values(array_filter($recipients));

        if (empty($recipients))
        {
            return [];
        }

        $this->user_loader->load_users($recipients);

        return $this->check_user_notification_options($recipients, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function get_avatar()
    {
        return $this->user_loader->get_avatar($this->get_data('author_id'), false, true);
    }

    /**
     * {@inheritdoc}
     */
    public function get_title()
    {
        $username = $this->user_loader->get_username($this->get_data('author_id'), 'no_profile');

        return $this->language->lang('NOTIFICATION_MESSENGER_GROUP', $username);
    }

    /**
     * {@inheritdoc}
     */
    public function get_reference()
    {
        return $this->language->lang(
            'NOTIFICATION_REFERENCE',
            (string) $this->get_data('group_title')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function get_url()
    {
        $routes = $this->get_route_helper();
        if (!$routes)
        {
            return '';
        }

        return $routes->group_url((int) $this->item_parent_id);
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
     * {@inheritdoc}
     */
    public function users_to_query()
    {
        return [(int) $this->get_data('author_id')];
    }

    /**
     * No e-mails for group chat messages, board (bell) notification only.
     *
     * {@inheritdoc}
     */
    public function get_email_template()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function get_email_template_variables()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function create_insert_array($data, $pre_create_data = [])
    {
        $this->set_data('author_id', (int) $data['author_id']);
        $this->set_data('group_title', (string) $data['group_title']);

        parent::create_insert_array($data, $pre_create_data);
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
}
