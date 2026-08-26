<?php

/**
 * @package negentiendertien\messenger
 * @copyright (c) 2026 negentiendertien
 * @license GPL-2.0-only
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'ACL_A_MESSENGER_MANAGE'      => 'Can manage messenger (ACP)',
	'ACL_U_MESSENGER_USE'         => 'Can use the messenger',
	'ACL_U_MESSENGER_DELETE_ME'   => 'Can delete messenger messages for self (own and received)',
	'ACL_U_MESSENGER_DELETE_BOTH' => 'Can delete any messenger message for both users',
]);
