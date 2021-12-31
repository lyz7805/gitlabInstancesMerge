<?php

namespace App\Command;

use Gitlab\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class UserImportCommand extends AbstractOneGitlabCommand
{
    protected static $defaultName = 'user:import';
    protected static $defaultDescription = 'Import gitlab users';

    protected function configure()
    {
        parent::configure();
        $this->setHelp('This command allows you to import gitlab users from a json file which is imported by user:import.')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'json file path'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$output instanceof ConsoleOutputInterface) {
            throw new \LogicException('This command accepts only an instance of "ConsoleOutputInterface".');
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('IMPORT GITLAB USERS');

        $io->info(sprintf('Now time: %s', date('Y-m-d H:i:s')));

        $client = new Client();
        $client->setUrl($input->getArgument('url'));
        $client->authenticate($input->getArgument('access_token'), Client::AUTH_HTTP_TOKEN);

        $version = $client->version()->show();
        $io->info(sprintf('GitLab Version: %s(%s)', $version['version'], $version['revision']));

        $io->section('Get file data:');
        $file = $input->getArgument('file');
        if (!file_exists($file)) {
            $io->error('File: '.$file.' not exists, please set another file!');

            return Command::INVALID;
        }

        if (filetype($file) !== 'file') {
            $io->error('File: '.$file.' is not a file, please set a file!');

            return Command::INVALID;
        }

        $data = file_get_contents($file);
        if ($data === false) {
            $io->error("Can't get file: $file contents!");

            return Command::INVALID;
        }

        $data = json_decode($data, true);
        if (!is_array($data)) {
            $io->error('file: '.$file.' content is not json string!');

            return Command::INVALID;
        }

        $count = count($data);
        if ($count) {
            $io->info('File users count: '.$count);
            // sort by id
            $sortUsers = [];
            foreach ($data as $user) {
                // TODO: 这里应该加上数据过滤

                $sortUsers[$user['id']] = $user;
            }
            ksort($sortUsers);

            $header = ['id', 'username', 'email', 'name', 'state'];
            $rows = [];
            foreach ($sortUsers as $user) {
                $rows[] = [$user['id'], $user['username'], $user['email'], $user['name'], $user['state']];
            }
            $io->table($header, $rows);

            $io->section('Import users:');

            $pbSec = $output->section();
            $msgSec = $output->section();

            $progressBar = new ProgressBar($pbSec);

            $table = new Table($output);
            $header = ['username', 'email', 'name', 'state', 'status', 'message'];
            $table->setHeaders($header);
            // $table->setColumnMaxWidth(0, 12);
            // $table->setColumnMaxWidth(1, 30);
            // $table->setColumnMaxWidth(2, 15);

            $rows = [];
            $users = $client->users();
            foreach ($progressBar->iterate($sortUsers) as $key => $user) {
                usleep(200000);
                $username = $user['username'];
                $email = $user['email'];
                $name = $user['name'];
                // admin or bot user
                if ($user['bot'] || $user['id'] == 1) {
                    $type = $user['bot'] ? 'bot' : 'admin';
                    $message = sprintf("User %s(%s) is %s user, don't need to import", $username, $email, $type);
                    $msgSec->overwrite('<comment>'.$message.'</comment>');
                    $rows[] = [$username, $email, $name, '-', '-', $message];

                    $table->addRow([$username, $email, $name, '-', '❗', "<comment>$message</comment>"]);
                    continue;
                }

                $create = [
                    'admin' => $user['is_admin'] ?? false,
                    // 'avatar' => '', //$user['avatar'],
                    'bio' => $user['bio'] ?? '',
                    'can_create_group' => $user['can_create_group'],
                    'color_scheme_id' => $user['color_scheme_id'],
                    'email' => $user['email'],
                    // 'extern_uid' => $user['extern_uid'],
                    // 'external' => $user['external'],
                    // 'extra_shared_runners_minutes_limit' => $user['extra_shared_runners_minutes_limit'],
                    'force_random_password' => false,
                    // 'group_id_for_saml' => $user['group_id_for_saml'],
                    'linkedin' => $user['linkedin'],
                    'location' => $user['location'],
                    'name' => $user['name'],
                    'note' => $user['note'],
                    'organization' => $user['organization'],
                    'password' => $user['email'], // set email as password
                    'private_profile' => $user['private_profile'] ?? false,
                    'projects_limit' => $user['projects_limit'],
                    // 'provider' => $user['provider'],
                    'public_email' => $user['public_email'],
                    'reset_password' => false,
                    // 'shared_runners_minutes_limit' => 0,
                    'skip_confirmation' => true,
                    'skype' => $user['skype'],
                    'theme_id' => $user['theme_id'],
                    'twitter' => $user['twitter'],
                    'username' => $user['username'],
                    'website_url' => $user['website_url'],
                ];

                try {
                    $res = $users->create($email, $email, $create);
                } catch (\Exception $e) {
                    $message = trim($e->getMessage());
                    $msgSec->overwrite([
                        '<fg=red>'.sprintf('User %s(%s) create failed', $username, $email).'</>',
                        '<fg=red>'.'Error message: '.$message.'</>',
                    ]);
                    $rows[] = [$username, $email, $name, '-', 'failed', $message];

                    $table->addRow([$username, $email, $name, '-', '❌', "<fg=red>$message</>"]);
                    continue;
                }

                $id = $res['id'];
                $message = '';
                // block user
                if ($user['state'] == 'blocked') {
                    if ($users->block($id)) {
                        $message = sprintf('User %s(%s) has been blocked', $res['username'], $res['email']);
                        $msgSec->overwrite('<info>'.$message.'</info>');
                        $res['state'] = 'blocked';
                    } else {
                        $message = sprintf('User %s(%s) block failed', $res['username'], $res['email']);
                        $msgSec->overwrite('<comment>'.$message.'</comment>');
                    }
                }

                $msgSec->overwrite([
                    '<info>'.sprintf('User %s(%s) import success', $res['username'], $res['email']).'</info>',
                    '<info>'.'User info: '.json_encode($res).'</info>',
                ]);
                $rows[] = [$username, $email, $name, $res['state'], 'success', $message];
                $table->addRow([$username, $email, $name, $res['state'], '✔', "<info>$message</info>"]);
            }

            $msgSec->clear();
            $table->render();

            $dir = __DIR__.'/../../result/import/user/';
            if (!file_exists($dir)) {
                if (mkdir($dir, 0777, true)) {
                    $realDirPath = realpath($dir);
                    $io->info('Make import result files dir: '.$realDirPath);
                } else {
                    $io->error('Can not create import result files dir: '.$dir);

                    return Command::FAILURE;
                }
            } else {
                $realDirPath = realpath($dir);
            }
            $resFile = sprintf('%s_%s_import.csv', date('Y-m-d_H-i-s'), 'users');
            $resFile = $realDirPath.'/'.$resFile;
            $file = fopen($resFile, 'a');
            fputcsv($file, mb_convert_encoding($header, 'GBK'));
            foreach ($rows as $row) {
                $row = mb_convert_encoding($row, 'GBK');
                fputcsv($file, $row);
            }
            fclose($file);
            $io->success(sprintf('Import Result file: '.$resFile));
        } else {
            $io->warning('No users found');
        }

        return Command::SUCCESS;
    }
}
