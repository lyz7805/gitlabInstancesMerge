<?php

namespace App\Api;

use ValueError;
use Gitlab\Api\Groups as ApiGroups;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

class Groups extends ApiGroups
{
    /**
     * @param array $parameters {
     *
     *     @var int[]  $skip_groups      skip the group IDs passes
     *     @var bool   $all_available    show all the groups you have access to
     *     @var string $search           return list of authorized groups matching the search criteria
     *     @var string $order_by         Order groups by name or path (default is name)
     *     @var string $sort             Order groups in asc or desc order (default is asc)
     *     @var bool   $statistics       include group statistics (admins only)
     *     @var bool   $with_custom_attributes  include custom attributes in response (administrators only)
     *     @var bool   $owned            limit by groups owned by the current user
     *     @var int    $min_access_level limit by groups in which the current user has at least this access level
     *     @var int    $top_level_only   limit to top level groups, excluding all subgroups
     * }
     *
     * @return mixed
     */
    public function all(array $parameters = [])
    {
        $resolver = $this->getGroupSearchResolver();

        return $this->get('groups', $resolver->resolve($parameters));
    }

    /**
     * @param int|string $group_id
     *
     * @return mixed
     */
    public function export($group_id)
    {
        return $this->post('groups/'.self::encodePath($group_id).'/export');
    }

    /**
     * @param int|string $group_id
     *
     * @return StreamInterface
     */
    public function exportDownload($group_id)
    {
        return $this->getAsResponse('groups/'.self::encodePath($group_id).'/export/download')
            ->getBody();
    }

    /**
     * @param string $name 	        the name of the group to be imported
     * @param string $path          name and path for new group
     * @param string $file          The file to be uploaded
     * @param int|null $parent_id   ID of a parent group that the group will be imported into. Defaults to the current user’s namespace if not provided.
     *
     * @return mixed
     */
    public function import(string $name, string $path, string $file, int $parent_id = null)
    {
        $params = [
            'name' => $name,
            'path' => $path,
        ];

        if (null !== $parent_id && ($parent_id < 1)) {
            throw new ValueError(\sprintf('%s::import(): Argument #4 ($parent_id) must be bigger than 1, or null', self::class));
        }
        $parent_id !== null && $params['parent_id'] = $parent_id;

        return $this->post('groups/import', $params, [], ['file' => $file]);
    }

    /**
     * @param int|string $group_id
     * @param array      $parameters {
     *
     *     @var int[]  $skip_groups   skip the group IDs passes
     *     @var bool   $all_available show all the groups you have access to
     *     @var string $search        return list of authorized groups matching the search criteria
     *     @var string $order_by      Order groups by name or path (default is name)
     *     @var string $sort          Order groups in asc or desc order (default is asc)
     *     @var bool   $statistics    include group statistics (admins only)
     *     @var bool   $with_custom_attributes  include custom attributes in response (administrators only)
     *     @var bool   $owned         Limit by groups owned by the current user.
     *     @var int    $min_access_level limit by groups in which the current user has at least this access level
     * }
     *
     * @return mixed
     */
    public function subgroups($group_id, array $parameters = [])
    {
        $resolver = $this->getGroupSearchResolver();

        return $this->get('groups/'.self::encodePath($group_id).'/subgroups', $resolver->resolve($parameters));
    }

    /**
     * @return OptionsResolver
     */
    protected function getGroupSearchResolver()
    {
        $resolver = $this->createOptionsResolver();
        $booleanNormalizer = function (Options $resolver, $value): string {
            return $value ? 'true' : 'false';
        };

        $resolver->setDefined('skip_groups')
            ->setAllowedTypes('skip_groups', 'array')
            ->setAllowedValues('skip_groups', function (array $value) {
                return \count($value) === \count(\array_filter($value, 'is_int'));
            })
        ;
        $resolver->setDefined('all_available')
            ->setAllowedTypes('all_available', 'bool')
            ->setNormalizer('all_available', $booleanNormalizer)
        ;
        $resolver->setDefined('search');
        $resolver->setDefined('order_by')
            ->setAllowedValues('order_by', ['id', 'name', 'path'])
        ;
        $resolver->setDefined('sort')
            ->setAllowedValues('sort', ['asc', 'desc'])
        ;
        $resolver->setDefined('statistics')
            ->setAllowedTypes('statistics', 'bool')
            ->setNormalizer('statistics', $booleanNormalizer)
        ;
        $resolver->setDefined('with_custom_attributes')
            ->setAllowedTypes('with_custom_attributes', 'bool')
            ->setNormalizer('with_custom_attributes', $booleanNormalizer)
        ;
        $resolver->setDefined('owned')
            ->setAllowedTypes('owned', 'bool')
            ->setNormalizer('owned', $booleanNormalizer)
        ;
        $resolver->setDefined('min_access_level')
            ->setAllowedValues('min_access_level', [null, 10, 20, 30, 40, 50])
        ;
        $resolver->setDefined('top_level_only')
            ->setAllowedTypes('top_level_only', 'bool')
            ->setNormalizer('top_level_only', $booleanNormalizer)
        ;
        return $resolver;
    }
}
