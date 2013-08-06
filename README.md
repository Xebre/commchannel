CommChannel
===========

PHP/JS library for MQ-like IPC, locally or over HTTP


Intro
-----

CommChannel provides a "communication channel" between two PHP processes.  Two or more clients create connections to a CommStore, and a message sent by one client is delivered to all of the others.

The implementation is split into two parts:
1. The CommChannel class itself
2. CommChannel storage backends (implementing the CommStore interface) that actually store the messages.  A backend based on memcached (using the memcache extension) is currently implemented.

If you require "channels" (ie, multiple conversations using the same storage backend) - you probably do - that needs to be implemented in the CommStore backend.  The memcache implementation provides channels.

In addition, a javascript client is provided that connects to the comm channel over HTTP to a PHP helper script.


Usage (PHP)
-----------
```php

// Set up a connection to the storage backend. MemcacheCommStore implements channels, so multiple (private) 
// conversations can happen at the same time!
$channelID = uniqid(); // All the clients that want to talk will need to know the ChannelID somehow!
$backend = new MemcacheCommStore('127.0.0.1', '11211', $channelID);

// Create a communication channel on top of that backend
$clientid = uniqid('client_'); // All clients need a unique identifier so that they can be identified!
$channel = new CommChannel($backend, $clientid);

// Send a message
$channel->send("Some Message");

// Look for messages
$messages = $channel->getMessages();

```


Usage (Javascript)
------------------

Javascript support is implemented on top of the memcache CommStore.  It requires a helper script (channel.php)
and a javascript client is provided in channelclient.js

The javascript client introduces message types, so oeprates at a slightly higher level than the basic PHP class.  Callbacks can be registered to handle different message types.


```javascript

var channelID = 'testchannel'; // The name of the channel (clients, PHP or JS, need to use the same one if they want to talk to each other!)
var clientID = Math.round(Math.random() * 1000000); // Generate a (probably unique) client ID to identify ourselves
var channel = new channelClient(channelID, clientID, '/path/to/channel.php'); 


// If one client does this, the callback will be executed when...
channel.register('msg', function(m){alert(m.from + " said: " + m.payload);});

// ...another client does this
channel.send('msg', "Hello there!");

```

### JS Security
If the name of the channel begins with an IP address followed by an underscore, then channel.php will only allow READ access to it from that IP address.  This allows semi-private channels to be set up that other clients can't snoop on by guessing the channel name.  It still allows other JS clients to send messages to it, though.  Since this security feature is implemented in the PHP-JS helper script, it doesn't affect native PHP access to the channel.

### Interacting with PHP
The JS client sends JSON objects over the channel (including type, payload and from fields), but the default PHP CommChannel class only deals with strings.  Use JSCommChannel to provide an interface similar to the JS client (typed messages) and to take care of the JSON-ey stuff, like so:


```php
// Set up a connection to the storage backend. MemcacheCommStore implements channels, so multiple (private) 
// conversations can happen at the same time!
$channelID = uniqid(); // All the clients that want to talk will need to know the ChannelID somehow!
$backend = new MemcacheCommStore('127.0.0.1', '11211', $channelID);

// Create a communication channel on top of that backend
$clientid = uniqid('client_'); // All clients need a unique identifier so that they can be identified!
$channel = new JSCommChannel($backend, $clientid);

// Send a message with type "msg" and a friendly message as the payload
$channel->send("msg", "Hello javascript client!");

// Look for messages
// These are associative arrays of the form array(type=>"some_type_eg_msg", msg=>"payload!", from=>"client_id_of_sender")
$messages = $channel->getMessages();

```


NativeCommChannel
-----------------

Native CommChannel extends the basic commChannel to send native PHP objects (via internal serialization).  It also
introduces message "types" and replies.
