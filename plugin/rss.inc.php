<?php
// PukiWiki - Yet another WikiWikiWeb clone
// $Id: rss.inc.php,v 1.18 2006/03/05 15:01:31 henoheno Exp $
//
// RSS plugin: Publishing RSS of RecentChanges
//
// Usage: plugin=rss[&ver=[0.91|1.0|2.0]] (Default: 0.91)
//
// NOTE for acronyms
//   RSS 0.9,  1.0  : RSS means 'RDF Site Summary'
//   RSS 0.91, 0.92 : RSS means 'Rich Site Summary'
//   RSS 2.0        : RSS means 'Really Simple Syndication' (born from RSS 0.92)

define('RSS_DESP_LENGTH', 256);

function plugin_rss_action()
{
	global $vars, $rss_max, $page_title, $whatsnew, $trackback, $username;
	global $qblog_defaultpage, $qblog_title, $qblog_close, $script;
	$qm = get_qm();
	$qt = get_qt();

	$version = isset($vars['ver']) ? $vars['ver'] : '';
	switch($version){
	case '':  $version = '2.0'; break; // Default
	case '1': $version = '1.0';  break; // Sugar
	case '2': $version = '2.0';  break; // Sugar
	case '0.91': /* FALLTHROUGH */
	case '1.0' : /* FALLTHROUGH */
	case '2.0' : break;
	default: die($qm->m['plg_rss']['err_version']);
	}

	$lang = LANG;
	$qblog_mode = FALSE;
	$qblog_export = FALSE;
	$page_export  = FALSE; // 全ページエクスポート

	//blogモード
	if( isset($vars['blog_rss']) && $vars['blog_rss']!='' ){
		$blog_mode = $vars['blog_rss']; //ブログページ名
		$qt->setv('blog_rss_mode', true);

		$page_title_utf8 = $page_title.' - '.$blog_mode;
	}
	// qblogモード
	else if (isset($vars['qblog_rss']))
	{
		//閉鎖中はRSS出力しない
		if ($qblog_close)
		{
			exit;
		}
		$qblog_mode = TRUE;
		$blog_mode = FALSE;
		$page_title_utf8 = mb_convert_encoding($qblog_title, 'UTF-8', SOURCE_ENCODING);

		//全ページ表示
		if (isset($vars['qblog_export']) && $vars['qblog_export'] === $username) {
			$qblog_export = TRUE;
		}
	}
	else if (isset($vars['page_export']) && $vars['page_export'] === $username) {
		$blog_mode = FALSE;
		$page_export = TRUE;
		$page_title_utf8 = mb_convert_encoding($page_title, 'UTF-8', SOURCE_ENCODING);
	}
	else
	{
		$blog_mode = false;
		$page_title_utf8 = mb_convert_encoding($page_title, 'UTF-8', SOURCE_ENCODING);
	}

	$self = get_script_uri();

	// Creating <item>
	global $ignore_plugin, $strip_plugin, $strip_plugin_inline;
	$items = $rdf_li = '';

	if ($qblog_mode)
	{
		$qblog_recent = CACHEQBLOG_DIR . 'qblog_recent.dat';
		if (! file_exists($qblog_recent)) die($qm->m['plg_rss']['err_nodata']);

		if ($qblog_export) {
			$lines = plugin_rss_qblog_posts();
		}
		else {
			// ページネーション用のヘッダー行を飛ばす
			$lines = file_head($qblog_recent, $rss_max+1);
			array_shift($lines);
		}
	}
	else
	{
		if ($page_export) {
			$pages = plugin_rss_get_all_page();
			$lines = array();
			foreach ($pages as $_page) {
				if ( ! preg_match('/\A(:config(\z|\/)|InterWiki|MenuAdmin|QBlog|QHMAdmin|RecentChanges|RecentDeleted)/', $_page)) {
					$lines[] = "\t$_page";
				}
			}
		} else {
			$recent = CACHE_DIR . 'recent.dat';
			if (! file_exists($recent)) die($qm->m['plg_rss']['err_nodata']);
			$lines = file_head($recent, $rss_max);
		}
	}

	foreach ($lines as $line)
	{
		$skip_list = FALSE;

		if ($qblog_mode)
		{
			$page = trim($line);
			$time = strtotime(get_qblog_date('Y-m-d 00:00:00',$page));
		}
		else
		{
			list($time, $page) = explode("\t", rtrim($line));
		}

		//blogモードで、$blog_name/Hogehogeでないなら(つまり、ブログページでないなら、何もしない
		if( $blog_mode && !preg_match('/^'.$blog_mode.'\/.*/', $page) ){
			continue;
		}

		$r_page = rawurlencode($page);
		$title  = get_page_title($page);
		$source = get_source($page);

		foreach($source as $k => $l)
		{
			if (preg_match($ignore_plugin, $l))
			{	// リストから省く
				$skip_list = TRUE;
				break;
			}

			if (preg_match($strip_plugin, $l))
			{	// 説明から省く
				unset($source[$k]);
			}
		}
		if ($skip_list)
		{
			continue;
		}

		//html(noskinを避ける)
		if( count($source) > 0){
			$source = str_replace('#html(noskin)', '#html()', $source);
			$source = preg_replace($strip_plugin_inline, '', $source); // 行内のプラグインを説明から省く
		}

		// 全文出力
		if ($qblog_export || $page_export) {
			$contents = convert_html($source);
			$contents = preg_replace(
				'/<img src="(swfu\/.*?)"/',
				'<img src="'.h(dirname($script)).'/\1"',
				$contents
			);
		} else {
			$contents = mb_strimwidth( strip_htmltag( convert_html( $source ) ), 0, RSS_DESP_LENGTH , '...' );
		}
		$contents = preg_replace_callback(
			'/(&[^;]+;)/',
			'plugin_rss_html_entity_decode', $contents);
		$contents = plugin_rss_utf8_for_xml($contents);

		switch ($version) {
		case '0.91': /* FALLTHROUGH */
		case '2.0':
			$wp_post_type = $page_export ? '<wp:post_type>page</wp:post_type>' : '';
			$wp_post_type = $qblog_export ? '<wp:post_type>post</wp:post_type>' : $wp_post_type;

			$post_date = date('Y-m-d H:i:s', $time);
			$post_date_gmt = gmdate('Y-m-d H:i:s', $time);
			$wp_post_date = $page_export || $qblog_export ? '<wp:post_date>'.$post_date.'</wp:post_date><wp:post_date_gmt>'.$post_date_gmt.'</wp:post_date_gmt>' : '';

			$permalink = "{$self}?{$r_page}";
			$wp_qhm_permalink = $qblog_export || $page_export ? '<wp:postmeta><wp:meta_key>qhm_permalink</wp:meta_key><wp:meta_value>'.$permalink.'</wp:meta_value></wp:postmeta>' : '';
			$date = get_date('D, d M Y H:i:s T', $time);
			if ($version == '0.91') {
				$date = '';
				$desc = ' <description>' . $date .' -- '. $contents. '</description>';
			} else {
				$date = ' <pubDate>'     . $date . '</pubDate>';
				if ($qblog_export || $page_export) {
					$desc = '<content:encoded><![CDATA['. $contents . ']]></content:encoded>';
				} else {
					$desc = ' <description>' . $contents . '</description>';
				}
			}
			$items .= <<<EOD
<item>
 <title>$title</title>
 <link>$permalink</link>
$date
$desc
$wp_post_type
$wp_post_date
$wp_qhm_permalink
</item>

EOD;
			break;

		case '1.0':
			// Add <item> into <items>
			$rdf_li .= '    <rdf:li rdf:resource="' . $self .
				'?' . $r_page . '" />' . "\n";

			$date = substr_replace(get_date('Y-m-d\TH:i:sO', $time), ':', -2, 0);
			$trackback_ping = '';
			if ($trackback) {
				$tb_id = md5($r_page);
				$trackback_ping = ' <trackback:ping>' . $self .
					'?tb_id=' . $tb_id . '</trackback:ping>';
			}
			$items .= <<<EOD
<item rdf:about="$self?$r_page">
 <title>$title</title>
 <link>$self?$r_page</link>
 <description><![CDATA[$contents]]></description>
 <dc:date>$date</dc:date>
 <dc:identifier>$self?$r_page</dc:identifier>
$trackback_ping
</item>

EOD;
			break;
		}
	}

	// Feeding start
	pkwk_common_headers();
	header('Content-type: application/xml; charset=utf-8');
	print '<?xml version="1.0" encoding="UTF-8"?>' . "\n\n";

	$r_whatsnew = rawurlencode($blog_mode ? $blog_mode : $whatsnew);
	$pagename = $qblog_mode ? $qblog_defaultpage : $r_whatsnew;
	$description = $qblog_mode ? 'QBlog Recent Changes' : $qm->m['plg_rss']['description'];

	$page_title_utf8 = h(plugin_rss_utf8_for_xml($page_title_utf8));
	$description = h(plugin_rss_utf8_for_xml($description));

	$wxr_definitions = $page_export || $qblog_export ? '
  <wp:wxr_version>1.2</wp:wxr_version>
' : '';

	switch ($version) {
	case '0.91':
		print '<!DOCTYPE rss PUBLIC "-//Netscape Communications//DTD RSS 0.91//EN"' .
		' "http://my.netscape.com/publish/formats/rss-0.91.dtd">' . "\n";
		 /* FALLTHROUGH */

	case '2.0':
		print <<<EOD
<rss version="{$version}"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:wp="http://wordpress.org/export/1.2/"
>
 <channel>
  <title>{$page_title_utf8}</title>
  <link>{$self}?{$pagename}</link>
  <description>{$description}</description>
  <language>{$lang}</language>
  {$wxr_definitions}

{$items}
 </channel>
</rss>
EOD;
		break;

	case '1.0':
		$xmlns_trackback = $trackback ?
			'  xmlns:trackback="http://madskills.com/public/xml/rss/module/trackback/"' : '';
		print <<<EOD
<rdf:RDF
  xmlns:dc="http://purl.org/dc/elements/1.1/"
{$xmlns_trackback}
  xmlns="http://purl.org/rss/1.0/"
  xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
  xml:lang="$lang">
 <channel rdf:about="$self?{$pagename}">
  <title>{$page_title_utf8}</title>
  <link>$self?{$pagename}</link>
  <description>{$description}</description>
  <items>
   <rdf:Seq>
{$rdf_li}
   </rdf:Seq>
  </items>
 </channel>

{$items}
</rdf:RDF>
EOD;
		break;
	}
	exit;
}

function plugin_rss_html_entity_decode($matches){
	if( preg_match("/^&(lt|gt|amp|apos|quot);$/", $matches[1]) ){
		return $matches[1];
	}else{
		return html_entity_decode($matches[1],ENT_COMPAT, "utf-8");
	}
}

function plugin_rss_utf8_for_xml($string)
{
    return preg_replace ('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $string);
}

function plugin_rss_qblog_posts()
{
	global $qblog_page_prefix, $qblog_page_re;

	$files = glob(DATA_DIR . encode($qblog_page_prefix) . '*');

	$pages = array();
	foreach ($files as $file)
	{
		$pagename = decode(basename($file, '.txt'));
		if (preg_match($qblog_page_re, $pagename, $mts))
		{
			$pages[] = $pagename;
		}
	}
	return $pages;
}

function plugin_rss_get_all_page($dir = DATA_DIR, $ext = '.txt')
{
	$pages = get_existpages($dir, $ext);
	$qm = get_qm();

	$symbol = ' ';
	$list = array();

	//並び替える
	foreach ($pages as $file => $page)
	{
		$list[] = $page;
	}

	return $list;
}
