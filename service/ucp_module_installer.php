<?php



/**

 * Messenger — UCP module install/remove helper

 *

 * @package negentiendertien\messenger

 * @copyright (c) 2026 negentiendertien

 * @license GPL-2.0-only

 */



namespace negentiendertien\messenger\service;



class ucp_module_installer

{

    const UCP_MODULE_BASENAME = '\\negentiendertien\\messenger\\ucp\\main_module';



    const UCP_ORPHAN_LANGNAMES = [

        'MESSENGER_TITLE',

        'MESSENGER_COMPOSE',

    ];



    /** @var \phpbb\db\driver\driver_interface */

    protected $db;



    /** @var \phpbb\db\migration\tool\module */

    protected $module_tool;



    /** @var \phpbb\module\module_manager */

    protected $module_manager;



    /** @var string */

    protected $table_prefix;



    public function __construct(

        \phpbb\db\driver\driver_interface $db,

        \phpbb\db\migration\tool\module $module_tool,

        \phpbb\module\module_manager $module_manager,

        $table_prefix

    ) {

        $this->db              = $db;

        $this->module_tool     = $module_tool;

        $this->module_manager  = $module_manager;

        $this->table_prefix    = $table_prefix;

    }



    public function is_installed()

    {

        $sql = 'SELECT module_id

                FROM ' . $this->table_prefix . "modules

                WHERE module_class = 'ucp'

                  AND module_basename = '" . $this->db->sql_escape(self::UCP_MODULE_BASENAME) . "'

                  AND module_mode = 'roster'";

        $result = $this->db->sql_query($sql);

        $module_id = (int) $this->db->sql_fetchfield('module_id');

        $this->db->sql_freeresult($result);



        return $module_id > 0;

    }



    public function install()

    {

        $this->purge();



        $this->module_tool->add('ucp', 'UCP_PM', [

            'module_basename' => '\negentiendertien\messenger\ucp\main_module',

            'modes'           => ['roster'],

        ]);

    }



    public function purge_legacy_extra_modes()

    {

        $sql = 'SELECT module_id

                FROM ' . $this->table_prefix . "modules

                WHERE module_class = 'ucp'

                  AND module_basename = '" . $this->db->sql_escape(self::UCP_MODULE_BASENAME) . "'

                  AND module_mode <> 'roster'";

        $result = $this->db->sql_query($sql);



        while ($module_id = (int) $this->db->sql_fetchfield('module_id'))

        {

            try

            {

                $this->module_manager->delete_module($module_id, 'ucp');

            }

            catch (\phpbb\module\exception\module_exception $e)

            {

                // Already removed or tree already broken.

            }

        }



        $this->db->sql_freeresult($result);

        $this->module_manager->remove_cache_file('ucp');

    }



    public function purge()

    {

        $this->delete_modules_by_basename();



        foreach (self::UCP_ORPHAN_LANGNAMES as $langname)

        {

            try

            {

                $this->module_tool->remove('ucp', 'UCP_PM', $langname);

            }

            catch (\phpbb\db\migration\exception $e)

            {

                // Module already removed.

            }

        }



        $this->module_manager->remove_cache_file('ucp');

    }



    protected function delete_modules_by_basename()

    {

        $sql = 'SELECT module_id

                FROM ' . $this->table_prefix . "modules

                WHERE module_class = 'ucp'

                  AND module_basename = '" . $this->db->sql_escape(self::UCP_MODULE_BASENAME) . "'";

        $result = $this->db->sql_query($sql);



        while ($module_id = (int) $this->db->sql_fetchfield('module_id'))

        {

            try

            {

                $this->module_manager->delete_module($module_id, 'ucp');

            }

            catch (\phpbb\module\exception\module_exception $e)

            {

                // Already removed or tree already broken.

            }

        }



        $this->db->sql_freeresult($result);

    }

    public function set_enabled($enabled)

    {

        if (!$this->is_installed())

        {

            return;

        }



        $enabled = (int) (bool) $enabled;



        $sql = 'UPDATE ' . $this->table_prefix . 'modules

                SET module_enabled = ' . $enabled . "

                WHERE module_class = 'ucp'

                  AND (

                    module_basename = '" . $this->db->sql_escape(self::UCP_MODULE_BASENAME) . "'

                    OR " . $this->db->sql_in_set('module_langname', self::UCP_ORPHAN_LANGNAMES) . '

                  )';

        $this->db->sql_query($sql);

        $this->module_manager->remove_cache_file('ucp');

    }

}


