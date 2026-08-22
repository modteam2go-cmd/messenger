<?php

/**
 * Messenger — ACP module info
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\acp;

class main_info
{
    public function module()
    {
        return [
            'filename' => '\negentiendertien\messenger\acp\main_module',
            'title'    => 'ACP_MESSENGER_TITLE',
            'modes'    => [
                'settings' => [
                    'title' => 'ACP_MESSENGER_SETTINGS',
                    'auth'  => 'ext_negentiendertien/messenger && acl_a_messenger_manage',
                    'cat'   => ['ACP_MESSENGER_TITLE'],
                ],
            ],
        ];
    }
}
