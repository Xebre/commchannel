
var channelClient_url = 'channel.php';
var channelClient = function(channel, clientid, base_url)
{
	var self = this;

	self.onmessage = function(msg){console.log('>> Received message on channel ' + channel, msg);};
	
	if(typeof base_url === 'undefined')
            base_url = channelClient_url;

        /**
         * Get the channel ID we're listening on
         */
        self.getChannelID = function()
        {
            return channel;
        }

        /**
         * Find our public IP address by asking the server
         */
        self.getIP = function(cb)
        {
            self.GET(base_url + '?getIP=1', cb);
        }

	/**
	 * Set up polling
	 * 
	 * delay defaults to 500ms
	 */
        self.pollTimeout = false;
	self.startPolling = function(delay)
	{
		console.log('Start polling channel ' + channel);
		
		if(typeof delay == 'undefined')
			delay = 500;
		
		var cb;
		
		cb = function()
		{
			self.pollTimeout = window.setTimeout(function(){self.poll(cb);}, delay);
		}
		
		self.poll(cb);
	}
	
        
        /**
         * Stop polling
         */
        self.stopPolling = function()
        {
            if(self.pollTimeout !== false)
                window.cancelTimeout(self.pollTimeout);
        }
        
        /**
	 * Check for new messages on the server
	 */
	self.poll = function(cb)
	{
		if(typeof cb === 'undefined')
			cb = function(){};
	
                var url = base_url + '?channelid=' + channel + '&clientid=' + clientid;
                        
		self.GET(url, cb);
	}
        
        self.GET = function(url, cb)
        {
                var xhr = new XMLHttpRequest();
		xhr.open('GET', url, true);

		xhr.onreadystatechange = function()
		{
			if(xhr.readyState == 4)
			{
				handle(eval(xhr.responseText));
				cb();
			}
		};

		xhr.send(null);
        }
	

	/**
	 * Handle messages from the server
	 */
	var handle = function(messages)
	{
		for(i in messages)
		{
			var m = JSON.parse(messages[i]);
			
			// Skip our own messages!
			if(m.from !== clientid)
			{
				self.onmessage(m);
				
				if(typeof self.handlers[m.type] == 'undefined')
					console.log("No handler for " + m.type + " on channel " + channel, m);
				else
					self.handlers[m.type](m.payload);
			}
		}
	}


	/**
	 * Register a callback for a received message type
	 * 
	 * Callbacks take two args:
	 * 		1: The message object itself
	 * 		2: A callback that can be used to send a reply
	 */
	self.handlers = {};
	self.register = function(type, callback)
	{
		console.debug("Register handler for " + type + " on channel " + channel);
		
		self.handlers[type] = callback;
	}


	/**
	 * Send a message to the channel
	 * All other clients subscribed to the channel will receive it
	 */
	self.send = function(type, payload)
	{
		var m = {'type': type, 'payload': payload, 'from': clientid};
		
		var j = JSON.stringify(m);
		
		console.log("<< Send message on channel " + channel, m);
		
		var xhr = new XMLHttpRequest();
		xhr.open('POST', url, true);
		xhr.send(j);
	}

}