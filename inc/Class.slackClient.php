<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

class slackClient
{
    private $connected = false;
    private $wssUrl = null;

    public $stream = null;
    public $messageId = 1;
    public $last_pong;
    public $users = null;
    public $channels = null;
    public $groups = null;
    public $ims = null;
    public $emoji = null;
    public $is_bot = null;

    public function __construct($baseUrl, $apiToken, $oauth2Token)
    {
        $this->postapi = new apiMethodPost($this, $baseUrl, $apiToken, $oauth2Token);
        $this->$baseUrl = $baseUrl;
        $this->token = $apiToken;
        $this->oauth2 = $oauth2Token;
    }
    public function __destruct()
    {
        $this->disconnect(0, "");
    }

    public function init() {
        $response=$this->postapi->rtm_start();
        $tmp_users=$response->users;
        foreach ($tmp_users as $userObj) {
            $this->users[$userObj->id]=$userObj;
        }
        $this->channels=$response->channels;
        $this->ims=$response->ims;
        $this->groups=$response->groups;
        $this->wssUrl=$response->url;
        $this->is_bot=false; //$this->users[$response->self->id]->is_bot;
    }

    public function connect()
    {
        $urlParts=parse_url($this->wssUrl);
        $host=$urlParts['host'];
        $port=443;
        $path=$urlParts['path'];
        $key = base64_encode($this->_generateRandomString(16, false, true));
        $header = "GET " . $path . " HTTP/1.1\r\n";
        $header .= "Host: " . $host . ":" . $port . "\r\n";
        $header .= "Upgrade: websocket\r\n";
        $header .= "Connection: Upgrade\r\n";
        $header .= "Sec-WebSocket-Key: " . $key . "\r\n";
        $header .= "Sec-WebSocket-Version: 13\r\n";
        $header .= "\r\n";
        $context = stream_context_create();
        stream_context_set_option($context, 'ssl', 'allow_self_signed', false);
        stream_context_set_option($context, 'ssl', 'verify_peer', false);
        $this->stream = stream_socket_client("tls://{$host}:{$port}", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
        if ($this->stream === FALSE) {
            throw new RuntimeException('Cannot connect to stream ([#' . $errno . '] ' . $errstr . ')');
        }
        stream_set_timeout($this->stream, 5);
        @fwrite($this->stream, $header);
        $response = @fgets($this->stream, 1500);
        $i = 0;
        while (!preg_match('#Sec-WebSocket-Accept:\s(.*)$#mU', $response, $matches)) {
            $response = @fgets($this->stream, 1500);
            $i++;
            if ($i > 5) {
                die("Could not upgrade connection");
            }
        }
        if ($matches) {
            $keyAccept = trim($matches[1]);
            $expectedResonse = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
            $this->connected=($keyAccept === $expectedResonse ? true : false);
        } else {
            // Something went wrong.
        }
        @fgets($this->stream, 2); // discard final \r\n
    }

    public function reconnect()
    {
        $this->connected = false;
        fclose($this->stream);
        $this->init();
        $this->connect();
    }
    public function disconnect($code, $reason)
    {
        $this->sendData($code . utf8_encode($reason), "close");
        sleep(3); // TODO: Actually handle response instead of ignoring and closing anyway
        $this->connected = false;
        is_resource($this->stream) and fclose($this->stream);
    }
    public function ping()
    {
        $postData["type"]="ping";
        // TODO: pcntl_alarm setup here
        $this->sendArray($postData);
    }

    public function readPacket() {
        $data = stream_get_line($this->stream, 2);
        $payloadLength = ord($data[1]) & 127;
        if ($payloadLength === 126) {
            $data.=stream_get_line($this->stream, 2);
            $payloadOffset = 0;
            $dataLength = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3]))) + $payloadOffset;
        } elseif ($payloadLength === 127) {
            $data.=stream_get_line($this->stream, 8);
            $payloadOffset = 14;
            $tmp = '';
            for ($i = 0; $i < 8; $i++) {
                $tmp .= sprintf('%08b', ord($data[$i + 2]));
            }
            $dataLength = bindec($tmp) + $payloadOffset;
            unset($tmp);
        } else {
            $payloadOffset = 0;
            $dataLength = $payloadLength + $payloadOffset;
        }
        if ($dataLength > 0) $data = stream_get_line($this->stream, $dataLength);
        return($this->updateFromResponse(json_decode($data)));
    }
    public function sendData($data, $type = 'text', $masked = true)
    {
        if (empty($data)) {
            return false;
        }
        $res = fwrite($this->stream, $this->_hybi10Encode($data, $type, $masked));
        return($res);
    }
    public function sendArray($array)
    {
        $this->messageId++;
        $array['id'] = $this->messageId;
        $this->sendData(json_encode($array));
        return ($this->messageId);
    }

    private function updateFromResponse($postResponse)
    {
        if (isset($postResponse->type)) {
            switch($postResponse->type) {
                case "channel_deleted":
                case "channel_archived":
                    unset($this->channels[$postResponse->channel->name]);
                    break;
                case "team_join":
                case "user_change":
                    $this->users[$postResponse->user->name]=$postResponse->user;
                    break;
                case "channel_created":
                    $this->channels[$postResponse->channel->name]=$postResponse->channel;
                    break;
                case "im.created":
                case "im.open":
                    $this->ims[$postResponse->channel->name]=$postResponse->ims;
                    break;
                case "group_joined":
                    $this->groups[$postResponse->group->name]=$postResponse->group;
                    break;
                case "group_left":
                    unset($this->groups[$postResponse->channel->name]);
                    break;
                case "group_closed":
                    unset($this->groups[$postResponse->channel]);
                    break;
                case "hello":
                    $this->connected=TRUE;
                    break;
                case "message":
                    // Verify that we have the channel and user objects, if not, request them.
                    break;
                case "bot_changed":
                case "bot_added":
                    // update?
                break;
                case "email_domain_changed":
                case "accounts_changed":
                case "team_domain_change":
                case "team_rename":
                case "team_pref_change":
                case "commands_changed":
                case "star_added":
                case "star_removed":
                case "pref_change":
                case "manual_presence_change":
                    // Ignore
                    break;


            }
        }
        return ($postResponse);
    }

    private function _generateRandomString($length = 10)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}';
        $useChars = array();
        for ($i = 0; $i < $length; $i++) {
            $useChars[] = $characters[mt_rand(0, strlen($characters) - 1)];
        }
        array_push($useChars, ' ', ' ', ' ', ' ', ' ', ' ');
        array_push($useChars, rand(0, 9), rand(0, 9), rand(0, 9));
        shuffle($useChars);
        $randomString = trim(implode('', $useChars));
        $randomString = substr($randomString, 0, $length);
        return $randomString;
    }
    private function _hybi10Encode($payload, $type = 'text')
    {
        $frameHead = array();
        $payloadLength = strlen($payload);
        switch ($type) {
            case 'text':
                // first byte indicates FIN, Text-Frame (10000001):
                $frameHead[0] = 129;
                break;
            case 'close':
                // first byte indicates FIN, Close Frame(10001000):
                $frameHead[0] = 136;
                break;
            case 'ping':
                // first byte indicates FIN, Ping frame (10001001):
                $frameHead[0] = 137;
                break;
            case 'pong':
                // first byte indicates FIN, Pong frame (10001010):
                $frameHead[0] = 138;
                break;
        }
        // set mask and payload length (using 1, 3 or 9 bytes)
        if ($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = 255;
            for ($i = 0; $i < 8; $i++) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }
            // most significant bit MUST be 0 (close connection if frame too big)
            if ($frameHead[2] > 127) {
                // TODO Handle dropping connection
                $this->connected = false;
                is_resource($this->stream) and fclose($this->stream);
                return false;
            }
        } elseif ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = 254;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = $payloadLength + 128;
        }
        // convert frame-head to string:
        foreach (array_keys($frameHead) as $i) {
            $frameHead[$i] = chr($frameHead[$i]);
        }
        // generate a random mask:
        $mask = array();
        for ($i = 0; $i < 4; $i++) {
            $mask[$i] = chr(rand(0, 255));
        }
        $frameHead = array_merge($frameHead, $mask);
        $frame = implode('', $frameHead);
        // append payload to frame:
        for ($i = 0; $i < $payloadLength; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }
        return $frame;
    }
}

