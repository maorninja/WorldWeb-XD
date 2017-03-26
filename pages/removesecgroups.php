<?php
if (!defined('BLARG')) die();

CheckPermission('admin.editusers');

if ($http->post('submit')) {
	if ($http->post('userid') && $http->post('groupid')) {
		Query("DELETE FROM {secondarygroups} (userid,groupid) VALUES ({0},{1})",
			$http->post('userid'), $http->post('groupid'));
		Report("[b]".$loguser['name']."[/] successfully removed a secondary group (ID: ".$http->post('groupid').") from user ID #".$http->post('userid')."", false);
		Alert(__("Secondary group successfully removed."), __("Notice"));
	} else if (!$http->post('userid') && $http->post('groupid')) {
		Report("[b]".$loguser['name']."[/] tried to remove a secondary group (ID: ".$http->post('groupid').") from someone.", false);
		Alert(__("Please enter a User ID and try again."), __("Notice"));
	} else if ($http->post('userid') && !$http->post('groupid')) {
		Report("[b]".$loguser['name']."[/] tried to remove a secondary group from user ID #".$http->post('userid').".", false);
		Alert(__("Please enter a Group ID and try again."), __("Notice"));
	} else if (!$http->post('userid') && !$http->post('groupid')) {
		Report("[b]".$loguser['name']."[/] tried to remove a secondary group.", false);
		Alert(__("Please enter a Group ID and a User ID and try again."), __("Notice"));
	}
} else {
	Alert(__("Please enter a Group ID and a User ID."), __("Notice"));
}
?>
<table class="outline"><tr class="header1"><th colspan="2" class="center">Remove secondary groups</th></tr>
<form action="" method="POST">
<tr class="cell2"><td>User ID</td><td><input type="text" name="userid"></td></tr>
<tr class="cell1"><td>Group ID</td><td><input type="text" name="groupid"></td></tr>
<tr><td colspan="2" class="cell2"><input type="submit" name="submit" value="Remove"></td></tr>
</form>
</table>