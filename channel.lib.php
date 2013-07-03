<?php

/**
 * Provide a multi-way comms channel between clients
 *
 * Stored in something that implements commStore
 */

/**
 * A commStore does the job of storing messages somehow.  It just needs to give out IDs
 * store messages and delete messages when told to do so.
 * @author richard
 */
interface CommStore
{
	/**
	 * Get a list of all IDs in the store
	 * Must return IDs in order, from most recent [0] to least recent
	 */
	public function getIDs();

	/**
	 * Add (or overwrite) a message to the store, with the given ID
	 *
	 * @param unknown_type $id
	 * @param unknown_type $msg
	 */
	public function addMsg($id, $msg);

	/**
	 * Delete a message from the store
	 * @param unknown_type $id
	 */
	public function delMsg($id);
}

class MemcacheCommStore implements CommStore
{
	private $ids = array();
	private $lastid = 0;

	private $messages;

	private $channelID;
	private $server;

	public function __construct($host, $port, $channelID)
	{
		$this->host = $host;
		$this->port = $port;
		$this->reconnect();
		
		$this->channelID = $channelID;

		$res = $this->server->get('channel_'.$this->channelID);

		if($res === false)
		{
			//echo "Channel {$this->channelID} was not found in memcache - Created!<br />";
		}
	}
        
        public function getChannelID()
        {
            return $this->channelID;
        }
	
	public function reconnect()
	{
		$this->server = new Memcache();
		$this->server->addserver($this->host, $this->port);
	}
	
	public function getMemcache()
	{
		return $this->server;
	}

	private function fetch()
	{
		$res = $this->server->get('channel_'.$this->channelID);

		if($res === false)
		{
			$this->messages = array();
			$this->lastid = 0;
		}
		else
		{
			$res = json_decode($res, true);

			$this->messages = $res['messages'];
			$this->lastid = $res['lastid'];
		}
	}

	private function store($messages)
	{
		$this->server->set('channel_'.$this->channelID, json_encode(array('lastid'=>$this->lastid,'messages'=>$messages)), MEMCACHE_COMPRESSED, 30);
	}
        
        /**
         * Acquire a lock on the channel, for up to five seconds
         * 
         * It is unsafe to write to the channel (more correctly, to read and then write) unless
         * a single lock is held across the operation to make it atomic
         */
        private function getLock()
        {
            $lname = 'channel_lock_'.$this->channelID;
            
            while(!$this->server->add($lname, 1, false, 5))
            {
                usleep(100);
            }
        }

        /**
         * Release the channel lock so other clients can do things
         */
        function release_lock()
        {
            $this->server->delete('channel_lock_'.$this->channelID);
        }


	public function addMsg($id, $msg)
	{
            $this->getLock();

                $this->fetch();

                $this->messages[$id] = $msg;

                $this->store($this->messages);

            $this->release_lock();
	}

	public function delMsg($id)
	{
            $this->getLock();

                $this->fetch();

                unset($this->messages[$id]);

                $this->store($this->messages);

            $this->release_lock();
	}

	public function getMsg($id)
	{
		$this->fetch();

		if(!array_key_exists($id, $this->messages))
			return false;

		return $this->messages[$id];
	}

	public function getIDs()
	{
		$this->fetch();

		return array_reverse(array_keys($this->messages), false);
	}

}


class CommChannel
{
	/**
	 * Create an CommChannel object that is bound to the provided
	 * channel $backend and accessing as client $clientID
	 */
	public function __construct(commStore $backend, $clientID)
	{
		$this->store = $backend;

		$this->clientID = $clientID;
	}

	/**
	 * Read the overhead message for this client
	 */
	protected function getOverhead()
	{
		// Check if there is housekeeping information and if not then create it
		if(($overhead = $this->store->getMsg('_overhead_'.$this->clientID)) === false)
		{
			$ids = $this->store->getIDs(); // Get IDs from the store

			if(count($ids) < 1)
			{
				$lastid = false;
			}
			else
			{
				//var_Dump($ids);
				$lastid = $ids[0];
			}

			$this->store->addMsg('_overhead_'.$this->clientID, array('lastID'=>$lastid));

			$overhead = $this->store->getMsg('_overhead_'.$this->clientID);
		}

		return $overhead;
	}

	/**
	 * Get outstanding messages for this client
	 * AND mark those messages as read
	 */
	public function getMessages()
	{
		// Get list of all IDs

		// Skip messages up to the last ID sent to the client
		// and return the rest
		$out = array();

		$oh = $this->getOverhead();
		$last = $oh['lastID'];

		if($last === false)
			$output = true;
		else
			$output = false;


		$ids = array_reverse($this->store->getIDs());
		foreach($ids as $id)
		{
			// Update the overhead
			$oh['lastID'] = $id;
			
			// We use _ to indicate private/overhead messages
			// They shouldn't be output!				
			if($output && substr($id, 0, 1) !== '_')
			{
				$out[] = $this->store->getMsg($id);
			}
			
			// If this is the last seen message, output from the next one onwards
			if($id === $last)
				$output = true;
		}

		//echo count($ids)." messages in channel\n\n";

		// Update the overhead
		if($last !== $oh['lastID'])
                    $this->store->addMsg('_overhead_'.$this->clientID, $oh);

		return $out;
	}

