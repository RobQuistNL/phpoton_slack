<?php

namespace Noxlogic\PhpotonBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BotCommand extends ContainerAwareCommand
{
    /**
     * @see Command
     */
    protected function configure()
    {
        $this
            ->setName('phpoton:run')
            ->setDescription('Run the bot')
            ->addArgument('token', InputArgument::REQUIRED)
            ->addArgument('channel', InputArgument::REQUIRED)
        ;
    }

    /**
     * @see Command
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $token = $input->getArgument('token');
        $channel = $input->getArgument('channel');

        $bot = $this->getContainer()->get('phpoton.bot.service');
        $bot->run($token, $channel);
    }

}
