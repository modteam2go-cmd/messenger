<?php

/**
 * Messenger — typing indicator (cache-backed heartbeat)
 *
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

namespace negentiendertien\messenger\service;

class typing_service
{
    /** @var \phpbb\cache\driver\driver_interface */
    protected $cache;

    /** Cache entry lifetime in seconds. */
    const TTL = 12;

    public function __construct(\phpbb\cache\driver\driver_interface $cache)
    {
        $this->cache = $cache;
    }

    public function set_direct_typing($user_id, $partner_id)
    {
        $user_id    = (int) $user_id;
        $partner_id = (int) $partner_id;

        if ($user_id <= 0 || $partner_id <= 0 || $user_id === $partner_id)
        {
            return;
        }

        $this->cache->put($this->direct_cache_key($user_id, $partner_id), time(), self::TTL);
    }

    public function set_group_typing($user_id, $group_id)
    {
        $user_id  = (int) $user_id;
        $group_id = (int) $group_id;

        if ($user_id <= 0 || $group_id <= 0)
        {
            return;
        }

        $this->cache->put($this->group_cache_key($user_id, $group_id), time(), self::TTL);
    }

    public function is_direct_typing($typist_id, $viewer_id)
    {
        $typist_id = (int) $typist_id;
        $viewer_id = (int) $viewer_id;

        if ($typist_id <= 0 || $viewer_id <= 0 || $typist_id === $viewer_id)
        {
            return false;
        }

        return $this->cache->get($this->direct_cache_key($typist_id, $viewer_id)) !== false;
    }

    /**
     * @param int[] $member_ids
     * @return int[]
     */
    public function get_group_typing_user_ids($group_id, $exclude_user_id, array $member_ids)
    {
        $group_id        = (int) $group_id;
        $exclude_user_id = (int) $exclude_user_id;
        $typing_ids      = [];

        foreach ($member_ids as $member_id)
        {
            $member_id = (int) $member_id;
            if ($member_id <= 0 || $member_id === $exclude_user_id)
            {
                continue;
            }

            if ($this->cache->get($this->group_cache_key($member_id, $group_id)) !== false)
            {
                $typing_ids[] = $member_id;
            }
        }

        return $typing_ids;
    }

    protected function direct_cache_key($typist_id, $viewer_id)
    {
        return '_msgr_typ_p' . (int) $viewer_id . '_u' . (int) $typist_id;
    }

    protected function group_cache_key($user_id, $group_id)
    {
        return '_msgr_typ_g' . (int) $group_id . '_u' . (int) $user_id;
    }
}
