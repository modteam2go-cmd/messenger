<?php

/**
 * Messenger — modern private messaging
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger;

class ext extends \phpbb\extension\base
{
    const GROUP_NOTIFICATION_TYPE = 'negentiendertien.messenger.notification.type.group_message';

    public function enable_step($old_state)
    {
        switch ($old_state)
        {
            case false:
                $this->container->get('notification_manager')->enable_notifications(self::GROUP_NOTIFICATION_TYPE);

                return 'notification';

            case 'notification':
                $this->sync_ucp_module();

                return 'ucp_module';
        }

        return parent::enable_step($old_state);
    }

    public function disable_step($old_state)
    {
        switch ($old_state)
        {
            case false:
                $this->container->get('notification_manager')->disable_notifications(self::GROUP_NOTIFICATION_TYPE);

                return 'notification';

            case 'notification':
                $this->purge_ucp_module();

                return 'ucp_module';
        }

        return parent::disable_step($old_state);
    }

    public function purge_step($old_state)
    {
        switch ($old_state)
        {
            case false:
                $this->container->get('notification_manager')->purge_notifications(self::GROUP_NOTIFICATION_TYPE);

                return 'notification';

            case 'notification':
                $this->purge_ucp_module();

                return 'ucp_module';
        }

        return parent::purge_step($old_state);
    }

    protected function sync_ucp_module()
    {
        $config = $this->container->get('config');
        $installer = $this->create_ucp_module_installer();

        if (!empty($config['messenger_ucp_mode']))
        {
            if (!$installer->is_installed())
            {
                $installer->install();
            }
            else
            {
                $installer->purge_legacy_extra_modes();
            }

            $installer->set_enabled(true);

            return;
        }

        if ($installer->is_installed())
        {
            $installer->purge_legacy_extra_modes();
        }
    }

    protected function purge_ucp_module()
    {
        $this->create_ucp_module_installer()->purge();
    }

    /**
     * Extension services are not loaded during enable/disable steps.
     *
     * @return \negentiendertien\messenger\service\ucp_module_installer
     */
    protected function create_ucp_module_installer()
    {
        return new \negentiendertien\messenger\service\ucp_module_installer(
            $this->container->get('dbal.conn'),
            $this->container->get('migrator.tool.module'),
            $this->container->get('module.manager'),
            $this->container->getParameter('core.table_prefix')
        );
    }
}
