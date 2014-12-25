<?php
$bootTime=time();
$baseUrl = "https://slack.com/api/";
$slackToken = "x......"; // client scope
$oauth2Token = "x....."; // oauth2 scopes
require_once ("inc/Class.SlackClient.php");

$slack=new slackClient($baseUrl, $slackToken, $oauth2Token);
$slack->init();
$slack->connect();

while (true) {
    $arr=array();
    $streamarray=array($slack->stream);
    $resArray=stream_select($streamarray, $arr, $arr, 3);
    if (!empty($resArray)) {
        $response = $slack->readPacket();
        $slack->last_pong=time();
	// Handle $response with custom code here
    } else {
        if (time() - $slack->last_pong < 5)
            $slack->ping();
    }
    if (time() - $slack->last_pong > 30) {
        echo "No pong for 20 seconds. Restarting connection\n";
        $slack->reconnect();
    }
}

?>
