<?php

namespace Noxlogic\PhpotonBundle\Service\Phpoton;

use Doctrine\Bundle\DoctrineBundle\Registry;
use GuzzleHttp\Client;
use WebSocket\ConnectionException;
use WebSocket\Client as WebSocketClient;

class Bot {

    protected $token;           // Api token
    protected $channel_id;      // Channel ID
    protected $channel_name;    // Channel name
    protected $msg_id;          // Current message id
    protected $user_cache;      // User cache
    /** @var WebSocketClient */
    protected $websocket;
    protected $start_timestamp; // Start date

    public function __construct(Registry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    public function run($token, $channel_name)
    {
        $this->token = $token;
        $this->channel_name = $channel_name;

        $this->start_timestamp = time();

        $this->init();

        try {
            while(1) {
                $message = $this->websocket->receive();
                echo "Received $message\n\n";
                $message = json_decode($message);

                $this->handle($message);
            }
        } catch (ConnectionException $e) {
            echo "Exception: Client died: $e\n";
        }
    }

    protected function init() {
        print "Connecting to slack API...";
        $this->guzzle = new Client(array('base_url' => 'https://slack.com'));
        $response = $this->guzzle->get('/api/rtm.start?token='.$this->token);
        $json = $response->json();

        // fetch channel
        $channelId = null;
        foreach ($json['channels'] as $chan) {
            if ($chan['name'] == $this->channel_name) {
                $this->channel_id = $chan['id'];
            }
        }

        // Add initial users to cache
        foreach ($json['users'] as $user) {
            $this->addUserToCache($user['id'], $user);
        }

        // fetch websocket url
        $webSocketUrl = $json['url'];
        print "Connecting to websocket: $webSocketUrl...\n";
        $this->websocket = new WebSocketClient($webSocketUrl);
    }



    /**
     * Returns (cached) user
     *
     * @param $user_id
     * @return mixed
     */
    protected function getUser($user_id) {
        if ($this->user_cache[$user_id]) {
            return $this->user_cache[$user_id];
        }

        $this->guzzle->get('/api/users.info?token='.$this->token);
        $json = $response->json();

        $this->user_cache[$json['user']['id']] = $json['user'];
        return $this->user_cache[$user_id];
    }


    /**
     * Adds a new user to cache
     *
     * @param $user_id
     * @param $user
     */
    protected function addUserToCache($user_id, $user) {
        $this->user_cache[$user_id] = $user;
    }


    /**
     * Returns next message ID
     *
     * @return mixed
     */
    protected function getMsgId() {
        $this->msg_id++;
        return $this->msg_id;
    }


    protected function handle($request)
    {
        // @TODO: Move commands to separate classes with CommandInterface

        print "REQUEST!!!\n";
        print_r($request);
        if (! isset($request->type)) return null;

        if ($request->type == "hello") {
            $this->sendMessage('This is Photon. Answer my questions correctly or face punishment!!  *bliep*');
        }

        if ($request->type == "message") {
            if ($request->channel[0] == 'D') {
                return $this->handleDirectMessage($request);
            }

            if ($request->text == "hi") {
                $user = $this->getUser($request->user);
                $this->sendMessage('Answered correctly by '.$user['name']);
            }
        }
    }


    protected function handleDirectMessage($request)
    {
        // @TODO: Move commands to separate classes with DirectCommandInterface

        $request->text = trim($request->text);
        if (in_array($request->text, array("help", "?"))) {
            $this->sendMessage('Available commands: *help*, *score*, *info*, *add*', $request->channel);
            return;
        }

        if ($request->text == "score") {
            $this->sendMessage('Score is not implemeted yet', $request->channel);
            return;
        }

        if ($request->text == "info") {
            $this->sendMessage(
                "Info about me:\n" .
                "- Questions asked: *123*\n" .
                "- Questions in database: *52*\n" .
                "- Online for: *".$this->secondsToTime(time()-$this->start_timestamp)."*",
                $request->channel);
            return;
        }
    }

    protected function secondsToTime($seconds) {
        $dtF = new \DateTime("@0");
        $dtT = new \DateTime("@$seconds");
        return $dtF->diff($dtT)->format('%a days, %h hours, %i minutes and %s seconds');
    }


    /**
     * Sends a message back to the websocket
     *
     * @param $message
     * @param null $channel_id  Custom channel ID (like a private channel), defaults to main channel
     */
    protected function sendMessage($message, $channel_id = null)
    {
        if ($channel_id == null) {
            $channel_id = $this->channel_id;
        }

        $response = array(
            'id' => $this->getMsgId(),
            'type' => 'message',
            'channel' => $channel_id,
            'text' => $message,
        );

        $this->websocket->send(json_encode($response));
    }
}
