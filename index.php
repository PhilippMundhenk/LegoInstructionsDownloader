<?php

use JonnyW\PhantomJs\Client;

$client = Client::getInstance();

/** 
 * @see JonnyW\PhantomJs\Http\Request
 **/
$request = $client->getMessageFactory()->createRequest('https://www.lego.com/de-de/service/buildinginstructions/7292', 'GET');

/** 
 * @see JonnyW\PhantomJs\Http\Response 
 **/
$response = $client->getMessageFactory()->createResponse();

// Send the request
$client->send($request, $response);

if($response->getStatus() === 200) {

	// Dump the requested page content
	echo $response->getContent();
}

?>