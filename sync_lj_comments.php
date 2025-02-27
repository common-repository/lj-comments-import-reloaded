<?
define('DB_NAME', '');    // The name of the database
define('DB_USER', '');     // Your MySQL username
define('DB_PASSWORD', ''); // ...and password
define('DB_HOST', 'localhost');    // 99% chance you won't need to change this value
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');
$table_prefix  = '';   // Only numbers, letters, and underscores please!

function get_wp_option($n) {
	global $table_prefix;
	
	$rez = mysql_query('SELECT `option_value`
											FROM `'.$table_prefix.'options`
											WHERE `option_name` = "'.$n.'"');
	list($v) = @mysql_fetch_row($rez);
	
	return @$v;
}

function set_wp_option($n, $v) {
	global $table_prefix;
	
	mysql_query('UPDATE `'.$table_prefix.'options`
							SET `option_value` = "'.mysql_real_escape_string($v).'"
							WHERE `option_name` = "'.$n.'"');
}

function cut_text($txt, $bleft, $bright) {
	$mpos = strpos($txt, $bleft);
	if ($mpos === false) {
		return '';
	}
	return substr($txt,
								$mpos + strlen($bleft),
								strpos($txt, $bright, $mpos) - $mpos - strlen($bright) + 1);
} // cut_text

$db = mysql_connect(DB_HOST, DB_USER, DB_PASSWORD);
mysql_select_db(DB_NAME);
mysql_query('SET NAMES '.DB_CHARSET);

/*$rez = mysql_query('SELECT MIN(`meta_value`)
										FROM `'.$table_prefix.'postmeta`
										WHERE (`meta_key` = "ljID")');
list($first_post_id) = mysql_fetch_row($rez);*/


//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////// init and login - begin //////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////


$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
curl_setopt($ch, CURLOPT_COOKIEFILE, $_SERVER['DOCUMENT_ROOT'].'/wp-content/plugins/lj-comments-import-reloaded/cookie.txt'); //get cookie from file
curl_setopt($ch, CURLOPT_COOKIEJAR, $_SERVER['DOCUMENT_ROOT'].'/wp-content/plugins/lj-comments-import-reloaded/cookie.txt');
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 6.0; en-US; rv:1.9.0.5) Gecko/2008120122 Firefox/3.0.5');
curl_setopt($ch, CURLOPT_URL, 'http://www.livejournal.com/');
$page = curl_exec($ch);

$login = get_wp_option('lj_comments_username');
$password = get_wp_option('lj_comments_pass');

curl_setopt($ch, CURLOPT_URL, 'https://www.livejournal.com/login.bml?ret=1');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'mode=login&user='.$login.'&password='.$password.'&_submit=%D0%92%D1%85%D0%BE%D0%B4+');
$page = curl_exec($ch);

curl_setopt($ch, CURLOPT_URL, 'http://www.livejournal.com/export_comments.bml?get=comment_meta&startid=0');
$page = curl_exec($ch);


//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////// init and login - end ////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////



//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////// comments meta data - begin //////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

