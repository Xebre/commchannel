<?php 

require 'channel.lib.php';

$s = new Memcache();

$s->connect('127.0.0.1', '11211');

$cid = $_GET['channelid'];
$clientid = "INSPECTOR";

// Bind to the requested channel
// TODO: Some sort of security?!
$channel = new CommChannel(new MemcacheCommStore($s, $cid), $clientid);

//var_Dump($channel);

$channel->debug();


?>