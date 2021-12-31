<?php

namespace App\Api;

use Gitlab\Api\Projects as ApiProjects;
use Psr\Http\Message\StreamInterface;
use ValueError;

class Projects extends ApiProjects
{
    /**
     * @param int|string $project_id    The ID or URL-encoded path of the project owned by the authenticated user
     * @param string     $description   Overrides the project description
     * @param string     $upload_url    The URL to upload the project
     * @param string     $upload_method The HTTP method to upload the exported project. Only PUT and POST methods allowed. Default is PUT
     *
     * @return mixed
     */
    public function export($project_id, string $description = null, string $upload_url = null, string $upload_method = null)
    {
        $params = [];
        !empty($description) && $params['description'] = $description;
        if (!empty($upload_url)) {
            $params['upload']['url'] = $upload_url;
            $params['upload']['method'] = in_array(strtoupper($upload_method), ['PUT', 'POST']) ? strtoupper($upload_method) : 'PUT';
        }

        return $this->post('projects/'.self::encodePath($project_id).'/export');
    }

    /**
     * @param int|string $project_id The ID or URL-encoded path of the project owned by the authenticated user
     *
     * @return mixed
     */
    public function exportStatus($project_id)
    {
        return $this->get('projects/'.self::encodePath($project_id).'/export');
    }

    /**
     * @param int|string $project_id
     *
     * @return StreamInterface
     */
    public function exportDownload($project_id)
    {
        return $this->getAsResponse('projects/'.self::encodePath($project_id).'/export/download')
            ->getBody();
    }

    /**
     * @param string   $path      Name and path for new project
     * @param string   $file      The file to be uploaded
     * @param mixed    $namespace The ID or path of the namespace that the project will be imported to. Defaults to the current user’s namespace
     * @param string   $name      The name of the project to be imported. Defaults to the path of the project if not provided
     * @param bool $overwrite     If there is a project with the same path the import will overwrite it. Default to false
     *
     * @return mixed
     */
    public function import(string $path, string $file, $namespace = null, string $name = null, bool $overwrite = false)
    {
        $params = [
            'path' => $path,
            'overwrite' => $overwrite ? 'true' : 'false'
        ];
        $namespace && $params['namespace'] = $namespace;
        $name && $params['name'] = $name;

        return $this->post('projects/import', $params, [], ['file' => $file]);
    }

    /**
     * @param int|string $project_id The ID or URL-encoded path of the project owned by the authenticated user
     *
     * @return mixed
     */
    public function importStatus($project_id)
    {
        return $this->get('projects/'.self::encodePath($project_id).'/import');
    }
}