class apiMethodPost {
    /*
     * For documentation on all POST methods, see https://api.slack.com/methods
     */

    public function __construct($parent, $baseUrl, $apiToken, $oauth2Token)
    {
        $this->parent = $parent;
        $this->baseUrl = $baseUrl;
        $this->token = $apiToken;
        $this->oauth2 = $oauth2Token;
    }

    public function api_test($error = NULL, $foo = NULL) {
        $postData=array();
        if ($error != NULL) $postData["error"]=$error;
        if ($foo != NULL) $postData["foo"]=$foo;
        return($this->post("api.test", $postData));
    }
    public function auth_test() {
        return($this->post("auth.test"));
    }

    public function channels_history($channel, $oldest=NULL, $latest=NULL, $count=NULL) {
        $postData["channel"]=$channel;
        if ($oldest != NULL) $postData["oldest"]=$oldest;
        if ($latest != NULL) $postData["latest"]=$latest;
        if ($count != NULL) $postData["count"]=$count;
        return($this->post("channels.history", $postData));
    }
    public function channels_info($channel) {
        $postData["channel"]=$channel;
        return($this->post("channels.info", $postData));
    }
    public function channels_list($exclude_archived=NULL) {
        $postData=array();
        if ($exclude_archived != NULL) $postData["exclude_archived"]=$exclude_archived;
        $postResponse=$this->post("channels.list", $postData);
        if ($postResponse->ok) $this->parent->channels=$postResponse->channels;
        return($postResponse);
    }
    public function channels_mark($channel, $ts) {
        $postData["channel"]=$channel;
        $postData["ts"]=$ts;
        return($this->post("channels.mark", $postData));
    }

