<?php

// Disallow direct access to this file for security reasons.
if (!defined('IN_MYBB')) {
	die('Direct access to this file is not allowed.');
}

$plugins->add_hook('build_forumbits_forum', 'catlastpost_hookin__build_forumbits_forum');

function catlastpost_info() {
	return array(
		'name'          => 'Category last post',
		'description'   => 'Displays the last post for a category on the main listing',
		'website'		=> 'https://community.mybb.com/mods.php?action=view&pid=1592',
		'author'        => '<b><a href="https://community.mybb.com/user-116662.html" style="color: #41929e">Laird</a></b> and <b><a href="https://community.mybb.com/user-134311.html">PARADOX</a></b>, based on code and templating by <b><a href="https://community.mybb.com/user-25096.html" style="color: #ff7500">Omar G</a></b>',
		'version'       => '1.0',
		'codename'      => 'catlastpost',
		'compatibility' => '18*'
	);
}

function catlastpost_install() {
	global $mybb, $cache, $db;

	$db->insert_query('templates', array(
		'title' => 'forumbit_depth1_cat_lastpost',
		'template' => $db->escape_string('<tr>
	<td class="trow2" colspan="5">
		<div class="float_right smalltext">
			Threads: {$threads}<br />
			Posts: {$posts}<br />
		</div>
		<div class="lastpostav">{$threadLastAvatar}</div><span class="smalltext">
			<a href="{$threadLink}" title="{$threadFullSubject}"><strong>{$threadSubject}</strong></a>
			<br />{$date}<br />{$lang_by} {$profileLink}
		</span>
	</td>
</tr>'),
		'sid'      => '-2',
		'version'  => '1',
		'dateline' => TIME_NOW
	));

	$lastpostav_css = '.lastpostav {
	height: 40px;
	width: 40px;
	border: 1px solid #d8dfea;
	border-radius: 50%;
	padding: 4px;
	margin: auto;
	margin-right: 6px;
	float: left;
	opacity: 0.8;
	overflow: hidden;
	outline: none;
	cursor: pointer;
}
.lastpostav img {
	width: 100%;
	height: 100%;
	border-radius: 50%;
}';

	$stylesheet = array(
		"name"		=> "lastpostav.css",
		"tid"		=> 1,
		"attachedto"	=> "",
		"stylesheet"	=> $db->escape_string($lastpostav_css),
		"cachefile"	=> "lastpostav.css",
		"lastmodified"	=> TIME_NOW
	);

	$sid = $db->insert_query("themestylesheets", $stylesheet);

	//File required for changes to styles and templates.
	require_once MYBB_ADMIN_DIR.'/inc/functions_themes.php';
	cache_stylesheet($stylesheet['tid'], $stylesheet['cachefile'], $lastpostav_css);
	update_theme_stylesheet_list(1, false, true);

	// Add setting group and toggle setting
	$setting_group = array(
		"name" => "catlastpost",
		"title" => "Category Last Post Settings",
		"description" => "Controls display behavior for the Category Last Post plugin.",
		"disporder" => 100,
		"isdefault" => 0
	);
	$gid = $db->insert_query("settinggroups", $setting_group);

	$setting = array(
		"name" => "catlastpost_show_on_forumdisplay",
		"title" => "Show on Category View (forumdisplay.php)",
		"description" => "Show category last post info when viewing the category itself (forumdisplay.php).",
		"optionscode" => "yesno",
		"value" => "0",
		"disporder" => 1,
		"gid" => $gid
	);
	$db->insert_query("settings", $setting);

	rebuild_settings(); // refresh the cache
}

function catlastpost_uninstall() {
	global $mybb, $cache, $db;

	// Remove the plugin's custom template
	$db->delete_query('templates', "title = 'forumbit_depth1_cat_lastpost'");

	// Remove the plugin's custom stylesheet
	$db->delete_query('themestylesheets', "name='lastpostav.css'");

	// Remove plugin setting and setting group
	$db->delete_query('settings', "name='catlastpost_show_on_forumdisplay'");
	$db->delete_query('settinggroups', "name='catlastpost'");

	// Refresh the settings cache
	rebuild_settings();

	// Remove only the {$GLOBALS['catLastPost']} injection from templates
	require_once MYBB_ROOT.'/inc/adminfunctions_templates.php';
	find_replace_templatesets('forumbit_depth1_cat', '#\{\$GLOBALS\[\'catLastPost\'\]\}#', '', 0);
	find_replace_templatesets('forumdisplay_subforums', '#\{\$GLOBALS\[\'catLastPost\'\]\}#', '', 0);

	// Rebuild stylesheet list
	require_once MYBB_ADMIN_DIR.'inc/functions_themes.php';
	$query = $db->simple_select('themes', 'tid');
	while ($theme = $db->fetch_array($query)) {
		update_theme_stylesheet_list($theme['tid']);
	}
}


function catlastpost_is_installed() {
	global $db;

	$res = $db->simple_select('templates', '*', "title ='forumbit_depth1_cat_lastpost'");
	return ($db->affected_rows() > 0);
}

function catlastpost_activate() {
	require_once MYBB_ROOT.'/inc/adminfunctions_templates.php';
	find_replace_templatesets('forumbit_depth1_cat', '(\\{\\$sub_forums\\})', '{$sub_forums}{$GLOBALS[\'catLastPost\']}');
	find_replace_templatesets('forumdisplay_subforums', '(\\{\\$forums\\})', '{$forums}{$GLOBALS[\'catLastPost\']}');
}


