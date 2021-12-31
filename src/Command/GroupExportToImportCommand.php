<?php

namespace App\Command;

use App\Api\Groups;
use Gitlab\Client;
use Gitlab\ResultPager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class GroupExportToImportCommand extends AbstrachTwoGitlabCommand
{
    protected static $defaultName = 'group:export2import';
    protected static $defaultDescription = 'Export groups and then import them into the new gitlab';

    protected function configure(): void
    {
        parent::configure();
        $this->setHelp('This command allows you to export gitlab groups from an old gitlab instance and then import them into another new instance.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('EXPORT/IMPORT GITLAB GROUPS');

        $io->info(sprintf('Now time: %s', date('Y-m-d H:i:s')));

        // export gitlab client
        $eClient = new Client();
        $eClient->setUrl($input->getArgument('export_url'));
        $eClient->authenticate($input->getArgument('export_access_token'), Client::AUTH_HTTP_TOKEN);

        // import gitlab client
        $iClient = new Client();
        $iClient->setUrl($input->getArgument('import_url'));
        $iClient->authenticate($input->getArgument('import_access_token'), Client::AUTH_HTTP_TOKEN);

        $eVersion = $eClient->version()->show();
        $io->info(sprintf('GitLab Version(Export): %s(%s)', $eVersion['version'], $eVersion['revision']));
        $iVersion = $iClient->version()->show();
        $io->info(sprintf('GitLab Version(Import): %s(%s)', $iVersion['version'], $iVersion['revision']));

        $io->section('Get all top level groups by pager:');
        $eGroups = new Groups($eClient);
        $ePager = new ResultPager($eClient, $this->perPage);

        $groupAll = [];
        // $groupAll = $ePager->fetchAll($eGroups, 'all', [[
        //     'order_by' => 'id',
        //     'sort' => 'asc',
        //     'top_level_only' => true, // 只需要顶级组即可
        // ]]);

        $io->progressStart();
        $groupAll = $ePager->fetch($eGroups, 'all', [[
            'order_by' => 'id',
            'sort' => 'asc',
            'top_level_only' => true, // 只需要顶级组即可
        ]]);
        $io->progressAdvance();
        while ($ePager->hasNext()) {
            $all = $ePager->fetchNext();
            $groupAll = array_merge($all, $groupAll);

            $io->progressAdvance();
        }
        $io->progressFinish();
        $io->info('Top level groups count: '.count($groupAll));

        $io->section('Groups(Export) list:');
        if (count($groupAll)) {
            $header = ['id', 'name', 'path', 'created_at'];

            $rows = [];
            foreach ($groupAll as $group) {
                $rows[] = [$group['id'], $group['name'], $group['path'], $group['created_at']];
            }
            $io->table($header, $rows);

            foreach ($groupAll as $group) {
                $id = $group['id'];
                $name = $group['name'];
                $path = $group['path'];
                $io->section("Export group: $name and download:");
                $eGroups->export($id); // {"message":"202 Accepted"}

                $sleepSec = 5;
                $io->progressStart($sleepSec);
                do {
                    sleep(1);
                    $io->progressAdvance();
                    --$sleepSec;
                } while ($sleepSec > 0);
                $io->progressFinish();

                $download = $eGroups->exportDownload($id);
                $dir = __DIR__.'/../../result/export/group/';
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

                // 2021-11-17_17-39-820_gangquan_export.tar.gz
                $file = sprintf('%s_%s_export.tar.gz', date('Y-m-d_H-i-s'), $name);
                $fullFilePath = $realDirPath.'/'.$file;

                if (file_put_contents($fullFilePath, $download) !== false) {
                    $io->info([
                        'Download export file success',
                        'File path: '.$fullFilePath,
                        'File size: '.filesize($fullFilePath).'bytes',
                    ]);
                } else {
                    $io->error('Download export file failure: '.$fullFilePath);

                    return Command::FAILURE;
                }

                if ($name == 'lost-and-found') {
                    $io->warning('Group: '.$name.' dont need import');
                    continue;
                }

                $io->section("Check whether the group: $name exists:");
                $iGroups = new Groups($iClient);
                $iPager = new ResultPager($iClient, $this->perPage);

                $iGroupsGen = $iPager->fetchAllLazy($iGroups, 'all', [[
                    'search' => $name,
                    'order_by' => 'name',
                    'sort' => 'asc',
                    'top_level_only' => true, // 只需要顶级组即可
                ]]);
                $hasGroup = false;
                foreach ($iGroupsGen as $iGroup) {
                    if ($iGroup['path'] == $path) {
                        $hasGroup = true;
                        break;
                    }
                    usleep(300000);
                }

                if ($hasGroup) {
                    $io->warning(sprintf('Group: %s(path: %s) exists, do not import', $name, $path));
                    continue;
                }
                $io->info('Group: '.$name.' not exists');

                $io->section('Import group:');
                $io->info([
                    'name: '.$name,
                    'path: '.$path,
                    'file: '.$fullFilePath,
                ]);
                try {
                    $iGroups->import($name, $path, $fullFilePath);
                } catch (\Exception $e) {
                    $io->error(sprintf('Import group: %s(path: %s) error: %d - %s', $name, $path, $e->getCode(), $e->getMessage()));
                    continue;
                }

                $io->success(sprintf('Import group: %s(path: %s) schedule create success', $name, $path));

                $sleepSec = 3;
                $io->section('Sleep 3 seconds to execute next group:');
                $io->progressStart();
                while ($sleepSec > 0) {
                    sleep(1);
                    $io->progressAdvance();
                    --$sleepSec;
                }
                $io->progressFinish();
            }
        } else {
            $io->info('The gitlab instance does not have any groups');
        }

        return Command::SUCCESS;
    }
}
