<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

abstract class AbstractOneGitlabCommand extends Command
{
    protected int $perPage = 100;

    protected function configure()
    {
        $this->addArgument(
                'url',
                InputArgument::REQUIRED,
                'gitlab url'
            )
            ->addArgument(
                'access_token',
                InputArgument::REQUIRED,
                'user access token'
            );
    }
}
