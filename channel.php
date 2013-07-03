<?php

// Handle IP requests
if(array_key_exists('getIP', $_GET))
{
    echo $_SERVER['REMOTE_ADDR'];
    exit;
}

require 'channel.lib.php';

$cid = $_GET['channelid'];
$clientid = $_GET['clientid'];

// Bind to the requested channel
// TODO: Some sort of security?!
$channel = new CommChannel('127.0.0.1', '11211', $cid, $clientid);


// Handle POSTed messages
if($_SERVER['REQUEST_METHOD'] == 'POST')
{
	$msg = file_get_contents('php://input');
	$channel->send($msg);

	//var_dump($msg);
}
else
{
    /**
    * If the channel name begins with an IP address followed by and underscore, then only allow
    * cleints from that IP address to read messages from it!
    */
    if(preg_match('@([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})_.*@', $cid, $matches))
    {
        $ip = $matches[1];
        
        if($_SERVER['REMOTE_ADDR'] != $ip)
        {
            echo "{err: \"Unauthorised client\"}";
            exit;
        }
    }
    
    // Get outstanding messages
    $msgs = $channel->getMessages();

    echo json_encode($msgs);
}

?>