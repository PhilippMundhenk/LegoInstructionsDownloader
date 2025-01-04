<?php

function getApiKey($set_id, $locale) {
	$curl_session = curl_init(); 
	curl_setopt($curl_session, CURLOPT_URL, "https://www.lego.com/$locale/service/buildinginstructions/$set_id");
	curl_setopt($curl_session, CURLOPT_RETURNTRANSFER, true);
	$result = curl_exec($curl_session);
	preg_match('/https:\/\/www.lego.com\/service\/dist\/scripts.min.min..*.js/', $result, $matches);
	$script = $matches[0][0];
	print_r($matches);
	
	curl_setopt($curl_session, CURLOPT_URL, $script);
	curl_setopt($curl_session, CURLOPT_RETURNTRANSFER, true);
	$result = curl_exec($curl_session);
	
	preg_match('/{headers:{"x-api-key":".*"}};/', $result, $matches);
	$key = str_replace('{headers:{"x-api-key":"', "", $matches[0][0]);
	$key = str_replace('"}};', "", $result);
	
	curl_close($curl_session);
	
	return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	$set_id=$_GET['set_id'];
	$locale=$_GET['locale'];
	
	$key=getApiKey($set_id, $locale);
}
?>