    public function chat_delete($ts, $channel) {
        $postData["ts"]=$ts;
        $postData["channel"]=$channel;
        return($this->post("chat.delete", $postData));
    }
    public function chat_postMessage($channel, $text, $username=NULL, $parse=NULL, $link_names=NULL, $attachments=NULL, $unfurl_links=NULL, $unfurl_media=NULL, $icon_url=NULL, $icon_emoji=NULL) {
        $postData["channel"]=$channel;
        $postData["text"]=$text;
        if ($username != NULL) $postData["username"]=$username;
        if ($parse != NULL) $postData["parse"]=$parse;
        if ($link_names != NULL) $postData["link_names"]=$link_names;
        if ($attachments != NULL) $postData["attachments"]=$attachments;
        if ($unfurl_links != NULL) $postData["unfurl_links"]=$unfurl_links;
        if ($unfurl_media != NULL) $postData["unfurl_media"]=$unfurl_media;
        if ($icon_url != NULL) $postData["icon_url"]=$icon_url;
        if ($icon_emoji != NULL) $postData["icon_emoji"]=$icon_emoji;
        return ($this->post("chat.postMessage", $postData));
    }
    public function chat_update($ts, $channel, $text) {
        $postData["ts"]=$ts;
        $postData["channel"]=$channel;
        $postData["text"]=$text;
        return ($this->post("chat.update", $postData));
    }

    public function emoji_list() {
        $postResponse=$this->post("emoji.list", array());
        if ($postResponse->ok) $this->parent->emoji=$postResponse->emoji;
        return($postResponse);
    }

    public function groups_close($group) {
        $postData["channel"]=$group;
        return ($this->post("groups.close", $postData));
    }
    public function groups_history($group, $oldest=NULL, $latest=NULL, $count=NULL) {
        $postData["channel"]=$group;
        if ($oldest != NULL) $postData["oldest"]=$oldest;
        if ($latest != NULL) $postData["latest"]=$latest;
        if ($count != NULL) $postData["count"]=$count;
        return($this->post("groups.history", $postData));
    }
    public function groups_list($exclude_archived=NULL) {
        $postData=array();
        if ($exclude_archived != NULL) $postData["exclude_archived"]=$exclude_archived;
        $postResponse=$this->post("groups.list", $postData);
        if ($postResponse->ok) $this->parent->groups=$postResponse->groups;
        return($postResponse);
    }
    public function groups_mark($group, $ts) {
        $postData["channel"]=$group;
        $postData["ts"]=$ts;
        return($this->post("channels.mark", $postData));
    }

