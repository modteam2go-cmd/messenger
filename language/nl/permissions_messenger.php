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
	'ACL_A_MESSENGER_MANAGE' => 'Mag messenger beheren (ACP)',
	'ACL_U_MESSENGER_USE'    => 'Mag de messenger gebruiken',
]);
