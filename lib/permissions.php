<?php
if (!defined('BLARG')) die();

require __DIR__."/permstrings.php";

$usergroups = [];
$grouplist = [];
$res = Query("SELECT * FROM {usergroups} ORDER BY id");
while ($g = Fetch($res)) {
	$usergroups[$g['id']] = $g;
	$grouplist[$g['id']] = $g['title'];
}

function LoadPermset($res) {
	$perms = [];
	$permord = [];

	while ($perm = Fetch($res)) {
		if ($perm['value'] == 0) continue;

		$k = $perm['perm'];
		if ($perm['arg']) $k .= '_'.$perm['arg'];

		if ((isset($permord[$k]) && ($perm['ord'] > $permord[$k])) || (!isset($perms[$k]) || $perms[$k] != -1))
			$perms[$k] = $perm['value'];

		$permord[$k] = $perm['ord'];
	}

	return $perms;
}

function LoadGroups() {
	global $usergroups, $loguserid, $loguser, $loguserGroup, $loguserPermset;
	global $guestPerms, $guestGroup, $guestPermset;

	$guestGroup = $usergroups[Settings::get('defaultGroup')];
	$res = Query("SELECT *, 1 ord FROM {permissions} WHERE applyto=0 AND id={0} AND perm IN ({1c})", $guestGroup['id'], $guestPerms);
	$guestPermset = LoadPermset($res);

	if (!$loguserid) {
		$loguserGroup = $guestGroup;
		$loguserPermset = $guestPermset;

		$loguser['banned'] = false;
		$loguser['root'] = false;
		return;
	}

	$secgroups = [];
	$loguserGroup = $usergroups[$loguser['primarygroup']];

	$res = Query("SELECT groupid FROM {secondarygroups} WHERE userid={0}", $loguserid);
	while ($sg = Fetch($res)) $secgroups[] = $sg['groupid'];

	$res = Query("	SELECT *, 1 ord FROM {permissions} WHERE applyto=0 AND id={0}
					UNION SELECT *, 2 ord FROM {permissions} WHERE applyto=0 AND id IN ({1c})
					UNION SELECT *, 3 ord FROM {permissions} WHERE applyto=1 AND id={2}
					ORDER BY ord",
		$loguserGroup['id'], $secgroups, $loguserid);
	$loguserPermset = LoadPermset($res);

	//Coding Language

	$loguser['banned'] = ($loguserGroup['id'] == Settings::get('bannedGroup'));
	$loguser['root'] = ($loguserGroup['id'] == Settings::get('rootGroup'));
	$loguser['owner'] = ($loguserGroup['id'] == Settings::get('rootGroup'));
	$loguser['rank'] = $loguserGroup['rank'];
	$loguser['group'] = $usergroups[$loguser['primarygroup']];

	//Language people told me its easier to code in so I just added it in.

	if (isset($user)) {
		$myrank = $loguserGroup['rank'];														//My Rank
		$targetrank = $usergroups[$user['primarygroup']]['rank'];					//The Targets Rank
		$Iamroot = ($loguserGroup['id'] == Settings::get('rootGroup'));			//I am Root/Owner
		$Iamowner = ($loguserGroup['id'] == Settings::get('rootGroup'));		//I am Root/Owner
		$Iambanned = ($loguserGroup['id'] == Settings::get('bannedGroup'));//I am banned
		$myGroup = $usergroups[$loguser['primarygroup']];							//My Group
		$Iamloggedin = $loguser["id"];															//I am logged in
		$Iamnotloggedin = !$loguser["id"];													//I am not logged in
	}
}

function HasPermission($perm, $arg=0, $guest=false) {
	global $guestPermset, $loguserPermset, $loguserid;

	// Root user always has permission, no matter what,
	if ($loguserid == 1)
		return true;

	$permset = $guest ? $guestPermset : $loguserPermset;

	// check general permission first
	if (!isset($permset[$perm]) || $permset[$perm] == -1)
		return false;

	$needspecific = !$permset[$perm];
	if ($needspecific && $arg == 0)
		return false;

	// then arg-specific permission
	// if it's set to revoke it revokes the general permission
	if ($arg) {
		$perm .= '_'.$arg;
		if ($needspecific) {
			if (!isset($permset[$perm]) || $permset[$perm] != 1)
				return false;
		} else {
			if (!isset($permset[$perm]) || $permset[$perm] == -1)
				return false;
		}
	}

	return true;
}