curl_setopt($ch, CURLOPT_URL, 'http://www.livejournal.com/export_comments.bml?get=comment_meta&startid=0');
$page = curl_exec($ch);
$maxid = false;
if (strlen($page) > 0) {
	$maxid = trim(cut_text($page, '<maxid>', '</maxid>'));
											
	$tmp = mysql_query('SELECT *
											FROM `'.$table_prefix.'lj_comments_meta`');
	$curr_meta = array();
	while ($row = mysql_fetch_assoc($tmp)) {
		array_push($curr_meta, $row);
	} // fetch current meta
	
	$tmp = mysql_query('SELECT *
											FROM `'.$table_prefix.'lj_comments_users`');
	$curr_users = array();
	while ($row = mysql_fetch_assoc($tmp)) {
		array_push($curr_users, $row);
	} // fetch current users

	$allmeta = explode("\n", trim(cut_text($page, '<comments>', '</comments>')));

for ($i = 0; $i < count($allmeta); $i++) {
		$matches = array();
		if (preg_match("/\<comment id='([0-9]*?)' posterid='([0-9]*?)' \/\>/", $allmeta[$i], $matches)) {
			 $matches[3] = '';
		}
		else if (preg_match("/\<comment id='([0-9]*)' \/\>/", $allmeta[$i], $matches)) {
			$matches[2] = '0';
			$matches[3] = '';
		}
		else if (preg_match("/\<comment id='([0-9]*)' state='([A-Za-z]*)' \/\>/", $allmeta[$i], $matches)) {
			$matches[3] = $matches[2];
			$matches[2] = '0';
		}
		else {
			preg_match("/\<comment id='([0-9]*)' posterid='([0-9]*)' state='([A-Za-z]*)' \/\>/", $allmeta[$i], $matches);
		}
		$found = false;
		$state_updated = false;
		for ($t = 0; $t < count($curr_meta); $t++) {
			if (($curr_meta[$t]['id'] == $matches[1]) and ($curr_meta[$t]['posterid'] == $matches[2])) {
				$found = true;
				if ($curr_meta[$t]['state'] != $matches[3]) {
					$state_updated = true;
				}
			}
		} // check current meta
		if (!$found) {
			mysql_query('INSERT INTO `'.$table_prefix.'lj_comments_meta`
									(`id`, `posterid`, `state`)
									VALUES
									('.intval($matches[1]).', '.intval($matches[2]).', "'.mysql_real_escape_string($matches[3]).'")');
		} // if !$found
		else if ($state_updated) {
			mysql_query('UPDATE `'.$table_prefix.'lj_comments_meta`
									SET `state` = "'.mysql_real_escape_string($matches[3]).'"
									WHERE (`id` = '.intval($matches[1]).') and (`posterid` = '.intval($matches[2]).')');
		} // if $state_updated
	} // for fetched meta
	
	$allusers = explode("\n", trim(cut_text($page, '<usermaps>', '</usermaps>')));

for ($i = 0; $i < count($allusers); $i++) {
		preg_match("/\<usermap id='([0-9]*)' user='(.+?)' \/\>/", $allusers[$i], $matches);
		$found = false;
		$name_changed = false;
		for ($t = 0; $t < count($curr_users); $t++) {
			if ($curr_users[$t]['id'] == $matches[1]) {
				$found = true;
				if ($curr_users[$t]['username'] != $matches[2]) {
					$name_changed = true;
				}
			}
		} // check current users
		if (!$found) {
		//$userpic = lj_comments_get_avatar($matches[2]);
			mysql_query('INSERT INTO `'.$table_prefix.'lj_comments_users`
										(`id`, `username`)
										VALUES
										('.intval($matches[1]).', "'.mysql_real_escape_string($matches[2]).'")');
		}
		else if ($name_changed) {
		//$userpic = lj_comments_get_avatar($matches[2]);
			mysql_query('UPDATE `'.$table_prefix.'lj_comments_users`
									SET `username` = "'.mysql_real_escape_string($matches[2]).'",
									WHERE (`id` = '.intval($matches[1]).')');
		}
	} // for fetched users
} // parse comments meta

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////// comments meta data - end ////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////


//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////// comments main data - begin //////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

$tmp = mysql_query('SELECT MAX(`id`)
										FROM `'.$table_prefix.'lj_comments`');
list($curr_maxid) = mysql_fetch_row($tmp);
$curr_maxid = intval($curr_maxid) - 1;
if ($curr_maxid < $maxid) {
	for ($tmp_maxid = $curr_maxid; $tmp_maxid <= $maxid; $tmp_maxid += 1000) {
		curl_setopt($ch, CURLOPT_URL, 'http://www.livejournal.com/export_comments.bml?get=comment_body&startid='.$tmp_maxid);
		$page = curl_exec($ch);
		$cpos = strpos($page, '<comments>');
		preg_match_all("/\<comment(.+?)\<\/comment\>/si", preg_replace("/\<comment[ a-z0-9=']*state='[S|D|F]'[ a-z0-9=']*\/\>/si", "", cut_text($page, '<comments>', '</comments>')), $matches);
		for ($i = 0; $i < count($matches[1]); $i++) {
			$subj = cut_text($matches[1][$i], '<subject>', '</subject>');
			$body = cut_text($matches[1][$i], '<body>', '</body>');
			$date = cut_text($matches[1][$i], '<date>', '</date>');
			$tmp = explode("\n", trim($matches[1][$i]));
			$tmp = explode(' ', str_replace('>', '', $tmp[0]));
			$info = array();
			$info['id'] = 0;
			$info['posterid'] = 0;
			$info['jitemid'] = 0;
			$info['parentid'] = 0;
			for ($t = 0; $t < count($tmp); $t++) {
				$tmp2 = explode('=', $tmp[$t]);
				$info[$tmp2[0]] = str_replace("'", '', $tmp2[1]);
			}
			$tmp = mysql_query('SELECT COUNT(*)
													FROM `'.$table_prefix.'lj_comments`
													WHERE (`id` = '.intval($info['id']).')');
			list($cnt) = mysql_fetch_row($rez);
			if (intval($cnt) == 0) {
				$tmp_rez = mysql_query('SELECT COUNT(*)
																FROM `'.$table_prefix.'lj_comments`
																WHERE (`id` = '.intval($info['id']).')');
				list($tmp_cnt) = mysql_fetch_row($tmp_rez);
				if ($tmp_cnt == 0) {
					mysql_query('INSERT INTO `'.$table_prefix.'lj_comments`
											(`id`, `jitemid`, `posterid`, `parentid`, `subject`, `body`, `date`)
											VALUES
											('.intval($info['id']).', '.intval($info['jitemid']).', '.intval($info['posterid']).', '.intval($info['parentid']).',
											"'.mysql_real_escape_string($subj).'", "'.mysql_real_escape_string($body).'", '.strtotime($date).')');
				}
			}
		} // for all found comments/patterns
	} // for each download step
} // if we have to download new comments

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////// comments main data - end ////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

curl_close($ch);

set_wp_option('lj_comments_last_sync', time());

mysql_close($db);

echo 'LJ comments sync finished';

?>