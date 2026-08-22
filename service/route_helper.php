<?php

/**
 * Messenger — mode-aware page URLs
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\service;

class route_helper
{
    const UCP_MODULE_BASENAME = '\\negentiendertien\\messenger\\ucp\\main_module';

    /** @var \phpbb\config\config */
    protected $config;

    /** @var \phpbb\controller\helper */
    protected $helper;

    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var string */
    protected $root_path;

    /** @var string */
    protected $php_ext;

    /** @var string */
    protected $table_prefix;

    /** @var array|null */
    protected $ucp_module_ids;

    public function __construct(
        \phpbb\config\config $config,
        \phpbb\controller\helper $helper,
        \phpbb\db\driver\driver_interface $db,
        $root_path,
        $php_ext,
        $table_prefix
    ) {
        $this->config       = $config;
        $this->helper       = $helper;
        $this->db           = $db;
        $this->root_path    = $root_path;
        $this->php_ext      = $php_ext;
        $this->table_prefix = $table_prefix;
    }

    public function is_ucp_mode()
    {
        return !empty($this->config['messenger_ucp_mode']) && $this->has_ucp_module();
    }

    public function has_ucp_module()
    {
        $ids = $this->get_ucp_module_ids();

        return !empty($ids['roster']);
    }

    public function matches_ucp_module_request($id)
    {
        return $this->is_messenger_module_id($id);
    }

    public function is_messenger_module_id($id)
    {
        if ($id === '' || $id === null)
        {
            return false;
        }

        if (is_numeric($id))
        {
            if ($this->lookup_messenger_module_id((int) $id))
            {
                return true;
            }

            foreach ($this->get_ucp_module_ids() as $module_id)
            {
                if ((int) $module_id === (int) $id)
                {
                    return true;
                }
            }

            return false;
        }

        if (strpos((string) $id, '-') !== false)
        {
            if (str_replace('-', '\\', (string) $id) === self::UCP_MODULE_BASENAME)
            {
                return true;
            }

            return strpos((string) $id, 'negentiendertien-messenger-ucp') !== false;
        }

        return false;
    }

    public function wants_ucp_mode()
    {
        return !empty($this->config['messenger_ucp_mode']);
    }

    public function is_messenger_ucp_request($id, $mode = '', \phpbb\request\request_interface $request = null)
    {
        if ($this->is_messenger_module_id($id))
        {
            return true;
        }

        if ($request === null)
        {
            return false;
        }

        $mode = (string) $mode;
        if (!in_array($mode, ['roster', 'chat', 'compose', 'group'], true))
        {
            return false;
        }

        return $request->is_set('partner_id')
            || $request->is_set('group_id')
            || (int) $request->variable('compose', 0) > 0;
    }

    public function standalone_url_for_request(\phpbb\request\request_interface $request)
    {
        $partner_id = (int) $request->variable('partner_id', 0);
        $group_id = (int) $request->variable('group_id', 0);
        $compose = (int) $request->variable('compose', 0);
        $quote_post = (int) $request->variable('quote_post', 0);

        if ($group_id > 0)
        {
            return $this->group_url($group_id);
        }

        if ($compose)
        {
            return $this->compose_url();
        }

        if ($partner_id > 0)
        {
            return $this->chat_url($partner_id, $quote_post);
        }

        return $this->standalone_roster_url();
    }

    public function standalone_roster_url(array $params = [])
    {
        return $this->helper->route('negentiendertien_messenger_roster', $params);
    }

    public function roster_url()
    {
        if ($this->is_ucp_mode())
        {
            return $this->ucp_url();
        }

        return $this->standalone_roster_url();
    }

    public function chat_url($partner_id, $quote_post_id = 0)
    {
        $partner_id = (int) $partner_id;
        if ($partner_id <= 0)
        {
            return $this->roster_url();
        }

        $params = ['partner_id' => $partner_id];
        if ((int) $quote_post_id > 0)
        {
            $params['quote_post'] = (int) $quote_post_id;
        }

        if ($this->is_ucp_mode())
        {
            return $this->ucp_url($params);
        }

        // Query-string on /messenger keeps the same path depth as the roster,
        // so relative board menu links (e.g. football/bet) still resolve correctly.
        return $this->standalone_roster_url($params);
    }

    public function compose_url()
    {
        if ($this->is_ucp_mode())
        {
            return $this->ucp_url(['compose' => 1]);
        }

        return $this->standalone_roster_url(['compose' => 1]);
    }

    public function group_url($group_id)
    {
        $group_id = (int) $group_id;
        if ($group_id <= 0)
        {
            return $this->roster_url();
        }

        if ($this->is_ucp_mode())
        {
            return $this->ucp_url(['group_id' => $group_id]);
        }

        return $this->standalone_roster_url(['group_id' => $group_id]);
    }

    public function chat_template_url()
    {
        if ($this->is_ucp_mode())
        {
            return $this->ucp_url(['partner_id' => 0]);
        }

        return $this->standalone_roster_url(['partner_id' => 0]);
    }

    public function group_template_url()
    {
        if ($this->is_ucp_mode())
        {
            return $this->ucp_url(['group_id' => 0]);
        }

        return $this->standalone_roster_url(['group_id' => 0]);
    }

    public function pinned_url()
    {
        return $this->helper->route('negentiendertien_messenger_pinned');
    }

    public function profile_url($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0)
        {
            return '';
        }

        return append_sid($this->root_path . 'memberlist.' . $this->php_ext, 'mode=viewprofile&u=' . $user_id);
    }

    protected function ucp_url(array $params = [])
    {
        $module_id = $this->ucp_module_id();
        if ($module_id === '')
        {
            return $this->helper->route('negentiendertien_messenger_roster');
        }

        $query = [
            'i'    => $module_id,
            'mode' => 'roster',
        ];

        foreach ($params as $key => $value)
        {
            $query[$key] = $value;
        }

        return append_sid($this->root_path . 'ucp.' . $this->php_ext, $this->build_query($query));
    }

    protected function ucp_module_id()
    {
        $ids = $this->get_ucp_module_ids();

        if (!empty($ids['roster']))
        {
            return (string) $ids['roster'];
        }

        return '';
    }

    protected function get_ucp_module_ids()
    {
        if ($this->ucp_module_ids !== null)
        {
            return $this->ucp_module_ids;
        }

        $this->ucp_module_ids = [];

        $sql = 'SELECT module_mode, module_id
                FROM ' . $this->table_prefix . 'modules
                WHERE module_class = \'ucp\'
                  AND (
                    module_basename = \'' . $this->db->sql_escape(self::UCP_MODULE_BASENAME) . '\'
                    OR ' . $this->db->sql_in_set('module_langname', ['MESSENGER_TITLE', 'MESSENGER_COMPOSE']) . '
                  )';
        $result = $this->db->sql_query($sql);

        while ($row = $this->db->sql_fetchrow($result))
        {
            $this->ucp_module_ids[(string) $row['module_mode']] = (int) $row['module_id'];
        }

        $this->db->sql_freeresult($result);

        return $this->ucp_module_ids;
    }

    protected function lookup_messenger_module_id($module_id)
    {
        $module_id = (int) $module_id;
        if ($module_id <= 0)
        {
            return false;
        }

        $basenames = [
            self::UCP_MODULE_BASENAME,
            ltrim(self::UCP_MODULE_BASENAME, '\\'),
        ];

        $sql = 'SELECT module_id
                FROM ' . $this->table_prefix . 'modules
                WHERE module_class = \'ucp\'
                  AND module_id = ' . $module_id . '
                  AND (
                    ' . $this->db->sql_in_set('module_basename', $basenames) . '
                    OR ' . $this->db->sql_in_set('module_langname', ['MESSENGER_TITLE', 'MESSENGER_COMPOSE']) . '
                  )';
        $result = $this->db->sql_query($sql);
        $found = (int) $this->db->sql_fetchfield('module_id') > 0;
        $this->db->sql_freeresult($result);

        return $found;
    }

    protected function build_query(array $params)
    {
        $parts = [];
        foreach ($params as $key => $value)
        {
            if ($value === '' || $value === null)
            {
                continue;
            }

            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $parts);
    }
}
