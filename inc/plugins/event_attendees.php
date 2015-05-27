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

$plugins->add_hook("admin_config_plugins_begin", "event_attendees_rebuild_settings");
$plugins->add_hook("calendar_event_end", "event_attendees_event_end");
$plugins->add_hook("misc_start", "event_attendees_misc_start");
$plugins->add_hook("calendar_start", "event_attendees_generate_attendance_info");

function event_attendees_info()
{	
	return array(
		"name"          => "Event Attendees",
		"description"   => "",
		"website"       => "",
		"author"        => "Mathias Garbe",
		"authorsite"    => "",
		"version"       => "0.3",
		"codename"      => str_replace('.php', '', basename(__FILE__)),
		"compatibility" => "18*"
	);
}

function event_attendees_add_settings_if_needed($setting)
{
	global $db;
	$query = $db->simple_select("settings", "gid", "name='".$setting["name"]."'");
	$numrows = $db->num_rows($query);

	if(!$numrows)
	{
		$db->insert_query("settings", $setting);
	}
}

function event_attendees_rebuild_settings()
{
	global $db;

	$query = $db->simple_select("settinggroups", "gid", "name='event_attendees'");
	print_r($query);

	if(!$db->num_rows($query))
	{
		$settingsgroup = array(
			"title"         => "Event Attendees",
			"name"          => "event_attendees",
			"description"   => "",
			"disporder"     => "50",
			"isdefault"     => "0",
		);
		$gid = $db->insert_query("settinggroups", $settingsgroup);
	}
	else
	{
		$gid = $db->fetch_array($query)['gid'];
	}

	$setting_activate = array(
		"name"          => "event_attendees_active",
		"title"         => "Activate",
		"description"   => "",
		"optionscode"   => "yesno",
		"value"         => '1',
		"disporder"     => '1',
		"gid"           => (int)$gid,
	);
	event_attendees_add_settings_if_needed($setting_activate);

	$setting_past_events = array(
		"name"          => "event_attendees_attend_past",
		"title"         => "Users can attend past events",
		"description"   => "",
		"optionscode"   => "yesno",
		"value"         => '0',
		"disporder"     => '2',
		"gid"           => (int)$gid,
	);
	event_attendees_add_settings_if_needed($setting_past_events);

	$setting_past_events_offset = array(
		"name"          => "event_attendees_attend_past_offset",
		"title"         => "Offset of past events",
		"description"   => "",
		"optionscode"   => "numeric",
		"value"         => '-86401',
		"disporder"     => '3',
		"gid"           => (int)$gid,
	);
	event_attendees_add_settings_if_needed($setting_past_events_offset);

	rebuild_settings();
}

function event_attendees_install()
{
	global $db;

	$col = $db->build_create_table_collation();
	$db->query("CREATE TABLE IF NOT EXISTS `".TABLE_PREFIX."event_attendees` (
				`id`			int(11)			NOT NULL AUTO_INCREMENT,
				`eid`			int(11)			NOT NULL,
				`uid`			int(11)			NOT NULL,
				`timestamp`		bigint(30)		NOT NULL,
	PRIMARY KEY (`id`) ) ENGINE=MyISAM {$col}");

	event_attendees_rebuild_settings();
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
	require "../inc/adminfunctions_templates.php";
	find_replace_templatesets("calendar_eventbit", '#(\{\$event\[\'name\'\]\}\</a\>)#i', "$1{\$event_attendance[\$event['eid']]}");
	find_replace_templatesets("calendar_weekview_day_event", '#(\{\$event\[\'fullname\'\]\}\</a\>)#i', "$1{\$event_attendance[\$event['eid']]}");
	find_replace_templatesets("calendar_dayview_event", '#(\{\$edit_event\})#i', "{\$event_attendance[\$event['eid']]}$1");
	event_attendees_rebuild_settings();
}

function event_attendees_deactivate()
{
	require "../inc/adminfunctions_templates.php";
	find_replace_templatesets("calendar_eventbit", '#\{\$event_attendance\[\$event\[\'eid\'\]\]\}#i', "");
	find_replace_templatesets("calendar_weekview_day_event", '#\{\$event_attendance\[\$event\[\'eid\'\]\]\}#i', "");
	find_replace_templatesets("calendar_dayview_event", '#\{\$event_attendance\[\$event\[\'eid\'\]\]\}#i', "");
}

function event_attendees_is_activated()
{
	global $mybb;
	return ($mybb->settings['event_attendees_active'] == 1);
}