function CheckPermission($perm, $arg=0, $guest=false) {
	global $loguserid, $loguser;

	if (!HasPermission($perm, $arg, $guest)) {
		if (!$loguserid)
			Kill(__('You must be logged in to perform this action.'));
		else if ($loguser['banned'])
			Kill(__('You may not perform this action because you are banned.'));
		else
			Kill(__('You may not perform this action.'));
	}
}

function ForumsWithPermission($perm, $guest=false) {
	global $guestPermset, $loguserPermset;
	static $fpermcache = [];

	if ($guest) {
		$permset = $guestPermset;
		$cperm = 'guest_'.$perm;
	} else {
		$permset = $loguserPermset;
		$cperm = $perm;
	}

	if (isset($fpermcache[$cperm]))
		return $fpermcache[$cperm];

	$ret = [];

	// if the general permission is set to deny, no need to check for specific permissions
	if ($permset[$perm] == -1) {
		$fpermcache[$cperm] = $ret;
		return $ret;
	}

	$forumlist = Query("SELECT id FROM {forums}");
	$check = (bool)($permset[$perm] == 1);

	// if the general permission is set to grant, we need to check for forums for which it'd be revoked
	// otherwise we need to check for forums for which it'd be granted
	while ($forum = Fetch($forumlist)) {
		if ($check && (isset($permset[$perm.'_'.$forum['id']]) && $permset[$perm.'_'.$forum['id']] != -1))
			$ret[] = $forum['id'];
		elseif (!$check && ((isset($permset[$perm.'_'.$forum['id']]) && $permset[$perm.'_'.$forum['id']] == 1)))
			$ret[] = $forum['id'];
		// We're still checking if this exists but since the others failed, it's a neutral perm.
		elseif (!isset($permset[$perm.'_'.$forum['id']]))
			$ret[] = $forum['id'];
	}


	$fpermcache[$cperm] = $ret;
	return $ret;
}

// retrieves the given permissions for the given users
// retrieves all possible permissions if $perms is left out
function GetUserPermissions($users, $perms=null) {
	if (is_array($users))
		$userclause = 'IN ({0c})';
	else
		$userclause = '= {0}';

	// retrieve all the groups those users belong to
	$allgroups = Query("
				SELECT primarygroup gid, id uid, 0 type FROM {users} WHERE id {$userclause}
		UNION 	SELECT groupid gid, userid uid, 1 type FROM {secondarygroups} WHERE userid {$userclause}",
		$users);

	$primgroups = [];	// primary group IDs
	$secgroups = [];	// secondary group IDs
	$groupusers = [];	// array of user IDs for each group

	while ($g = Fetch($allgroups)) {
		if ($g['type'])
			$secgroups[] = $g['gid'];
		else
			$primgroups[] = $g['gid'];

		$groupusers[$g['gid']][] = $g['uid'];
	}

	// remove duplicate group IDs. This is faster than using array_unique.
	$primgroups = array_flip(array_flip($primgroups));
	$secgroups = array_flip(array_flip($secgroups));

	if (is_array($perms))
		$permclause = 'AND perm IN ({3c})';
	else if ($perms)
		$permclause = 'AND perm = {3}';
	else
		$permclause = '';

	// retrieve all the permissions related to those users and groups
	$res = Query("
				SELECT *, 1 ord FROM {permissions} WHERE applyto=0 AND id IN ({1c}) {$permclause}
		UNION 	SELECT *, 2 ord FROM {permissions} WHERE applyto=0 AND id IN ({2c}) {$permclause}
		UNION 	SELECT *, 3 ord FROM {permissions} WHERE applyto=1 AND id {$userclause} {$permclause}
				ORDER BY ord",
		$users, $primgroups, $secgroups, $perms);

	$permdata = [];
	$permord = [];

	// compile all the resulting permission lists for all the requested users
	while ($p = Fetch($res)) {
		if ($p['value'] == 0) continue;

		$k = $p['perm'];
		if ($p['arg']) $k .= '_'.$p['arg'];

		if ($p['applyto'] == 0) {	// group perm -- apply it to all the matching users
			foreach ($groupusers[$p['id']] as $uid) {
				if ($p['ord'] > $permord[$uid][$k] || $permdata[$uid][$k] != -1)
					$permdata[$uid][$k] = $p['value'];

				$permord[$uid][$k] = $p['ord'];
			}
		} else { // user perm
			$uid = $p['id'];

			if ($p['ord'] > $permord[$uid][$k] || $permdata[$uid][$k] != -1)
				$permdata[$uid][$k] = $p['value'];

			$permord[$uid][$k] = $p['ord'];
		}
	}

	unset($permord);
	return $permdata;
}


LoadGroups();
$loguser['powerlevel'] = -1; // safety
