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
	'ACP_MESSENGER_SETTINGS'             => 'Instellingen',
	'ACP_MESSENGER_GENERAL'              => 'Algemeen',
	'ACP_MESSENGER_BEHAVIOUR'            => 'Gedrag',
	'ACP_MESSENGER_ENABLED'              => 'Messenger inschakelen',
	'ACP_MESSENGER_ENABLED_EXPLAIN'      => 'Schakelt de messenger in op forumniveau. Alleen gebruikers en groepen met de permissie “Mag de messenger gebruiken” zien de messenger; anderen houden het normale PB-systeem.',
	'ACP_MESSENGER_POLL_INTERVAL'        => 'Poll-interval (seconden)',
	'ACP_MESSENGER_POLL_INTERVAL_EXPLAIN'=> 'Hoe vaak de browser op nieuwe berichten controleert als een chat open is.',
	'ACP_MESSENGER_ALLOW_EDIT'           => 'Bewerken na lezen toestaan',
	'ACP_MESSENGER_ALLOW_DELETE_BOTH'    => 'Verwijderen voor beide gebruikers toestaan',
	'ACP_MESSENGER_VISIBLE_PM_LINK'      => 'Zichtbare PB-link in topics',
	'ACP_MESSENGER_UCP_MODE'             => 'Messenger openen in Gebruikerspaneel',
	'ACP_MESSENGER_UCP_MODE_EXPLAIN'     => 'Indien ingeschakeld openen privéberichten in het UCP met de standaard PB-tabs zichtbaar. Indien uitgeschakeld wordt de standalone messenger-pagina gebruikt.',
	'ACP_MESSENGER_SHOW_HEADER_FOOTER'   => 'Forumheader en footer tonen',
	'ACP_MESSENGER_SHOW_HEADER_FOOTER_EXPLAIN' => 'Geldt alleen bij de standalone messenger-pagina. Toont de normale forumheader en footer. Zet uit voor een compacte volledig-scherm weergave.',
	'ACP_MESSENGER_SAVED'                => 'Messenger-instellingen opgeslagen.',
	'ACP_MESSENGER_MAINTENANCE'          => 'Onderhoud',
	'ACP_MESSENGER_CLEANUP_NOTIFICATIONS'=> 'PB-belnotificaties opschonen',
	'ACP_MESSENGER_CLEANUP_NOTIFICATIONS_EXPLAIN' => 'Markeert alle open PB-notificaties op de bel én alle ongelezen PB-vlaggen in de database als gelezen. Bedoeld als eenmalige opschoning na de messenger-update. Gebruikers krijgen daarna weer normaal meldingen bij nieuwe PB\'s.',
	'ACP_MESSENGER_NOTIFICATIONS_CLEANED'=> 'Opschoning voltooid. %1$d bel-notificatie(s) en %2$d PB-bericht(en) gemarkeerd als gelezen.',
	'ACP_MESSENGER_GROUP_CHAT'           => 'Groepsgesprekken',
	'ACP_MESSENGER_GROUP_CHAT_ENABLED'   => 'Groepsgesprekken inschakelen',
	'ACP_MESSENGER_GROUP_CHAT_ENABLED_EXPLAIN' => 'Gebruikers kunnen groepsgesprekken starten met leden van geselecteerde phpBB-groepen.',
	'ACP_MESSENGER_GROUP_CHAT_GROUPS'    => 'Toegestane groepen',
	'ACP_MESSENGER_GROUP_CHAT_GROUPS_EXPLAIN' => 'Alleen leden van deze groepen kunnen aan een groepsgesprek worden toegevoegd (bijv. Administrators en Global moderators). Houd Ctrl ingedrukt om meerdere groepen te selecteren.',
]);
