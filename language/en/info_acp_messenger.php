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
	'ACP_MESSENGER_TITLE'                => 'Messenger',
	'ACP_MESSENGER_SETTINGS'             => 'Settings',
	'ACP_MESSENGER_GENERAL'              => 'General',
	'ACP_MESSENGER_BEHAVIOUR'            => 'Behaviour',
	'ACP_MESSENGER_ENABLED'              => 'Enable messenger',
	'ACP_MESSENGER_ENABLED_EXPLAIN'      => 'Enables the messenger at board level. Only users and groups with the “Can use the messenger” permission see the messenger; everyone else keeps the standard PM system.',
	'ACP_MESSENGER_POLL_INTERVAL'        => 'Poll interval (seconds)',
	'ACP_MESSENGER_POLL_INTERVAL_EXPLAIN'=> 'How often the browser checks for new messages when a chat is open.',
	'ACP_MESSENGER_ALLOW_EDIT'           => 'Allow editing after read',
	'ACP_MESSENGER_ALLOW_DELETE_BOTH'    => 'Allow delete for both users',
	'ACP_MESSENGER_VISIBLE_PM_LINK'      => 'Visible PM link in topics',
	'ACP_MESSENGER_UCP_MODE'             => 'Open messenger in User Control Panel',
	'ACP_MESSENGER_UCP_MODE_EXPLAIN'     => 'When enabled, private messages open inside the UCP with the standard PM tabs visible. When disabled, the standalone messenger page is used.',
	'ACP_MESSENGER_SHOW_HEADER_FOOTER'   => 'Show forum header and footer',
	'ACP_MESSENGER_SHOW_HEADER_FOOTER_EXPLAIN' => 'Applies only when the standalone messenger page is used. Shows the normal forum header and footer on messenger pages. Turn off for a compact full-screen layout.',
	'ACP_MESSENGER_SAVED'                => 'Messenger settings saved.',
	'ACP_MESSENGER_MAINTENANCE'          => 'Maintenance',
	'ACP_MESSENGER_CLEANUP_NOTIFICATIONS'=> 'Clean up PM bell notifications',
	'ACP_MESSENGER_CLEANUP_NOTIFICATIONS_EXPLAIN' => 'Marks all open PM bell notifications as read. Intended as a one-time cleanup after the messenger update. Users will receive normal alerts again for new PMs afterwards.',
	'ACP_MESSENGER_NOTIFICATIONS_CLEANED'=> 'Cleanup complete. %1$d bell notification(s) and %2$d PM message(s) marked as read.',
	'ACP_MESSENGER_GROUP_CHAT'           => 'Group chats',
	'ACP_MESSENGER_GROUP_CHAT_ENABLED'   => 'Enable group chats',
	'ACP_MESSENGER_GROUP_CHAT_ENABLED_EXPLAIN' => 'Allows users to start group conversations with members of selected phpBB groups.',
	'ACP_MESSENGER_GROUP_CHAT_GROUPS'    => 'Allowed groups',
	'ACP_MESSENGER_GROUP_CHAT_GROUPS_EXPLAIN' => 'Only members of these groups can be added to a group chat (e.g. Administrators and Global moderators). Hold Ctrl to select multiple groups.',
]);
