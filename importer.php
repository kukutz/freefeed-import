<?php

/*  GET TWITTER TOKEN (needed only once. Bearer below is example, not true credentials)
$bearer = "xvz1evFS4wEEPTGEFPHBog:L8qq9PZyRg6ieKGEKhZolGC0vJWLw8iEJ88DRdyOg";
$opts = array('http' =>
  array(
		'method'  => 'POST',
		'header'  => "Content-Type: application/x-www-form-urlencoded;charset=UTF-8\r\n".
      "Authorization: Basic ".base64_encode($bearer)."\r\n",
		'content' => 'grant_type=client_credentials',
      )
);
$context  = stream_context_create($opts);
$tw_token = file_get_contents('https://api.twitter.com/oauth2/token', false, $context);
die($tw_token);
*/

//You MUST create your own Twitter app and generate token with code above
$tw_token = '';
$logfile = "/var/www/log.txt";

function my_die($error) {
	global $logfile, $reqid;
	error_log("[".$reqid."] DYING: ".$error."\n", 3, $logfile);
	die($error);
}

function my_log($msg) {
	global $logfile, $reqid;
	error_log("[".$reqid."] ".$msg."\n", 3, $logfile);
//	echo($msg);
}

$reqid = rand (0, 10000);

my_log("URL: ".$_REQUEST["url"]);

if (!isset($_REQUEST['url'])) my_die('No URL at all');
$url = trim($_REQUEST['url']);

if (!isset($_REQUEST['token'])) my_die('No token');
$frf_token = trim($_REQUEST['token']);

if (!preg_match("/^https?\:\/\//", $url, $matches)) my_die('No http URL');


//============ TWITTER
if (preg_match("/twitter\.com\/.*?\/([\d]+)/", $url, $matches)) {
	my_log("Twitter found");

	$tw_id = $matches[1];

	$context  = stream_context_create(array('http' => array('header'  =>  "Authorization: Bearer ".$tw_token."\r\n")));
	$tw_json = file_get_contents('https://api.twitter.com/1.1/statuses/show.json?id='.$tw_id.'&tweet_mode=extended', false, $context); 

	$tw_array = json_decode($tw_json, true);
	/*
	id_str, full_text, in_reply_to_status_id_str
	extended_entities > media > 0..N > media_url, type (photo, video, animated_gif)
	retweeted_status
	user > id, screen_name

	Правила:
	+ 0. проверить, что json ок и непуст. проверить что in_reply_to_user_id_str пуст
	+ 1. если retweeted_status cуществует, то брать все следуюшие параметры из retweeted_status > имя
	+ 2. если extended_entities > media > 0 > type нет, это не медиатвит, иначе
	+ 3. если extended_entities > media > 0 > type = photo, выкачать все фото, приложить к посту
	+ 4. если extended_entities > media > 0 > type != photo, то это твит с видео, обернуть все ссылки, кроме последней, в !
	+ 5. если это retweet, то добавить в начало RT retweeted_status > user > screen_name и два перевода строк
	+ 6. если не было шага 4, то поставить в конце два перевода строк и ! + укороченную ссылку на https://twitter.com/ user > screen_name /status/ id_str
	*/

	echo "<pre>"; print_r($tw_array); 
	//die();
	$tweet_video = false;
	$text = "";

	if (isset($tw_array) && is_array($tw_array) && $tw_array["in_reply_to_user_id_str"] == "") {
		if (isset($tw_array["retweeted_status"]) && is_array($tw_array["retweeted_status"])) { 
			$tweet = $tw_array["retweeted_status"];
			$text = "RT @".$tweet["user"]["screen_name"].":\r\n\r\n";
		}
		else
			$tweet = $tw_array;
		if (isset($tweet["extended_entities"]["media"][0]["type"])) {
			if ($tweet["extended_entities"]["media"][0]["type"]=="photo") {
				$photos = Array();
				foreach ($tweet["extended_entities"]["media"] as $k => $v) {
					if ($v["type"] == "photo") $photos[] = $v["media_url"];
				}
			} else {
				$tweet_video = true;
			}
		}
		if (isset($tweet["entities"]["urls"]) && is_array($tweet["entities"]["urls"])) {
			foreach ($tweet["entities"]["urls"] as $k => $v) {
				$tweet["full_text"] = str_replace($v["url"],$v["expanded_url"],$tweet["full_text"]);
			}
		}
		if ($tweet_video) {
			$text .= preg_replace("/(https?\:\/\/.*)$/","\r\n\r\n(from $0)",preg_replace("/\!(https?\:\/\/.*)$/", "$1", preg_replace("/https?\:\/\//", "!$0",trim($tweet["full_text"]))));
		} else if (is_array($photos)) {
			$text = preg_replace("/(https?\:\/\/.*)$/","\r\n\r\n(from $0)",trim($tweet["full_text"]));
		} else {
			$text .= $tweet["full_text"]."\r\n\r\n(from !https://twitter.com/".$tw_array["user"]["screen_name"]."/status/".$tw_array["id_str"].")";
		}
	}

	// ============= post to freefeed

	$body = array( "title" => $text, );
	if (isset($photos) && is_array($photos)) $body ["images"] = $photos;

	$opts = array('http' =>
		array(
			'method'  => 'POST',
			'header'  => "Content-Type: application/json; charset=utf-8\r\n".
				"X-Authentication-Token: ".$frf_token."\r\n",
			'content' => json_encode($body),
			'timeout' => 60
		)
	);

	$context  = stream_context_create($opts);
	$result = file_get_contents('https://freefeed.net/v1/bookmarklet', false, $context);

	my_log("Result: ".substr($result,0,10)."\n");
	die($result);
}