function event_attendees_can_attend_event($eid)
{
	global $mybb, $db;

	if($mybb->settings['event_attendees_attend_past'] == 0)
	{
		$query = $db->query("
			SELECT e.*
			FROM ".TABLE_PREFIX."events e
			WHERE e.eid='".(int)$eid."'
		");
		$event = $db->fetch_array($query);

		$event_time = $event['starttime'] + $event['endtime'] - (int)$mybb->settings['event_attendees_attend_past_offset'];
		if($event_time < gmmktime())
		{
			return false;
		}
	}

	return true;
}

function event_attendees_validate_calendar($cid)
{
	global $db;
	$query = $db->simple_select("calendars", "*", "cid='{$cid}'");
	$calendar = $db->fetch_array($query);

	// Invalid calendar?
	if(!$calendar || !$calendar['cid'])
	{
		error("Invalid calendar");
	}

	// Do we have permission to view this calendar?
	$calendar_permissions = get_calendar_permissions($calendar['cid']);
	if($calendar_permissions['canviewcalendar'] != 1 || ($calendar_permissions['canmoderateevents'] != 1 && $event['visible'] == 0))
	{
		error("Invalid permissions");
	}

	return $calendar;
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

	event_attendees_validate_calendar($event['cid']);
}

function event_attendees_get_attendees($eid)
{
	global $db;

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

function event_attendees_build_attendance_html($eid)
{
	global $mybb;
	$attendees = event_attendees_get_attendees($eid);

	// Check if user is attending
	$attending = False;
	foreach($attendees as $attendee)
	{
		if($attendee['uid'] == $mybb->user['uid'])
		{
			$attending = True;
		}
	}

	$attendees_text = implode(', ', array_map(function($val){return $val['username'];}, $attendees));

	if(count($attendees) == 0)
	{
		$attendees_text = "Keiner";
	}

	// Build html code
	$text = "<div style=\"text-align: left; vertical-align: bottom;\" class=\"postbit_buttons\">\n";
	$text .= "<div><b>Teilnehmer (".count($attendees).")</b>: ".$attendees_text."</div>";

	if(event_attendees_can_attend_event($eid))
	{
		$url = "misc.php?action=edit_attendance&amp;eid=".$eid."&amp;my_post_key=".generate_post_check()."&amp;edit=";
		if(!$attending)
		{
			$text .= "<a href=\"".$url."add\" class=\"postbit_reputation_add\"><span>Teilnehmen</span></a>";
		}
		else
		{
			$text .= "<a href=\"".$url."delete\" class=\"postbit_delete_pm\"><span>Nicht Teilnehmen</span></a>";
		}
	}
	else
	{
		$text .= "Die Teilnahme an dieser Veranstaltung kann nicht mehr verändert werden.";
	}

	$text .= "</div>";
	return $text;
}

function event_attendees_event_end()
{
	global $db, $mybb, $edit_event;

	if(!event_attendees_is_activated())
	{
		return;
	}

	$eid = (int)$mybb->input['eid'];

	// Check event
	event_attendees_check_event($eid);

	// Prepend html code to template global
	$edit_event = event_attendees_build_attendance_html($eid).$edit_event;
}

function event_attendees_misc_start()
{
	global $mybb;

	if(event_attendees_is_activated() && $mybb->input['action'] == "edit_attendance" && isset($mybb->input['edit']))
	{
		$eid = (int)$mybb->input['eid'];
		$edit = $mybb->input['edit'];

		if(!event_attendees_can_attend_event($eid))
		{
			error("Event can not be attended");
		}

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

		redirect($_SERVER['HTTP_REFERER'], "Teilnahme geändert.");
	}
}

function event_attendees_generate_attendance_info()
{
	global $db, $mybb, $event_attendance;

	if(!event_attendees_is_activated())
	{
		return;
	}

	if($mybb->input['calendar'])
	{
		$query = $db->simple_select("calendars", "*", "cid='{$mybb->input['calendar']}'");
		$calendar = $db->fetch_array($query);
	}
	// Showing the default calendar
	else
	{
		$query = $db->simple_select("calendars", "*", "", array('order_by' => 'disporder', 'limit' => 1));
		$calendar = $db->fetch_array($query);
	}

	event_attendees_validate_calendar($calendar['cid']);

	$mybb->input['year'] = $mybb->get_input('year', MyBB::INPUT_INT);
	if($mybb->input['year'] && $mybb->input['year'] <= my_date("Y")+5)
	{
		$year = $mybb->input['year'];
	}
	else
	{
		$year = my_date("Y");
	}

	$mybb->input['month'] = $mybb->get_input('month', MyBB::INPUT_INT);
	if($mybb->input['month'] >= 1 && $mybb->input['month'] <= 12)
	{
		$month = $mybb->input['month'];
	}
	else
	{
		$month = my_date("n");
	}

	$mybb->input['day'] = $mybb->get_input('day', MyBB::INPUT_INT);
	if($mybb->input['day'] && $mybb->input['day'] <= gmdate("t", gmmktime(0, 0, 0, $month, 1, $year)))
	{
		$day = $mybb->input['day'];
	}
	else
	{
		$day = my_date("j");
	}

	$full_functions = false;

	if(!$mybb->input['action']) // monthview
	{
		$next_month = get_next_month($month, $year);
		$prev_month = get_prev_month($month, $year);

		$weekdays = fetch_weekday_structure($calendar['startofweek']);

		$month_start_weekday = gmdate("w", gmmktime(0, 0, 0, $month, $calendar['startofweek']+1, $year));

		$prev_month_days = gmdate("t", gmmktime(0, 0, 0, $prev_month['month'], 1, $prev_month['year']));

		// This is if we have days in the previous month to show
		if($month_start_weekday != $weekdays[0] || $calendar['startofweek'] != 0)
		{
			$prev_days = $day = gmdate("t", gmmktime(0, 0, 0, $prev_month['month'], 1, $prev_month['year']));
			$day -= array_search(($month_start_weekday), $weekdays);
			$day += $calendar['startofweek']+1;
			if($day > $prev_month_days+1)
			{
				// Go one week back
				$day -= 7;
			}
			$calendar_month = $prev_month['month'];
			$calendar_year = $prev_month['year'];
		}
		else
		{
			$day = $calendar['startofweek']+1;
			$calendar_month = $month;
			$calendar_year = $year;
		}

		// So now we fetch events for this month (nb, cache events for past month, current month and next month for mini calendars too)
		$start_timestamp = gmmktime(0, 0, 0, $calendar_month, $day, $calendar_year);
		$num_days = gmdate("t", gmmktime(0, 0, 0, $month, 1, $year));

		$month_end_weekday = gmdate("w", gmmktime(0, 0, 0, $month, $num_days, $year));
		$next_days = 6-$month_end_weekday+$calendar['startofweek'];

		// More than a week? Go one week back
		if($next_days >= 7)
		{
			$next_days -= 7;
		}
		if($next_days > 0)
		{
			$end_timestamp = gmmktime(23, 59, 59, $next_month['month'], $next_days, $next_month['year']);
		}
		else
		{
			// We don't need days from the next month
			$end_timestamp = gmmktime(23, 59, 59, $month, $num_days, $year);
		}
	}
	else if($mybb->input['action'] == "weekview")
	{
		// No incoming week, show THIS week
		if(empty($mybb->input['week']))
		{
			list($day, $month, $year) = explode("-", my_date("j-n-Y"));
			$php_weekday = gmdate("w", gmmktime(0, 0, 0, $month, $day, $year));
			$my_weekday = array_search($php_weekday, $weekdays);
			// So now we have the start day of this week to show
			$start_day = $day-$my_weekday;
			$mybb->input['week'] = gmmktime(0, 0, 0, $month, $start_day, $year);
		}
		else
		{
			$mybb->input['week'] = (int)str_replace("n", "-", $mybb->get_input('week'));
			// No negative years please ;)
			if($mybb->input['week'] < -62167219200)
			{
				$mybb->input['week'] = -62167219200;
			}
		}

		// This is where we've come from and where we're headed
		$week_from = explode("-", gmdate("j-n-Y", $mybb->input['week']));
		$week_to_stamp = gmmktime(0, 0, 0, $week_from[1], $week_from[0]+6, $week_from[2]);
		$week_to = explode("-", gmdate("j-n-Y-t", $week_to_stamp));

		// Establish if we have a month ending in this week
		if($week_from[1] != $week_to[1])
		{
			$different_months = true;
			$week_months = array(array($week_from[1], $week_from[2]), array($week_to[1], $week_to[2]));
			$bday_months = array($week_from[1], $week_to[1]);
		}
		else
		{
			$week_months = array(array($week_from[1], $week_from[2]));
			$bday_months = array($week_from[1]);
		}

		// Load Birthdays for this month
		if($calendar['showbirthdays'] == 1)
		{
			$birthdays = get_birthdays($bday_months);
		}

		// We load events for the entire month date range - for our mini calendars too
		$start_timestamp = gmmktime(0, 0, 0, $week_from[1], 1, $week_from[2]);
		$end_timestamp = gmmktime(0, 0, 0, $week_to[1], $week_to[3], $week_to[2]);
	}
	else if($mybb->input['action'] == "dayview")
	{
		$start_timestamp = gmmktime(0, 0, 0, $month, $day, $year);
		$end_timestamp = gmmktime(23, 59, 59, $month, $day, $year);
		$full_functions = true;
	}
	else
	{
		return;
	}

	$events = get_events($calendar, $start_timestamp , $end_timestamp, 1, 1);

	foreach ($events as $day) {
		foreach($day as $event)
		{
			$eid = $event['eid'];
			$attendees = event_attendees_get_attendees($eid);

			if($full_functions)
			{
				$event_attendance[$eid] = event_attendees_build_attendance_html($eid);
			}
			else
			{
				$event_attendance[$eid] = " (".count($attendees).")";
			}
		}
	}
}

?>