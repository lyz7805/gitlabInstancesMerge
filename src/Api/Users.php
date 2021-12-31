<?php

namespace App\Api;

use Gitlab\Api\Users as ApiUsers;
use Symfony\Component\OptionsResolver\Options;

class Users extends ApiUsers
{
    /**
     * @param array $parameters {
     *
     *     @var string             $search         search for user by email or username
     *     @var string             $username       lookup for user by username
     *     @var bool               $external       search for external users only
     *     @var string             $extern_uid     lookup for users by external uid
     *     @var string             $provider       lookup for users by provider
     *     @var \DateTimeInterface $created_before return users created before the given time (inclusive)
     *     @var \DateTimeInterface $created_after  return users created after the given time (inclusive)
     *     @var bool               $active         Return only active users. It does not support filtering inactive users.
     *     @var bool               $blocked        Return only blocked users. It does not support filtering non-blocked users.
     *     @var string             $order_by       Return users ordered by id, name, username, created_at, or updated_at fields. Default is id
     *     @var string             $sort           Return users sorted in asc or desc order. Default is desc
     *     @var bool               $two_factor     Filter users by Two-factor authentication. Filter values are enabled or disabled. By default it returns all users
     *     @var bool               $without_projects    Filter users without projects. Default is false, which means that all users are returned, with and without projects.
     *     @var int                $admins         Return only admin users. Default is false
     * }
     *
     * @return mixed
     */
    public function all(array $parameters = [])
    {
        $resolver = $this->createOptionsResolver();
        $booleanNormalizer = function (Options $resolver, $value): string {
            return $value ? 'true' : 'false';
        };
        $datetimeNormalizer = function (Options $resolver, \DateTimeInterface $value): string {
            return $value->format('c');
        };

        $resolver->setDefined('search');
        $resolver->setDefined('username');
        $resolver->setDefined('external')
            ->setAllowedTypes('external', 'bool')
        ;
        $resolver->setDefined('extern_uid');
        $resolver->setDefined('provider');
        $resolver->setDefined('created_before')
            ->setAllowedTypes('created_before', \DateTimeInterface::class)
            ->setNormalizer('created_before', $datetimeNormalizer)
        ;
        $resolver->setDefined('created_after')
            ->setAllowedTypes('created_after', \DateTimeInterface::class)
            ->setNormalizer('created_after', $datetimeNormalizer)
        ;
        $resolver->setDefined('active')
            ->setAllowedTypes('active', 'bool')
            ->setAllowedValues('active', true)
        ;
        $resolver->setDefined('blocked')
            ->setAllowedTypes('blocked', 'bool')
            ->setAllowedValues('blocked', true)
        ;
        $resolver->setDefined('order_by')
            ->setAllowedValues('order_by', ['id', 'name', 'username', 'created_at', 'updated_at'])
            ->setDefault('order_by', 'id')
        ;
        $resolver->setDefined('sort')
            ->setAllowedValues('sort', ['asc', 'desc'])
        ;
        $resolver->setDefined('two_factor');
        $resolver->setDefined('without_projects')
            ->setAllowedTypes('without_projects', 'bool')
            ->setNormalizer('without_projects', $booleanNormalizer)
            ->setDefault('without_projects', false)
        ;
        $resolver->setDefined('admins')
            ->setAllowedTypes('admins', 'bool')
            ->setNormalizer('admins', $booleanNormalizer)
            ->setDefault('admins', false)
        ;

        return $this->get('users', $resolver->resolve($parameters));
    }
}
