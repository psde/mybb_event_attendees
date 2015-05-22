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
 
define("IN_MYBB", 1);
define('THIS_SCRIPT', 'event_attendees.php');

require_once "global.php";

if($mybb->input['action'] == "edit_attendance" && isset($mybb->input['edit']))
{
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

error("Not for public use");

?>