<?php

/**
 * Messenger — enable forum header/footer by default
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\migrations;

class enable_header_footer_default extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['messenger_show_header_footer'])
            && (int) $this->config['messenger_show_header_footer'] === 1;
    }

    public static function depends_on()
    {
        return ['\negentiendertien\messenger\migrations\add_group_chat'];
    }

    public function update_data()
    {
        return [
            ['config.update', ['messenger_show_header_footer', 1]],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.update', ['messenger_show_header_footer', 0]],
        ];
    }
}
