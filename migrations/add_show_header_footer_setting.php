<?php

/**
 * Messenger — ACP setting for forum header/footer visibility
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\migrations;

class add_show_header_footer_setting extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['messenger_show_header_footer']);
    }

    public static function depends_on()
    {
        return ['\negentiendertien\messenger\migrations\cleanup_stale_pm_notifications'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['messenger_show_header_footer', 1]],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['messenger_show_header_footer']],
        ];
    }
}