    public function im_close($channel) {
        $postData["channel"]=$channel;
        unset($this->parent->ims[$channel]);
        return ($this->post("im.close", $postData));
    }
    public function im_history($im, $latest=NULL, $oldest=NULL, $count=NULL) {
        $postData["channel"]=$im;
        if ($oldest != NULL) $postData["oldest"]=$oldest;
        if ($latest != NULL) $postData["latest"]=$latest;
        if ($count != NULL) $postData["count"]=$count;
        return($this->post("im.history", $postData));
    }
    public function im_list() {
        $postResponse=$this->post("im.list", array());
        if ($postResponse->ok) $this->parent->ims=$postResponse->ims;
        return($postResponse);
    }
    public function im_mark($im, $ts) {
        $postData["channel"]=$im;
        $postData["ts"]=$ts;
        return($this->post("im.mark", $postData));
    }
    public function im_open($user) {
        $postData["user"]=$user;
        return($this->post("im.open", $postData));
    }

    public function rtm_start() {
        return($this->post("rtm.start"));
    }

    public function users_info($user) {
        $postData["user"]=$user;
        return($this->post("users.info", $postData));
    }
    public function users_list() {
        $postResponse=$this->post("users.list", array());
        if ($postResponse->ok) $this->parent->users=$postResponse->users;
        return($postResponse);
    }
    public function users_setPresence($presence) {
        $postData["presence"]=$presence;
        return($this->post("users.setPresence"));
    }

/*
 *  API Functions restricted by user_is_bot
 */
    public function channels_invite($channel, $user) {
        if ($this->parent->is_bot) return (FALSE);
        $postData = array("channel"=>$channel, "user"=>$user);
        return($this->post("channels.invite", $postData, TRUE));
    }
    public function channels_archive($channel) {
        if ($this->parent->is_bot) return (FALSE);
        $postData = array("channel"=>$channel);
        return($this->post("channels.archive", $postData, TRUE));
    }
    public function channels_create($channelName)
    {
        if ($this->parent->is_bot) return (FALSE);
        $postData = array("name" => $channelName);
        return ($this->post("channels.create", $postData, TRUE));
    }
    public function channels_join($channelName) {
        if ($this->parent->is_bot) return (FALSE);
        $postData = array("name" => $channelName);
        return ($this->post("channels.join", $postData, TRUE));
    }
    public function channels_kick($channel, $user) {
        if ($this->parent->is_bot) return (FALSE);
        $postData = array("channel" => $channel, "user" => $user);
        return ($this->post("channels.join", $postData, TRUE));
    }
    public function channels_leave($channel) {
        if ($this->parent->is_bot) return (FALSE);
        $postData = array("channel" => $channel);
        return ($this->post("channels.leave", $postData, TRUE));
    }
    public function channels_setPurpose($channel, $purpose) {
        if ($this->parent->is_bot) return (FALSE);
        $postData = array("channel" => $channel, "purpose" => $purpose);
        return ($this->post("channels.setPurpose", $postData, TRUE));
    }
    public function channels_setTopic($channel, $topic) {
        if ($this->parent->is_bot) return (FALSE);
        $postData = array("channel" => $channel, "topic" => $topic);
        return ($this->post("channels.setPurpose", $postData, TRUE));
    }
    public function channels_unarchive($channel) {
        if ($this->parent->is_bot) return (FALSE);
        $postData = array("channel" => $channel);
        return ($this->post("channels.unarchive", $postData, TRUE));
    }

    public function files_info($file, $count=100, $page=1) {
        if ($this->parent->is_bot) return (FALSE);
        $postData = array("file" => $file, "count" => $count, "page" => $page);
        return ($this->post("files.info", $postData, TRUE));
    }
    public function files_list($user=null, $ts_from=0, $ts_to="now" , $types="all", $count=100, $page=1) {
        if ($this->parent->is_bot) return (FALSE);
        $postData = array("ts_from"=>$ts_from, "ts_to"=>$ts_to, "types"=>$types, "count"=>$count, "page"=>$page);
        if ($user != null) $postData["user"]=$user;
        return ($this->post("files.list", $postData, TRUE));
    }
    public function files_upload($file=null, $content=null, $filetype=null, $filename=null, $title=null, $initial_comment=null, $channels=null) {
        if ($this->parent->is_bot) return (FALSE);
        if ($file == null && $content == null) return (FALSE);
        $postData=array();
        if ($file != null) $postData["file"]=$file;
        if ($content != null) $postData["content"]=$content;
        if ($filetype != null) $postData["filetype"]=$filetype;
        if ($filename != null) $postData["filename"]=$filename;
        if ($title != null) $postData["title"]=$title;
        if ($initial_comment != null) $postData["initial_comment"]=$initial_comment;
        if ($channels != null) $postData["channels"]=$channels;
        return ($this->post("files.upload", $postData, TRUE));
    }

