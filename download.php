<?php

function getApiKey($set_id, $locale) {
	$curl_session = curl_init(); 
	curl_setopt($curl_session, CURLOPT_URL, "https://www.lego.com/$locale/service/buildinginstructions/$set_id");
	$result = curl_exec($curl_session);
	print("result:");
	print("-----------------------------------------------------------------------------------------");
	print("$result");
	print("-----------------------------------------------------------------------------------------");
	
	preg_match_all('/https://.*scripts.min.min.*.js/', $result, $matches);
	$script = matches[0][0];
	
	print("script:");
	print("-----------------------------------------------------------------------------------------");
	print("$script");
	print("-----------------------------------------------------------------------------------------");
	curl_setopt($curl_session, CURLOPT_URL, $script);
	$result = curl_exec($curl_session);
	
	print("result:");
	print("-----------------------------------------------------------------------------------------");
	print("$result");
	print("-----------------------------------------------------------------------------------------");
	preg_match_all('/{headers:{"x-api-key":".*"}};/', $result, $matches);
	$key = str_replace('{headers:{"x-api-key":"', "", $matches[0][0]);
	$key = str_replace('"}};', "", $result);
	
	print("key:");
	print("-----------------------------------------------------------------------------------------");
	print("$key");
	print("-----------------------------------------------------------------------------------------");
	
	curl_close($curl_session);
	
	return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	$set_id=$_GET['set_id'];
	$locale=$_GET['locale'];
	
	$key=getApiKey($set_id, $locale);
}
?>