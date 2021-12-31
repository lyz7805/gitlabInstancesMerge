<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

abstract class AbstrachTwoGitlabCommand extends Command
{
    protected int $perPage = 100;

    protected function configure(): void
    {
        $this->addArgument(
                'export_url',
                InputArgument::REQUIRED,
                'export gitlab url'
            )
            ->addArgument(
                'export_access_token',
                InputArgument::REQUIRED,
                'export gitlab access token'
            )
            ->addArgument(
                'import_url',
                InputArgument::REQUIRED,
                'import gitlab url'
            )
            ->addArgument(
                'import_access_token',
                InputArgument::REQUIRED,
                'import gitlab access token'
            );
    }
}
