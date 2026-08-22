<?php



/**

 * Messenger — UCP module info

 *

 * @package negentiendertien\messenger

 * @copyright (c) 2026 negentiendertien

 * @license GPL-2.0-only

 */



namespace negentiendertien\messenger\ucp;



class main_info

{

    public function module()

    {

        return [

            'filename' => '\negentiendertien\messenger\ucp\main_module',

            'title'    => 'MESSENGER_TITLE',

            'modes'    => [

                'roster' => [

                    'title' => 'MESSENGER_TITLE',

                    'auth'  => 'ext_negentiendertien/messenger && acl_u_messenger_use',

                    'cat'   => ['UCP_PM'],

                ],

            ],

        ];

    }

}


