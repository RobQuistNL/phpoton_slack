<?php

namespace Noxlogic\PhpotonBundle\Service\Phpoton;

use Doctrine\Bundle\DoctrineBundle\Registry;
use GuzzleHttp\Client;
use Noxlogic\PhpotonBundle\Entity\Score;
use Noxlogic\PhpotonBundle\Websocket\TimeoutException;
use Noxlogic\PhpotonBundle\Websocket\Client as WebSocketClient;

class Bot {

    const QUESTION_TIMEOUT = 45;        // Maximum time to answer a question
    const IDLE_MIN = 5;                 // Minimum number of seconds between questions
    const IDLE_MAX = 30;                // Maximum number of seconds between questions

    protected $token;           // Api token
    protected $channel_id;      // Channel ID
    protected $channel_name;    // Channel name
    protected $msg_id;          // Current message id

    /** @var array User cache */
    protected $user_cache;

    /** @var integer start timestamp */
    protected $start_timestamp;

    /** @var WebSocketClient */
    protected $websocket;

    /** @var Registry */
    protected $doctrine;


    protected $current_question = null;
    protected $question_timeout = null;
    protected $next_question_timestamp = null;


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

        while (true) {
            try {
                $message = $this->websocket->receive();
            } catch (TimeoutException $e) {
                $this->handleQuestioning();
                continue;
            }

            echo "Received\n\n";
            var_dump($message);
            $message = json_decode($message);
            if ($message) {
                $this->handle($message);
            }
        }
    }

    protected function init() {
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
        $this->websocket = new WebSocketClient($webSocketUrl, array('timeout' => 1));
    }



    /**
     * Returns (cached) user
     *
     * @param $user_id
     * @return mixed
     */
    protected function getUser($user_id) {
        if (isset($this->user_cache[$user_id])) {
            return $this->user_cache[$user_id];
        }

        $response = $this->guzzle->get('/api/users.info?token='.$this->token);
        $json = $response->json();

        if (! $json['ok']) return array('id' => $user_id, 'name' => "[".$user_id."]");

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
        if (! isset($request->type)) return null;

        if ($request->type == "hello") {
            $this->sendMessage('This is Photon. Answer my questions correctly or face punishment!!  **bliep**');
        }

        if ($request->type == "message") {
            if ($request->channel[0] == 'D') {
                return $this->handleDirectMessage($request);
            } else {
                return $this->handleMessage($request);
            }
        }
    }


    protected function handleMessage($request) {
        // @TODO: Move commands to separate classes with CommandInterface

        if ($this->current_question == null) {
            $this->sendMessage("Please wait until we ask a question!\n");
            return;
        }

        $answer = trim(strtolower($request->text));
        $actual = trim(strtolower($this->current_question->getAnswer()));

        if ($answer === $actual) {
            $user = $this->getUser($request->user);
            $this->sendMessage('Answered correctly by '.$user['name'].'! Score increased by one robo-point! **bliep**'.$this->randomEmoji());

            $this->increaseScore($request->user);
            $this->scheduleNextQuestion();
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
            $output = "Top 25: \n";

            $oldPoints = 0;
            $pos = 0;

            $top = $this->doctrine->getRepository('NoxlogicPhpotonBundle:Score')->getTop(25);
            foreach ($top as $item) {
                $user_id = $item['0']->getUser();
                $user = $this->getUser($user_id);

                // Take care of shared positions
                if ($item['points'] != $oldPoints) {
                    $pos++;
                    $oldPoints = $item['points'];
                }

                $output .= " #".$pos." - ".$user['name']." - (".$item['points']." points)\n";
            }

            $points = $this->doctrine->getRepository('NoxlogicPhpotonBundle:Score')->getUserScore($request->user);

            $output .= "\n";
            $output .= "Your score: ".$points." points\n";

            $this->sendMessage($output, $request->channel);

            return;
        }

        if ($request->text == "info") {
            $questionCount = $this->doctrine->getRepository('NoxlogicPhpotonBundle:Question')->count();

            $this->sendMessage(
                "Info about me:\n" .
                "- Questions in database: *".$questionCount."*\n" .
                "- Online for: *".$this->secondsToTime(time()-$this->start_timestamp)."*",
                $request->channel);
            return;
        }
    }



    /**
     * Converts seconds to textual time
     *
     * @param $seconds
     * @return string
     */
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


    protected function handleQuestioning() {
        if (! $this->current_question && ($this->next_question_timestamp == null || $this->next_question_timestamp < time())) {
            $question = $this->doctrine->getRepository('NoxlogicPhpotonBundle:Question')->random();

            $this->current_question = $question;
            $this->question_timeout = time() + self::QUESTION_TIMEOUT;

            // New question
            $this->sendMessage(':question: '.$question->getQuestion());
        }

        if ($this->current_question && time() > $this->question_timeout) {
            $this->sendMessage(':scream: Timeout! This question is too hard for humans!');
            $this->scheduleNextQuestion();
        }
    }

    /**
     * Return random emoji
     *
     * @return mixed
     */
    protected function randomEmoji() {
        $emoji = array('', ':dancers:',':raised_hands:',':birthday:',':tada:',':clap:',':thumbsup:');
        shuffle($emoji);
        return array_pop($emoji);
    }

    /**
     * Schedule next question
     */
    protected function scheduleNextQuestion()
    {
        $this->current_question = null;
        $this->next_question_timestamp = time() + rand(self::IDLE_MIN, self::IDLE_MAX);
    }

    /**
     * Add score to database
     *
     * @param $user_id
     */
    protected function increaseScore($user_id)
    {
        $score = new Score();
        $score->setUser($user_id);
        $score->setCreatedDt(new \DateTime());
        $this->doctrine->getManager()->persist($score);
        $this->doctrine->getManager()->flush();
    }

}
