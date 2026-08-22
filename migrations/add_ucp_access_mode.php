<?php

/**
 * Messenger — optional UCP access mode
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\migrations;

class add_ucp_access_mode extends \phpbb\db\migration\container_aware_migration
{
    public function effectively_installed()
    {
        return isset($this->config['messenger_ucp_mode']);
    }

    public static function depends_on()
    {
        return ['\negentiendertien\messenger\migrations\add_user_wallpaper'];
    }

    public function update_data()
    {
        return [
            ['custom', [[$this, 'install_ucp_access_mode']]],
        ];
    }

    public function install_ucp_access_mode()
    {
        if (!isset($this->config['messenger_ucp_mode']))
        {
            $this->config->set('messenger_ucp_mode', 0);
        }

        $installer = $this->get_installer();

        if ($installer->is_installed())
        {
            $installer->purge_legacy_extra_modes();
        }

        if (!empty($this->config['messenger_ucp_mode']))
        {
            $this->ensure_ucp_module($installer);
        }
    }

    public function revert_data()
    {
        return [
            ['custom', [[$this, 'remove_ucp_access_mode']]],
            ['config.remove', ['messenger_ucp_mode']],
        ];
    }

    public function remove_ucp_access_mode()
    {
        $this->get_installer()->purge();
    }

    /**
     * @return \negentiendertien\messenger\service\ucp_module_installer
     */
    protected function get_installer()
    {
        return new \negentiendertien\messenger\service\ucp_module_installer(
            $this->container->get('dbal.conn'),
            $this->container->get('migrator.tool.module'),
            $this->container->get('module.manager'),
            $this->table_prefix
        );
    }

    /**
     * @param \negentiendertien\messenger\service\ucp_module_installer $installer
     */
    protected function ensure_ucp_module($installer)
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
    }
}
