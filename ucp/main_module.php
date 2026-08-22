<?php



/**

 * Messenger — UCP module

 *

 * @package negentiendertien\messenger

 * @copyright (c) 2026 negentiendertien

 * @license GPL-2.0-only

 */



namespace negentiendertien\messenger\ucp;



class main_module

{

    public $u_action;

    public $tpl_name;

    public $page_title;



    public function main($id, $mode)

    {

        global $phpbb_container, $request;



        /** @var \negentiendertien\messenger\service\route_helper $routes */

        $routes = $phpbb_container->get('negentiendertien.messenger.service.route');

        if (!$routes->is_ucp_mode())
        {
            redirect($routes->standalone_url_for_request($request));
        }



        /** @var \negentiendertien\messenger\service\display_service $display */

        $display = $phpbb_container->get('negentiendertien.messenger.service.display');



        $this->tpl_name = '@negentiendertien_messenger/messenger_ucp';



        $partner_id = (int) $request->variable('partner_id', 0);

        $group_id = (int) $request->variable('group_id', 0);

        $compose = (int) $request->variable('compose', 0);



        switch ($mode)

        {

            case 'chat':

                if ($partner_id <= 0)

                {

                    redirect($routes->roster_url());

                }



                $this->page_title = $display->render_chat($partner_id, true);

            break;



            case 'group':

                if ($group_id <= 0)

                {

                    redirect($routes->roster_url());

                }



                $this->page_title = $display->render_group($group_id, true);

            break;



            case 'compose':

                $this->page_title = $display->render_compose(true);

            break;



            case 'roster':

            default:

                if ($partner_id > 0)

                {

                    $this->page_title = $display->render_chat($partner_id, true);

                }

                elseif ($group_id > 0)

                {

                    $this->page_title = $display->render_group($group_id, true);

                }

                elseif ($compose)

                {

                    $this->page_title = $display->render_compose(true);

                }

                else

                {

                    $this->page_title = $display->render_roster(true);

                }

            break;

        }

    }

}


