<?php

namespace App\Command;

use App\Api\Projects;
use Gitlab\Client;
use Gitlab\ResultPager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ProjectExportToImportCommand extends AbstrachTwoGitlabCommand
{
    protected static $defaultName = 'project:export2import';
    protected static $defaultDescription = 'Export projects and then import them into the new gitlab';

    protected function configure(): void
    {
        parent::configure();
        $this->setHelp('This command allows you to export gitlab projects from an old gitlab instance and then import them into another new instance.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('EXPORT/IMPORT GITLAB PROJECTS');

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

        $io->section('Get all projects by pager:');
        $eProjects = new Projects($eClient);
        $ePager = new ResultPager($eClient, $this->perPage);

        $projectAll = [];

        $io->progressStart();
        $projectAllFetch = $ePager->fetchAllLazy($eProjects, 'all', [[
            'search_namespaces' => true,
            'order_by' => 'last_activity_at',
            'sort' => 'asc',
        ]]);
        foreach ($projectAllFetch as $project) {
            $projectAll[] = $project;

            $io->progressAdvance();
            usleep(300000 / $this->perPage);
        }

        $io->progressFinish();
        $io->info('Projects count: '.count($projectAll));

        $io->section('Projects(Export) list:');
        if (count($projectAll)) {
            $header = ['id', 'name', 'path_with_namespace', 'created_at', 'last_activity_at'];

            $rows = [];
            foreach ($projectAll as $project) {
                $rows[] = [$project['id'], $project['name'], $project['path_with_namespace'], $project['created_at'], $project['last_activity_at']];
            }
            $io->table($header, $rows);

            $iProjectsResHeader = ['o_id', 'n_id', 'name', 'path_with_namespace', 'status', 'message'];
            $iProjectsResRows = [];
            foreach ($projectAll as $key => $project) {
                $io->section(sprintf('Start execute the %s project:', $key));
                $id = $project['id'];
                $name = $project['name'];
                $path = $project['path'];
                $path_with_namespace = $project['path_with_namespace'];
                $namespace_full_path = $project['namespace']['full_path'];
                $io->section("Export project: $name and download:");
                $eProjects->export($id); // {"message":"202 Accepted"}

                $exportStatus = '';
                $io->progressStart();
                do {
                    sleep(1);
                    $status = $eProjects->exportStatus($id);
                    $exportStatus = $status['export_status'];

                    $io->progressAdvance();
                } while ($exportStatus != 'finished');
                $io->progressFinish();

                $download = $eProjects->exportDownload($id);
                $dir = __DIR__.'/../../result/export/project/';
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

                // 2017-12-05_22-11-148_namespace_project_export.tar.gz
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
                    $msg = 'Project: '.$name.' dont need import';
                    $io->warning($msg);
                    
                    $iProjectsResRows[] = [$id, null, 'info', $msg];
                    goto sleepToNext;
                }

                $io->section("Check whether the project: $name exists:");
                $iProjects = new Projects($iClient);
                $iPager = new ResultPager($iClient, $this->perPage);

                $iProjectsGen = $iPager->fetchAllLazy($iProjects, 'all', [[
                    'search' => $path,
                    // 'search_namespaces' => true,
                    'order_by' => 'name',
                    'sort' => 'asc',
                ]]);
                $hasProject = false;
                foreach ($iProjectsGen as $iProject) {
                    if ($iProject['path_with_namespace'] == $path_with_namespace) {
                        $hasProject = true;
                        break;
                    }
                    usleep(300000);
                }

                if ($hasProject) {
                    $msg = sprintf('Project: %s(path: %s) exists, do not import', $name, $path_with_namespace);
                    $io->warning($msg);
                    
                    $iProjectsResRows[] = [$id, $iProject['id'], 'warning', $msg];
                    goto sleepToNext;
                }
                $io->info('Project: '.$name.' not exists');

                $io->section('Import project:');
                $io->info([
                    'name: '.$name,
                    'path: '.$path_with_namespace,
                    'file: '.$fullFilePath,
                ]);
                try {
                    $importRes = $iProjects->import($path, $fullFilePath, $namespace_full_path, $name);
                } catch (\Exception $e) {
                    $msg = sprintf('Import project: %s(path: %s) error: %d - %s', $name, $path, $e->getCode(), $e->getMessage());
                    $io->error($msg);

                    $iProjectsResRows[] = [$id, null, 'error', $msg];
                    goto sleepToNext;
                }

                $io->success(sprintf('Import project: %s(path: %s) schedule create success, project info: %s', $name, $path_with_namespace, json_encode($importRes, JSON_UNESCAPED_UNICODE)));
                $iProjectId = $importRes['id'];

                $importStatus = '';
                $io->progressStart();
                do {
                    sleep(1);
                    $status = $iProjects->importStatus($iProjectId);
                    $importStatus = $status['import_status'];

                    $io->progressAdvance();
                } while ($importStatus != 'finished' && $importStatus != 'failed');
                $io->progressFinish();

                if ($importStatus == 'failed') {
                    $msg = sprintf('Import project: %s(path: %s) failed, error msg: %s', $name, $path_with_namespace, $status['import_error']);
                    $io->error([
                        $msg,
                        'Failed relations: %s', json_encode($status['failed_relations'])
                    ]);
                    $iProjectsResRows[] = [$id, $iProjectId, 'error', $msg];
                } else {
                    $msg = sprintf('Import project: %s(path: %s) finished', $name, $path_with_namespace);
                    $io->success($msg);
                    $iProjectsResRows[] = [$id, $iProjectId, 'success', $msg];
                }

                sleepToNext:
                $sleepSec = 3;
                $io->section('Sleep 3 seconds to execute next project:');
                $io->progressStart();
                while ($sleepSec > 0) {
                    sleep(1);
                    $io->progressAdvance();
                    --$sleepSec;
                }
                $io->progressFinish();
            }

            $io->section('Import projects result:');
            $io->table($iProjectsResHeader, $iProjectsResRows);
        } else {
            $io->info('The gitlab instance does not have any projects');
        }

        return Command::SUCCESS;
    }
}
