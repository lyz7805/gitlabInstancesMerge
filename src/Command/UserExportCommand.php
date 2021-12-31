<?php

namespace App\Command;

use App\Api\Users;
use Gitlab\Client;
use Gitlab\ResultPager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class UserExportCommand extends AbstractOneGitlabCommand
{
    protected static $defaultName = 'user:export';
    protected static $defaultDescription = 'Export gitlab users';

    protected function configure()
    {
        parent::configure();
        $this->setHelp('This command allows you to export gitlab users by gitlab apis.')
            ->addArgument(
                'file',
                InputArgument::OPTIONAL,
                'export file path'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('EXPORT GITLAB USERS');

        $io->info(sprintf('Now time: %s', date('Y-m-d H:i:s')));

        $file = $input->getArgument('file');
        if ($file) {
            if (file_exists($file)) {
                $io->error('File: '.$file.' exists, please set another file name or unset the argument!');

                return Command::INVALID;
            }
        } else {
            $dir = __DIR__.'/../../result/export/user/';
            if (!file_exists($dir)) {
                if (mkdir($dir, 0777, true)) {
                    $realDirPath = realpath($dir);
                    $io->info('Make export result files dir: '.$realDirPath);
                } else {
                    $io->error('Can not create export result files dir: '.$dir);

                    return Command::FAILURE;
                }
            } else {
                $realDirPath = realpath($dir);
            }
            $file = sprintf('%s_%s_export.json', date('Y-m-d_H-i-s'), 'users');
            $file = $realDirPath.'/'.$file;
        }

        $client = new Client();
        $client->setUrl($input->getArgument('url'));
        $client->authenticate($input->getArgument('access_token'), Client::AUTH_HTTP_TOKEN);

        $version = $client->version()->show();
        $io->info(sprintf('GitLab Version: %s(%s)', $version['version'], $version['revision']));

        $io->section('Get all users:');
        $pager = new ResultPager($client, $this->perPage);

        $userAll = [];
        $header = ['username', 'email', 'name', 'state'];
        $rows = [];

        $io->progressStart();
        $users = new Users($client);
        $userAllFetch = $pager->fetchAllLazy($users, 'all', [[
            'order_by' => 'id',
            'sort' => 'asc',
        ]]);
        foreach ($userAllFetch as $user) {
            $userAll[] = $user;
            $rows[] = [$user['username'], $user['email'], $user['name'], $user['state']];
            $io->progressAdvance();
            usleep(300000 / $this->perPage);
        }
        $io->progressFinish();

        $io->info('Users count: '.count($userAll));
        if (count($userAll)) {
            $io->section('Users info list:');
            $io->table($header, $rows);
        } else {
            $io->warning('No users found');

            return Command::SUCCESS;
        }

        if (file_put_contents($file, json_encode($userAll, JSON_UNESCAPED_UNICODE)) !== false) {
            $io->success([
                'Export users success',
                'File path: '.$file
            ]);
        }

        return Command::SUCCESS;
    }
}