    public function groups_archive($group) {
        if ($this->parent->is_bot) return (FALSE);
        return($this->post("groups.archive", array("channel" => $group), TRUE));
    }
    public function groups_create($group) {
        if ($this->parent->is_bot) return (FALSE);
        return($this->post("groups.create", array("channel" => $group), TRUE));
    }
    public function groups_createChild($group) {
        if ($this->parent->is_bot) return (FALSE);
        return($this->post("groups.createChild", array("channel" => $group), TRUE));
    }
    public function groups_invite($group, $user) {
        if ($this->parent->is_bot) return (FALSE);
        return($this->post("groups.archive", array("channel" => $group, "user" => $user ), TRUE));
    }
    public function groups_kick($group, $user) {
        if ($this->parent->is_bot) return (FALSE);
        return($this->post("groups.kick", array("channel" => $group, "user" => $user), TRUE));
    }
    public function groups_leave($group) {
        if ($this->parent->is_bot) return (FALSE);
        return($this->post("groups.leave", array("channel" => $group), TRUE));
    }
    public function groups_rename($group, $name) {
        if ($this->parent->is_bot) return (FALSE);
        return($this->post("groups.rename", array("channel"=>$group, "name"=>$name), TRUE));
    }
    public function groups_setPurpose($group, $purpose) {
        if ($this->parent->is_bot) return (FALSE);
        return($this->post("groups.setPurpose", array("channel"=>$group, "purpose"=>$purpose), TRUE));
    }
    public function groups_setTopic($group, $topic) {
        if ($this->parent->is_bot) return (FALSE);
        return($this->post("groups.setTopic", array("channel"=>$group, "topic"=>$topic), TRUE));
    }
    public function groups_unarchive($group) {
        if ($this->parent->is_bot) return (FALSE);
        return($this->post("groups.unarchive", array("channel" => $group), TRUE));
    }

    public function search_all($query, $sort=null, $sort_dir=null, $highlight=null, $count=null, $page=null) {
        if ($this->parent->is_bot) return (FALSE);
        $postData["query"]=$query;
        if ($sort != null) $postData["sort"]=$sort;
        if ($sort_dir != null) $postData["sort_dir"]=$sort_dir;
        if ($highlight != null) $postData["highlight"]=$highlight;
        if ($count != null) $postData["count"]=$count;
        if ($page != null) $postData["page"]=$page;
        return ($this->post("search.all", $postData, TRUE));
    }
    public function search_files($query, $sort=null, $sort_dir=null, $highlight=null, $count=null, $page=null) {
        if ($this->parent->is_bot) return (FALSE);
        $postData=array();
        $postData["query"]=$query;
        if ($sort != null) $postData["sort"]=$sort;
        if ($sort_dir != null) $postData["sort_dir"]=$sort_dir;
        if ($highlight != null) $postData["highlight"]=$highlight;
        if ($count != null) $postData["count"]=$count;
        if ($page != null) $postData["page"]=$page;
        return ($this->post("search.files", $postData, TRUE));
    }
    public function search_messages($query, $sort=null, $sort_dir=null, $highlight=null, $count=null, $page=null) {
        if ($this->parent->is_bot) return (FALSE);
        $postData=array();
        $postData["query"]=$query;
        if ($sort != null) $postData["sort"]=$sort;
        if ($sort_dir != null) $postData["sort_dir"]=$sort_dir;
        if ($highlight != null) $postData["highlight"]=$highlight;
        if ($count != null) $postData["count"]=$count;
        if ($page != null) $postData["page"]=$page;
        return ($this->post("search.messages", $postData, TRUE));
    }

    public function stars_list($user, $count=null, $page=null) {
        if ($this->parent->is_bot) return (FALSE);
        $postData["user"]=$user;
        if ($count != null) $postData["count"]=$count;
        if ($page != null) $postData["page"]=$page;
        return ($this->post("stars.list", $postData, TRUE));
    }

    private function post($method, array $apiData = NULL, $oauth2=FALSE) {
        $fields_string="";
        $apiData['token']= $oauth2 ? $this->oauth2 : $this->token;
        foreach ($apiData as $key => $value) {
            $fields_string .= $key . '=' . $value . '&';
        }
        rtrim($fields_string, '&');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, count($apiData));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
        $output = curl_exec($ch);
        curl_close($ch);
        return (json_decode($output));
    }
}

