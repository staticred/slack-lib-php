<?php

require_once("config.php");

    class SlackAPI {

        public $app_id; // Slack app ID
        public $app_user_id; // App User ID (for WTA)
        public $installer_user_id; // User ID of the person who installed the app. 
        public $access_token; // Authentication token returned from oAuth.
        public $error; // Holding spot for any errors.
        public $scope = array();
        public $state; // unique string to be passed back by oAuth after completion [optional]
        public $teamid; // Slack team ID [optional]
        public $teamname;
        public $userid; // Slack user ID
        public $channelid; // Slack channel ID
        public $webhookurl; // webhook URL
        public $response_url;
        public $trigger_id;
        public $last_ts;


        // Temporary data stores
        public $result; // array of JSON results.
        public $webhook; // webhook object
        public $bot; // bot object

        private $secret = ""; // set by $cfg->clientsecret
        private $clientid = ""; // set by $cfg->clientid

        function __construct() {
            global $cfg;
            // Grab the client ID and secret out of config.
            $this->clientid = $cfg->clientid;
            $this->secret = $cfg->clientsecret;
            $this->redirecturi = $cfg->redirecturl;
            // Set up scopes definitions.
            $this->scopes();

        }

        /**
          * Builds an array of scopes this app needs. Writes to $this->scopes,
          * which is a private attribute used later down the road.
          *
          * We're doing a lot, so we need a lot!
          */
        private function scopes() {

      	  global $cfg;

          if (is_array($cfg->scopes) && sizeof($cfg->scopes) > 0) {
            $this->scope = implode(',', $cfg->scopes);
          } else {

            $scopes = array(
                'incoming-webhook',
                'commands',
                'bot',
                'channels:read',
                'channels:history',
                'channels:write',
                'chat:write:bot',
                'chat:write:user',
                'emoji:read',
                'im:write',
                'im:history',
                'reactions:read',
                'reactions:write',
                'team:read',
                'users:read',
                'users.profile:read',
                );

            $this->scope = implode(',', $scopes);
          }
        }


/**
  * +---------------------------------------+
  * | Token-related functions               |
  * +---------------------------------------+
  */

  /**
    * get_token() - retrieves a new token based on a temporary access code.
    *
    * @param string $code
    *   The temporary oAuth code provided from Slack's oAuth provider. This can
    *   be exchanged after authentication for an access token, which is
    *   permanent (store it safely!)
    *
    * @return bool
    *
    */

        public function get_token($code = "") {
          global $cfg;

             $method = "oauth.access";

            $payload = array(
              'code' => $code,
            );

            // Make the API request.
            if ($result = $this->apicall($method, $payload)) {
                            
              $this->app_id = $result['app_id'];
              $this->app_user_id = $result['app_user_id'];
              $this->installer_user_id = $result['installer_user']['user_id'];
              $this->access_token = $result['access_token'];
              $this->userid = $result['user_id'];
              $this->teamid = $result['team_id'];
              $this->teamname = $result['team_name'];
              $this->webhook->channel_id = $result['incoming_webhook']['channel_id'];
              $this->webhook->channel = $result['incoming_webhook']['channel'];
              $this->webhook->configuration_url = $result['incoming_webhook']['configuration_url'];
              $this->webhook->url = $result['incoming_webhook']['url'];
              $this->bot->user_id = $result['bot']['bot_user_id'];
              $this->bot->access_token = $result['bot']['bot_access_token'];

              // write to file (for now; DB later)
              if ($this->store_token()) {
                return TRUE;
              }
            }
          return FALSE;
        }

        /**
          * load_token() - loads a token from the token store. In order for this to work,
          *                $this->userid must be set. How we're going to do this in the future,
          *                is anyone's guess. Though I bet someoneo knows.
          *
          * @param none
          *
          * @return bool
          *
          */
        public function load_token() {
          global $cfg;

          $db = new PDO("mysql:host={$cfg->db_host};dbname={$cfg->db_name}", $cfg->db_user, $cfg->db_password);
          

          // look for an existing token

          if (isset($cfg->oauth_token)) {
            $this->access_token = $cfg->oauth_token;
            return TRUE;
          }

          $stmt = $db->prepare("SELECT * FROM tokenstore WHERE teamid = ?");
          $stmt->bindParam(1, $this->teamid);

          if ($stmt->execute()) {
            $token = $stmt->fetchAll();
            $this->access_token = openssl_decrypt($token[0]['oauth_token'], $cfg->enctype, $cfg->salt, $cfg->salt);
            $this->app_id = $token[0]['app_id'];
            $this->app_user_id = $token[0]['app_user_id'];
            $this->installer_user_id = $token[0]['installer_user_id'];
            
            return TRUE;            
          }

          return FALSE;


        }

        /**
          * store_token() - stores a token and associated information
          *
          * @param none
          *
          * @return bool
          */
        private function store_token() {
          global $cfg;

          $db = new PDO("mysql:host={$cfg->db_host};dbname={$cfg->db_name}", $cfg->db_user, $cfg->db_password);


          if (!isset($this->teamid)) {
            return FALSE;
          }

          $record = [
            'teamid' => $this->teamid,
            'app_id' => $this->app_id,
            'app_user_id' => $this->app_user_id,
            'installer_user_id' => $this->installer_user_id,
            'oauth_token' => openssl_encrypt($this->access_token, $cfg->enctype, $cfg->salt, $cfg->salt),
          ];


          $tokenstore = $this->load_token();
          if ($this->access_token <> "") {
            $encrypted_token = openssl_encrypt($this->access_token, $cfg->enctype, $cfg->salt, $cfg->salt);
            $stmt = $db->prepare("UPDATE tokenstore SET teamid = ?, app_id = ?, app_user_id = ?, installer_user_id = ?, oauth_token = ? WHERE teamid = ? AND installer_user_id = ?");
            $stmt->bindParam(1, $this->teamid);
            $stmt->bindParam(2, $this->result['app_id']);
            $stmt->bindParam(3, $this->result['app_user_id']);
            $stmt->bindParam(4, $this->result['installer_user_id']);
            $stmt->bindParam(5, $encrypted_token);
            $stmt->bindParam(6, $this->teamid);
            $stmt->bindParam(7, $this->userid);
          } else {
  
            $encrypted_token = openssl_encrypt($this->result['access_token'], $cfg->enctype, $cfg->salt, $cfg->salt);
            $stmt = $db->prepare("INSERT INTO tokenstore SET teamid = ?, app_id = ?, app_user_id = ?, installer_user_id = ?, oauth_token = ?");
            $stmt->bindParam(1, $record['teamid']);
            $stmt->bindParam(2, $record['app_id']);
            $stmt->bindParam(3, $record['app_user_id']);
            $stmt->bindParam(4, $record['installer_user_id']);
            $stmt->bindParam(5, $encrypted_token);

          }
          
          if ($stmt->execute()) {
            return TRUE;
          } else {
            print_r($stmt->errorInfo());
          }

            return FALSE;

        }


        /**
          * apicall() [private]
          *
          * Makes a request to the API
          *
          * @param string $method
          *   The method to send to the API
          * @param array $payload
          *   The payload to send to the API.
          *
          *
          * @return mixed
          *    Returns false on failure (sets $this->error), JSON data package
          *    on success.
          */
        private function apicall($method, $payload = null, $bot=FALSE) {
          // we need to access configuration!
          global $cfg;

          switch ($method) {
            // oAuth authorization uses a separate endpoint URL. Let's account
            // for that.
            case 'authorize':
              $url = $cfg->oauthurl;
              break;

            default:
              $url = $cfg->apiurl;
              break;

          }


          if (!is_array($payload)) {
            error_log("No payload was passed, or payload is not an array");
            return false;
          }
          // let's add the clientid and secret to the payload. Pull those out
          // of config.php
          $payload['client_id'] = $cfg->clientid;
          $payload['client_secret'] = $cfg->clientsecret;
          // add the token
          if (isset($this->access_token) && $bot == FALSE) {
            $payload['token'] = $this->access_token;
          } else if (isset($this->bot) && $bot == TRUE) {
            $payload['token'] = $this->bot->access_token;
          }

          // add our payload passed through the function.
          $args = http_build_query($payload);

          // Build the full URL call to the API.
          $callurl = $url . $method . "?" . $args;

          // Let's build a cURL query.
        	$ch = curl_init($callurl);
        	curl_setopt($ch, CURLOPT_USERAGENT, $cfg->useragent);
        	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

          if (array_key_exists("filename", $payload)) {
            $callurl = $url . $method;
            $headers = array("Content-Type: multipart/form-data"); // cURL headers for file uploading
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
          }


          $ch_response = curl_exec($ch);
          $errors = curl_error($ch);
          if ($errors) {
            $this->debug("curl_errors.json", json_encode($errors));
          }
        	curl_close($ch);

          $return = json_decode($ch_response, TRUE);

          $this->debug("api-debug.txt", $ch_response);
          

        	$this->result = json_decode($ch_response, TRUE);
          return $this->result;


        }


      public function debug($filename="slackapi_debug.log", $message) {
        global $cfg;
        if ($cfg->debug == FALSE) {
          return false;
        }
        if (is_array($message)) {
          $message = json_encode($message);
        }
        $ts = date("Y-M-d h:m:s");
        $debugtxt = "{$ts}: {$message}\n";
        file_put_contents($filename, $debugtxt, FILE_APPEND);
      }

/**
  * +-------------------------+
  * | Channel-related methods |
  * +-------------------------+
  */

        /**
          * list_channels() - get a list of public channels
          *
          * @param bool $exclude_archived
          *
          * @return mixed
          *   Returns array of channels, or false
          *
          */
        public function list_channels($exclude_archived=true) {

          $method="channels.list";
          $payload['exclude_archived'] = $exclude_archived;

          $result = $this->apicall($method, $payload);
          if (isset($result['channels'])) {
            return $result['channels'];
          } else {
            return false;
          }
        }


        /**
          * channel_history() - get a list of public channels
          *
          * @param bool $channel
          *   Channel ID to get history for. [Required]
          * @param float $latest
          *   End of time range of messages to include in results. (default 0)
          * @param float $oldest
          *   Start of time range of messages to include in results. (default 0)
          * @param bool $inclusive
          *   Include messages with latest or oldest time stamps in results (default 0)
          * @param int $count
          *   Number of messages to return, between 1 and 1000 (default 100)
          * @param bool $unreads
          *   Include unread_count_display in the output (default 0)
          *
          * @return mixed
          *   Returns array of channels, or false
          *
          */
        public function channel_history($channelid, $latest=false, $oldest=false, $inclusive=false, $count=100, $unreads=false) {
          $method = "channels.history";
          $payload = array(
            'channel' => $channelid,
            'count' => $count,
          );
  
          if ($latest) {
            $payload['latest'] = date("U", strtotime($latest));
          }          
          
          if ($oldest) { 
            $payload['oldest'] = date("U", strtotime($oldest));
          }
          
          if ($inclusive) {
            $payload['inclusive'] = $inclusive;
          }
          
          if ($unreads) {
            $payload['unreads'] = $unreads;
          }


          return $this->apicall($method, $payload);

        }



        /**
         * conversation_history function.
         * 
         * @access public
         * @param mixed $channelid
         * @param string $latest (default: '')
         * @param string $oldest (default: '')
         * @param bool $cursor (default: false)
         * @param int $inclusive (default: 0)
         * @param int $count (default: 100)
         * @param int $unreads (default: 0)
         * @return void
         */
        public function conversation_history($channelid, $latest='', $oldest='', $cursor=false, $inclusive=0, $count=100, $unreads=0) {
          $method = "channels.history";
          $payload = array(
            'channel' => $channelid,
            'latest' => $latest,
            'oldest' => $oldest,
            'unreads' => $unreads,
          );

          if ($inclusive) {
            $payload['inclusive'] = $inclusive;
          }
          
          if ($count) {
            $payload['count'] = $count;
          }
          
          if ($cursor) {
            $payload['cursor'] = $cursor;
          }

          return $this->apicall($method, $payload);

        }

        function setTopic($channel, $topic, $bot=FALSE) {
          global $cfg;

          $method = "channels.setTopic";
          $payload = [
            'channel' => $channel,
            'topic' => $topic,
          ];
          if ($bot) {
            $this->bot->access_token = "$cfg->bot_token";
            $bot = TRUE;
          } else {
            $bot = FALSE;
          }
          return $this->apicall($method, $payload, $bot);
        }



        /**
          * group_history() - get a list of public channels
          *
          * @param bool $channel
          *   Channel ID to get history for. [Required]
          * @param float $latest
          *   End of time range of messages to include in results. (default 0)
          * @param float $oldest
          *   Start of time range of messages to include in results. (default 0)
          * @param bool $inclusive
          *   Include messages with latest or oldest time stamps in results (default 0)
          * @param int $count
          *   Number of messages to return, between 1 and 1000 (default 100)
          * @param bool $unreads
          *   Include unread_count_display in the output (default 0)
          *
          * @return mixed
          *   Returns array of channels, or false
          *
          */
        public function group_history($channelid, $latest='', $oldest='', $inclusive=0, $count=100, $unreads=0) {
          $method = "groups.history";
          $payload = array(
            'channel' => $channelid,
            'latest' => $latest,
            'oldest' => $oldest,
            'inclusive' => $inclusive,
            'count' => $count,
            'unreads' => $unreads,
          );

          return $this->apicall($method, $payload);

        }



        public function join_channel($channel) {
          $method = "channel.join";

        }

        /**
          * get_threads()
          * @author @darren
          *
          * This is an UNDOCUMENTED API CALL to get a list of threads
          * for an array of channels. I'm writing this because I'm annoyed at
          * the lack of thread management, and I'm letting off steam.
          *
          * @param $channels array
          *   An array of channel IDs to check.
          *
          * @return array
          *   Returns an array of thread_ts keyed by channel
          */

        public function get_threads($channels) {
          $method = "subscriptions.thread.get";
          $return = array();
          foreach ($channels as $channel) {
            $payload = array(
              'channel' => $channel['id'],
            );
            $result = $this->apicall($method, $payload);
            var_dump($result);
          }
        }


/**
  * +-------------------------+
  * | File-related methods    |
  * +-------------------------+
  */

  /**
    * upload_file()
    *   Uploads a file to somewhere.
    *
    * @param $filename
    *   Local path to the file to upload
    *
    * @return bool
    *   Returns true/false on success
    */
  public function upload_file($filename, $filepath, $title="Uploaded file.", $initial_comment="", $channels=null, $type="auto") {

    // check that the file exists
    if (!file_exists($filepath)) {
      error_log("SlackAPI::file_upload(): File does not exist: " . $filepath);
      return false;
    }

    // prepare the file for upload
    if (function_exists('curl_file_create')) { // php 5.6+
      $cFile = curl_file_create($filepath);
    } else { //
      $cFile = '@' . realpath($filepath);
    }
    
    if ( ! function_exists ( 'mime_content_type ' ) )
    {
       function mime_content_type ( $f )
       {
           return trim ( exec ('file -bi ' . escapeshellarg ( $f ) ) ) ;
       }
    }

    $mimetype = mime_content_type($filepath);
    $file = new CurlFile($filepath, $mimetype);
    

    $method = "files.upload";
    $filetype = "auto";

    $params = array(
      'filename' => $filename,
      'title' => $title,
//      'file' => $cFile,
      'filetype' => 'auto',
      'channels' => $channels,
    );

    $result = $this->apicall($method, $params);

    return $result;

  }


    public function get_link($channel, $ts) {
      $method = "chat.getPermalink";
      $payload = [
        'channel' => $channel,
        'message_ts' => $ts,
      ];
            
      return $this->apicall($method, $payload);
    }



  /**
   * create_post function.
   *
   * @access public
   * @param mixed $title
   * @param mixed $content
   * @param mixed $channels
   * @return void
   */
  public function create_post($title, $content, $channels) {

    $method = "files.upload";
    $payload = [
      'channels' => implode(",", $channels),
      'contents' => $content,
      'file' => $content,
      'title' => $title,
      'filename' => $title,
    ];

    $result = $this->apicall($method, $payload);

    return $result;


  }

  /**
   * update_post function.
   *
   * @access public
   * @param mixed $fileid
   * @param mixed $title
   * @param mixed $content
   * @param mixed $channels
   * @return void
   */
  public function update_post($fileid, $title, $content, $channels) {

    $method = "files.upload";
    $payload = [
      'channels' => implode(",", $channels),
      'file' => $fileid,
      'contents' => $content,
      'title' => $title,
      'filename' => $title,
    ];

    $result = $this->apicall($method, $payload);

    return $result;


  }





/**
  * +-------------------------+
  * | IM-related methods      |
  * +-------------------------+
  */

      function addreaction($timestamp, $channel, $reaction) {


          // let's add a reaction to the message.
          $method = "reactions.add";
          $payload = array(
            'timestamp' => $timestamp,
            'channel' => $channel,
            'name' => $reaction,
          );



          $this->apicall($method, $payload);



      }


      function sendDialog($dialog) {

        $method = "dialog.open";
        $payload['trigger_id'] = $this->trigger_id;
        $payload['dialog'] = json_encode($dialog);

        $this->apicall($method, $payload);


      }


      function sendim($message, $attachments, $unfurl=FALSE) {
        global $cfg;
        $method = "im.open";
        $payload  = array(
          'user' => $this->userid,
        );

        $channel = $this->apicall($method, $payload);

        $channelid = $channel['channel']['id'];

        $method = "chat.postMessage";
        $payload = array(
          'user' => $this->userid,
          'channel' => $channelid,
          'text' => $message,
          'link_names' => 1,
          'username' => $cfg->botname,
        );
        
        if (is_array($attachments)) {
          $payload['attachments'] = $attachments;
        }

        if ($unfurl) {
          $payload['unfurl_links'] = "true";
        }

        $this->apicall($method, $payload);


      }


      function sendMessage($payload, $ephemeral=FALSE) {
        global $cfg;
        if($ephemeral) {
          $method = "chat.postEphemeral";
        } else {
          $method = "chat.postMessage";
        }
        if (!isset($payload['channel'])) {
          $payload['channel'] = $this->webhook->channel_id;
        }

        $result = $this->apicall($method, $payload);
        return $result;
        
      }

      function updateMessage($ts, $payload) {
        global $cfg;
        $method = "chat.update";
        $payload['ts'] = $ts;
        $result = $this->apicall($method, $payload);
      }

      function deleteMessage($payload) {
        global $cfg;
        $method = "chat.delete";
        $result = $this->apicall($method, $payload);
      }



      /**
        * sendWebhook()
        *   Sends a webhook message payload. The webhook URL should be set in
        *   $this->webhookurl before calling the function. Otherwise, it will
        *   return false.
        *
        * @param array $payload
        *   A message payload to send.
        *
        * @return bool
        *   Returns true/false on success
        */
      function sendWebhook($payload) {
        global $cfg;

        if (!isset($this->webhookurl) or empty($this->webhookurl)) {
          return false;
        }

        $json = json_encode($payload);

    		$curl = curl_init($this->webhookurl);
    		curl_setopt($curl, CURLOPT_HEADER, false);
    		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    		curl_setopt($curl, CURLOPT_HTTPHEADER,
          array("Content-type: application/json"));
    		curl_setopt($curl, CURLOPT_POST, true);
    		curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
    		$json_response = curl_exec($curl);
    		$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $json = json_decode($json_response);

        if ($status == 200) {
          return true;
        } else {
          error_log("An error occurred: " . $status . " - " . $json_response);
          return false;
        }




      }


/**
  * +-------------------------+
  * | Team-related methods    |
  * +-------------------------+
  */


    /**
      * team_info()
      *   Returns info for the connected team
      *
      * @param none
      *
      * @return mixed
      *   Returns false on error, object array of team details on success.
      */
    public function team_info() {

      $method="team.info";
      $payload = array();
      $team_details = $this->apicall($method, $payload);
      $this->debug("team-details.log", json_encode($team_details));
      return $team_details;

    }


/**
  * +-------------------------+
  * | User-related methods    |
  * +-------------------------+
  */

        public function user_reactions() {
          $method = 'reactions.list';
          $payload = array(
            'user' => $this->user,
            'full' => '1',
          );
          $result = $this->apicall($method, $payload);
          return $result['items'];

        }

        /**
          * search_by_username()
          *   Searches for a specific username
          *
          * @param string $username
          *   A username to search for
          *
          * @return array
          *   Returns an object array of user details;
          */
        public function search_by_username($username) {

          // first, get all users
          $method = "users.list";
          $payload = array();
          $result = $this->apicall($method, $payload);
          $users = $result['members'];

          $this->debug("userlist.json", json_encode($users));

          $key = array_search($username, array_column($users, 'name'));

          $this->debug("user.json", json_encode($users[$key]));

          return $users[$key];


        }


        public function lookup_user($userid) {

          $method="users.info";
          $payload = array(
            "user" => $userid,
          );

          $user_details = $this->apicall($method, $payload);
          $this->debug("user-details.log", json_encode($user_details));
          return $user_details['user'];
        }

        public function user_identity() {


          if (isset($this->access_token)) {
            $method = "users.profile.get";
            $payload['user'] = $this->userid;

            // send the API request to get the user's identit.
            $result = $this->apicall($method, $payload);

            $this->debug("user-profile.log", json_encode($result));

            return $result['profile'];

            // write to DB / file.

          }
        }

        public function statuschange($status, $user=null) {
          $method = "users.profile.set";
          $payload['profile'] = json_encode($status);

	  if (isset($user)) {
	    $payload['user'] = $user;
          }
          if ($result = $this->apicall($method, $payload)) {
            return true;
          }

          return false;

        }

        public function getstatus() {
          $method = "users.profile.get";
          $payload = array();

          if ($result = $this->apicall($method, $payload)) {

            $return = array(
              'status_text' => $result['profile']['status_text'],
              'status_emoji' => $result['profile']['status_emoji'],
            );

            return $return;

          }
        }

      /** Link Unfurl methods */

        public function unfurl($payload) {
          $method = "chat.unfurl";

          $result = $this->apicall($method, $payload);
//          $this->debug("app-unfurl.log", json_encode($result));

          return $result;

        }

    }