function catlastpost_deactivate() {
	require_once MYBB_ROOT.'/inc/adminfunctions_templates.php';
	find_replace_templatesets('forumbit_depth1_cat', '(\\{\\$GLOBALS\\[\'catLastPost\'\\]\\})', '', 0);
	find_replace_templatesets('forumdisplay_subforums', '(\\{\\$GLOBALS\\[\'catLastPost\'\\]\\})', '', 0);
}

// Gather the last-post data for the forum/category in the forum
// $forum, being a leaf entry in the $fcache multidimensional array.
// Start with the data for the forum itself, and then recurse
// through its child forums/categories in the $fcache array, if any.
// $data holds the current array of data and is updated during recursion.
function clp_get_last_post_data_r($forum, &$data) {
	global $fcache, $forumpermissions;

	if ($forum['type'] == 'f') {
		$permissions = $forumpermissions[$forum['fid']];

		if ((!$permissions['canview'] && $mybb->settings['hideprivateforums'])
		    ||
		    (isset($permissions['canviewthreads']) && !$permissions['canviewthreads'])
		    ||
		    !forum_password_validated($forum, true)
		) {
			;
		} else {
			if ($data['lastpost'] < $forum['lastpost']) {
				$data['lastpost'] = (int)$forum['lastpost'];
				$data['lastposter'] = htmlspecialchars_uni($forum['lastposter']);
				$data['lastposteruid'] = (int)$forum['lastposteruid'];
				$data['lastposttid'] = (int)$forum['lastposttid'];
				$data['lastpostsubject'] = $forum['lastpostsubject'];
			}

			if (empty($permissions['canonlyviewownthreads'])) {
				$data['threads'] += $forum['threads'];
				$data['posts'] += $forum['posts'];
				$data['unapprovedposts'] += $forum['unapprovedposts'];
				$data['unapprovedthreads'] += $forum['unapprovedthreads'];
			}
		}
	}

	if (isset($fcache[$forum['fid']])) {
		foreach ($fcache[$forum['fid']] as $disp_order => $forums_disp) {
			foreach ($forums_disp as $forum) {
				clp_get_last_post_data_r($forum, $data);
			}
		}
	}
}

function catlastpost_hookin__build_forumbits_forum(&$args) {
	global $parser, $templates, $lang, $fcache, $lastPostCache, $catLastPost;
	global $posts, $threads, $unapprovedposts, $unapprovedthreads;
	global $mybb; // added for settings access

	$isForumDisplay = (constant('THIS_SCRIPT') == 'forumdisplay.php');

	if ($args['type'] != 'c' && !$isForumDisplay) {
		return;
	}

	if ($isForumDisplay) {
		global $fid;
	} else {
		$fid = $args['fid'];
	}

	if (isset($lastPostCache[$fid])) {
		$lastPostData = $lastPostCache[$fid];
	} else {
		if (!isset($lastPostCache)) {
			$lastPostCache = array();
		}
		$lastPostData = [
			'lastpost' => 0,
			'lastposter' => '',
			'lastposteruid' => 0,
			'lastposttid' => 0,
			'lastpostsubject' => '',
			'threads' => 0,
			'posts' => 0,
			'unapprovedposts' => 0,
			'unapprovedthreads' => 0,
		];

		if (isset($fcache[$fid])) {
			$forums_disp = array_values($fcache[$fid])[0];
			$first_child = array_values($forums_disp)[0];
			$parents = explode(',', $first_child['parentlist']);
			$parent_id = 0;
			foreach ($parents as $p) {
				if ($p == $fid) {
					break;
				} else {
					$parent_id = $p;
				}
			}
			$found = false;
			foreach ($fcache[$parent_id] as $forums_disp) {
				foreach ($forums_disp as $forum) {
					if ($forum['fid'] == $fid) {
						$found = true;
						break;
					}
				}
				if ($found) {
					break;
				}
			}

			if ($found) {
				clp_get_last_post_data_r($forum, $lastPostData);
			}
		}
		$lastPostCache[$fid] = $lastPostData;
	}

	foreach (['threads', 'posts', 'unapprovedposts', 'unapprovedthreads'] as $key) {
		${$key} = my_number_format($lastPostData[$key]);
	}

	$date = $lastPostData['lastpost'] ? my_date('relative', $lastPostData['lastpost']) : '';

	$profileLink = $lastPostData['lastposteruid'] ? build_profile_link($lastPostData['lastposter'], $lastPostData['lastposteruid']) : '';

	if ($lastPostData['lastposteruid']) {
		$profileUrl = get_profile_link($lastPostData['lastposteruid']);
		$lastPoster = get_user($lastPostData['lastposteruid']);
		$lastPosterAvatar = format_avatar($lastPoster['avatar']);

		$threadLastAvatar = "<a href=\"{$profileUrl}\"><img src=\"{$lastPosterAvatar['image']}\" class=\"lastpostav\" alt=\"\" {$lastPosterAvatar['width_height']} /></a>";
		$lang_by = $lang->by;
	} else {
		$lang_by = $threadLastAvatar = '';
	}

	$threadLink = $lastPostData['lastposttid'] ? get_thread_link($lastPostData['lastposttid'], 0, 'lastpost') : '';

	$threadSubject = $threadFullSubject = $parser->parse_badwords($lastPostData['lastpostsubject']);

	if (my_strlen($threadSubject) > 25) {
		$threadSubject = my_substr($threadSubject, 0, 25).'...';
	}

	$threadSubject = htmlspecialchars_uni($threadSubject);
	$threadFullSubject = htmlspecialchars_uni($threadFullSubject);

	// Use the toggle setting to decide whether to display
	if (THIS_SCRIPT != 'forumdisplay.php' || $mybb->settings['catlastpost_show_on_forumdisplay']) {
		$catLastPost = eval($templates->render('forumbit_depth1_cat_lastpost'));
	} else {
		$catLastPost = '';
	}
}