// =================== INSTAGRAM

else if (preg_match("/instagram\.com\//", $url, $matches)) {

	my_log("Instagram found");

	//TODO проверить что возвращает strpos
	if (strpos($url, "?")!=false) $ig_urlj = $url."&__a=1";
	else $ig_urlj = $url."?__a=1";

	$opts = array('http' =>
		array(
			'header'  => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/66.0.3359.158 Safari/537.36\r\n",
		)
	);
	$context  = stream_context_create($opts);
	$ig_json = file_get_contents($ig_urlj, false, $context);

	$ig_array = json_decode($ig_json,true);

	echo "<pre>"; print_r($ig_array); echo "<hr>";
	$ig_video = false;

	if ($ig_array["graphql"]["shortcode_media"]["__typename"] == "GraphVideo")
		$ig_video = true;
	else if ($ig_array["graphql"]["shortcode_media"]["__typename"] == "GraphSidecar") {
		$photos = Array();
		foreach ($ig_array["graphql"]["shortcode_media"]["edge_sidecar_to_children"]["edges"] as $k => $v) {
			$width = 0;
		
			foreach ($v["node"]["display_resources"] as $kk=>$vv) {
				if ($v["node"]["__typename"] == "GraphVideo") $ig_video = true;
				if ($vv["config_width"]>$width) {
					$width = $vv["config_width"];
					$pic_url = $vv["src"];
				}	
			}
			$photos[] = $pic_url;
		}
	
	} else if ($ig_array["graphql"]["shortcode_media"]["__typename"] == "GraphImage") {
		$width = 0;

		foreach ($ig_array["graphql"]["shortcode_media"]["display_resources"] as $k=>$v) {
			if ($v["config_width"]>$width) {
				$width = $v["config_width"];
				$height = $v["config_height"];
				$photo = $v["src"];
			}	
		}
	} else {
		my_log("NEW TYPE!!! ".$ig_array["graphql"]["shortcode_media"]["__typename"]." at url ".$url;
	}

	if ($ig_video) $ig_text_url = $url.' (+ Video)';
	else $ig_text_url = $url;

	if (isset($ig_array["graphql"]["shortcode_media"]["edge_media_to_caption"]["edges"][0]["node"]["text"])) {
		$ig_text = $ig_array["graphql"]["shortcode_media"]["edge_media_to_caption"]["edges"][0]["node"]["text"];
		$ig_comment = "from ".$ig_text_url."";
	}
	else 
		$ig_text = $ig_text_url;

	if ($ig_video) {
		$ig_text .= "\r\n\r\n".$ig_comment;
		unset($ig_comment);
	}
	// ============= post to freefeed

	$body = array( "title" => $ig_text, );
	if (isset($photos) && is_array($photos)) $body ["images"] = $photos;
	if (isset($photo)) $body ["image"] = $photo;
	if (isset($ig_comment)) $body ["comment"] = $ig_comment;
	
	print_r($body);

	$opts = array('http' =>
		array(
			'method'  => 'POST',
			'header'  => "Content-Type: application/json; charset=utf-8\r\n".
				"X-Authentication-Token: ".$frf_token."\r\n",
			'content' => json_encode($body),
			'timeout' => 60
		)
	);

	$context  = stream_context_create($opts);
	$result = file_get_contents('https://freefeed.net/v1/bookmarklet', false, $context);

	my_log("Result: ".substr($result,0,10));
	die($result);
}
else {
  my_die("Strange URL: ".$url);
}

my_die("I understand nothing: ".$url);

?>
