<?php

namespace AppBundle\Repository;

/**
 * PageRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class PageRepository extends \Doctrine\ORM\EntityRepository
{
    /**
     * @param string $visibility
     * @return array
     */
    public function findOrderedBySort($visibility = '')
    {
        $repository = $this->getEntityManager()->getRepository('AppBundle:Page');
        $builder = $repository->createQueryBuilder('p');
        $builder->add('select', 'p');
        if ($visibility) {
            $builder->where('p.visibility = "'.$visibility.'"');
        }
        $builder->addOrderBy('p.sort, p.name');
        $query = $builder->getQuery();
        return $query->getResult();
    }
}