	/**
	 * Purge messages that all clients have seen
	 * - Where do we get the list of clients - A shared overhead message?
	 * TODO!
	 * Also need to kill clients that haven't been seen in a while
	 */
	protected function purge()
	{

	}

	/**
	 * Send a message to other clients in this channel
	 */
	public function send($msg)
	{
		$this->store->addMsg(uniqid(), $msg);
	}


	/**
	 * Remove a client from the list
	 */
	public function removeClient()
	{
		$this->store->delMsg('_overhead_'.$this->clientID);
	}

	/**
	 * Show debug info
	 */
	public function debug()
	{
		echo "<table>";
		
		$ids = $this->store->getIDs();
		
		var_Dump($ids);
		
		foreach($ids as $id)
		{
			$msg = $out[] = $this->store->getMsg($id);
			
			echo "<tr><td>$id</td><td>";
			var_Dump($msg);
			echo "</td></tr>";
		}
		
		echo "</table>";
	}
	
	public function getBackend()
	{
		return $this->store;
	}
        
        public function getClientID()
        {
            return $this->clientID;
        }
}

class JSCommChannel extends CommChannel
{
    public function send($type, $msg)
    {
        parent::send(json_encode(array('type'=>$type, 'msg'=>$msg, 'from'=>$this->getClientID())));
    }
    
    public function getMessages()
    {
        $msgs = parent::getMessages();
        
        foreach($msgs as $id=>$m)
        {
            $msgs[$id] = json_decode($m, true);
        }
        
        return  $msgs;
    }
}

/**
 * Extend CommChannel to send messages using PHP serialization
 * This allows eg PHP objects to be sent
 * 
 * Also implements message-reply linkage - When a message is sent, an ID is
 * assigned and returned.  A reply can be sent using the reply($id, $msg) method,
 * and the original sender can keep checking hasReply($id) to see if a reply has
 * been received 
 */
class NativeCommChannel extends CommChannel
{
	private $messages = array();
	private $replies = array();
	
	/**
	 * Send a message - The assigned ID will be returned so that replies can be checked for
	 */
	public function send($msg, $type='message')
	{
		$this->dosend($id = uniqid(), $type, $msg);
                
                return $id;
	}
	
	/**
	 * Send a reply to a message
	 */
	public function reply($id, $msg)
	{
		$this->dosend($id, 'reply', $msg);
	}
        
        /**
         * Send a message and wait for a reply
         */
        public function sendAndWait($msg, $type='message', $reply_type='reply', $timeout='10')
        {
            $id = $this->send($msg, $type);
            
            $start = time();
            do
            {
                $reply = $this->getMessage($id, $reply_type);
                
                if($start + $timeout < time())
                {
                    tlog("Timeout waiting for reply to $id of type $reply_type on comm channel", 'WARN');

                    break;
                }
                    
                sleep(1);
            }
            while($reply===false);
            
            return $reply;
        }
	
	/**
	 * Low-level message sending - Internal use only
	 */
	private function dosend($id, $type, $payload)
	{
		parent::send(serialize(array('type'=>$type, 'id'=>$id, 'payload'=>$payload)));
	}

	/**
	 * Get all outstanding messages of the given type
	 */
	public function getMessages($type='message')
	{
		$this->checkMessages();
		
                if(!array_key_exists($type, $this->messages))
                        return array();
                
		$messages = $this->messages[$type];
		
		$this->messages[$type] = array();
		
		return $messages;
	}
        
        public function getMessageCache()
        {
            return $this->messages;
        }
        
        /**
         * Get a particular message
         * 
         * If $type is set, only messages of that type are searched (allowing, eg, replies to be distinguished from messages)
         */
        public function getMessage($id, $type=false)
        {
            $this->checkMessages();
            
            if($type === false)
            {
                foreach($this->messages as  $type=>&$msgs)
                {
                    if(array_key_exists($id, $msgs))
                    {
                        return $msgs[$id];
                    }
                }
            }
            else
            {
                return array_key_exists($type, $this->messages) && array_key_exists($id, $this->messages[$type]) 
                        ? $this->messages[$type][$id] 
                        : false;
            }
        }
	
	/**
	 * Check for messages and replies, store them in the buffers
	 */
	private function checkMessages()
	{
		$msgs = parent::getMessages();
		
		foreach($msgs as $id=>$m)
		{
			$m = unserialize($m);
			
			$this->messages[$m['type']][$m['id']] = $m['payload'];
		}
	}
}




?>