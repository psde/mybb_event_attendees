<?php
/***************************************************************************
 *
 *   Event Attendees for MyBB
 *   Copyright: © 2015 by Mathias Garbe
 *
 ***************************************************************************/

/***************************************************************************
 *
 *   This program is free software: you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation, either version 3 of the License, or
 *   (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 ***************************************************************************/
 
if(!defined("IN_MYBB"))
{
    die("This file cannot be accessed directly.");
}

require_once MYBB_ROOT."inc/functions_calendar.php";

$plugins->add_hook("calendar_event_end", "event_attendees_event_end");
$plugins->add_hook("misc_start", "event_attendees_misc_start");


function event_attendees_info()
{	
	return array(
		"name"          => "Event Attendees",
		"description"   => "",
		"website"       => "",
		"author"        => "Mathias Garbe",
		"authorsite"    => "",
		"version"       => "0.1",
		"codename"      => str_replace('.php', '', basename(__FILE__)),
		"compatibility" => "18*"
	);
}

function event_attendees_install()
{
	global $db;

	$col = $db->build_create_table_collation();
	$db->query("CREATE TABLE `".TABLE_PREFIX."event_attendees` (
				`id`			int(11)			NOT NULL AUTO_INCREMENT,
				`eid`			int(11)			NOT NULL,
				`uid`			int(11)			NOT NULL,
				`timestamp`		bigint(30)		NOT NULL,
	PRIMARY KEY (`id`) ) ENGINE=MyISAM {$col}");

	$settingsgroup = array(
		"title"         => "Event Attendees",
		"name"          => "event_attendees",
		"description"   => "",
		"disporder"     => "50",
		"isdefault"     => "0",
	);
	$gid = $db->insert_query("settinggroups", $settingsgroup);

	$setting_activate = array(
		"name"          => "event_attendees_active",
		"title"         => "Activate",
		"description"   => "",
		"optionscode"   => "yesno",
		"value"         => 'yes',
		"disporder"     => '1',
		"gid"           => (int)$gid,
	);
	$db->insert_query("settings", $setting_activate);

	$setting_past_events = array(
		"name"          => "event_attendees_attend_past",
		"title"         => "Users can attend past events",
		"description"   => "",
		"optionscode"   => "yesno",
		"value"         => 'no',
		"disporder"     => '2',
		"gid"           => (int)$gid,
	);
	$db->insert_query("settings", $setting_past_events);

	rebuild_settings();
}

function event_attendees_is_installed()
{
	global $db;
	return $db->table_exists("event_attendees");
}

function event_attendees_uninstall()
{
	global $db;
	
	$db->drop_table("event_attendees");

	$query = $db->simple_select("settinggroups", "gid", "name='event_attendees'");
	$g = $db->fetch_array($query);
	$db->delete_query("settinggroups", "gid='".$g['gid']."'");
	$db->delete_query("settings", "gid='".$g['gid']."'");
	rebuild_settings();
}

function event_attendees_activate()
{

}

function event_attendees_deactivate()
{

}

function event_attendees_is_activated()
{
	global $mybb;
	return ($mybb->settings['event_attendees_active'] == 1);
}

function event_attendees_check_event($eid)
{
	global $db, $mybb;
	$query = $db->query("
		SELECT u.*, e.*
		FROM ".TABLE_PREFIX."events e
		LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=e.uid)
		WHERE e.eid='".(int)$eid."'
	");
	$event = $db->fetch_array($query);

	if(!$event || ($event['private'] == 1 && $event['uid'] != $mybb->user['uid']))
	{
		error("Invalid event");
	}

	$query = $db->simple_select("calendars", "*", "cid='{$event['cid']}'");
	$calendar = $db->fetch_array($query);

	// Invalid calendar?
	if(!$calendar)
	{
		error("Invalid calendar");
	}

	// Do we have permission to view this calendar?
	$calendar_permissions = get_calendar_permissions($calendar['cid']);
	if($calendar_permissions['canviewcalendar'] != 1 || ($calendar_permissions['canmoderateevents'] != 1 && $event['visible'] == 0))
	{
		error("Invalid permissions");
	}
}

function event_attendees_get_attendees($eid)
{
	global $db;

	event_attendees_check_event($eid);

	$attendees = array();

	// Get all attendees
	$query = $db->query("
		SELECT u.*, e.*
		FROM ".TABLE_PREFIX."event_attendees e
		LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=e.uid)
		WHERE e.eid='".intval($eid)."'
		ORDER BY u.username ASC;
	");

	while($attendee = $db->fetch_array($query))
	{
		$attendees[] = $attendee;
	}
	return $attendees;
}

function event_attendees_add_attendance($eid)
{
	global $db, $mybb;
	$eid = (int)$eid;

	event_attendees_check_event($eid);

	// Check if user is already attending
	$query = $db->query("
		SELECT e.eid
		FROM ".TABLE_PREFIX."event_attendees e
		WHERE e.eid='{$mybb->input['eid']}' AND e.uid='{$mybb->user['uid']}';
	");
	$attending = $db->fetch_array($query);

	if(isset($attending))
	{
		return;
	}

	// Add this user to attendees
	$data = array(
		"eid" => $eid,
		"uid" => $mybb->user['uid'],
		"timestamp" => gmmktime()
	);
	$db->insert_query("event_attendees", $data);
}

function event_attendees_remove_attendance($eid)
{
	global $db, $mybb;
	$eid = (int)$eid;

	event_attendees_check_event($eid);

	// Delete user from attendees, no need to check if he is attending
	$db->delete_query("event_attendees", "eid='".$eid."' AND uid='".$mybb->user['uid']."'");
}

function event_attendees_event_end()
{
	global $db, $mybb, $edit_event;

	if(!event_attendees_is_activated())
	{
		return;
	}

	$attendees = event_attendees_get_attendees($mybb->input['eid']);

	// Check if user is attending
	$attending = False;
	foreach($attendees as $attendee)
	{
		if($attendee['uid'] == $mybb->user['uid'])
		{
			$attending = True;
		}
	}

	// Build html code
	$text = "<div style=\"text-align: left; vertical-align: bottom;\" class=\"postbit_buttons\">\n";
	$text .= "<div><b>Teilnehmer (".count($attendees).")</b>: ".implode(', ', array_map(function($val){return $val['username'];}, $attendees))."</div>";

	$url = "misc.php?action=edit_attendance&amp;eid=".$mybb->input['eid']."&amp;my_post_key=".generate_post_check()."&amp;edit=";
	if(!$attending)
	{
		$text .= "<a href=\"".$url."add\" class=\"postbit_reputation_add\"><span>Teilnehmen</span></a>";
	}
	else
	{
		$text .= "<a href=\"".$url."delete\" class=\"postbit_delete_pm\"><span>Nicht Teilnehmen</span></a>";
	}

	$text .= "</div>";

	// Prepend html code to template global
	$edit_event = $text.$edit_event;
}

function event_attendees_misc_start()
{
	global $mybb;

	if(event_attendees_is_activated() && $mybb->input['action'] == "edit_attendance" && isset($mybb->input['edit']))
	{
		print("test");
		$eid = (int)$mybb->input['eid'];
		$edit = $mybb->input['edit'];
		print($edit);

		if(!verify_post_check($mybb->get_input('my_post_key')))
		{
			error("Post key invalid");
		}

		if($edit == "add")
		{
			event_attendees_add_attendance($eid);
		}
		else if($edit == "delete")
		{
			event_attendees_remove_attendance($eid);
		}
		else
		{
			error("Did not understand edit action");
		}

		redirect(get_event_link($eid), "Teilnahme geändert.");
	}
}

?>