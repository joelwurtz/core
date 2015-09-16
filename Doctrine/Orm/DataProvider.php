<?php

/*
 * This file is part of the DunglasApiBundle package.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Dunglas\ApiBundle\Doctrine\Orm;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrineOrmPaginator;
use Doctrine\ORM\QueryBuilder;
use Dunglas\ApiBundle\Doctrine\Orm\Filter\FilterInterface;
use Dunglas\ApiBundle\Model\DataProviderInterface;
use Dunglas\ApiBundle\Api\ResourceInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Data provider for the Doctrine ORM.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class DataProvider implements DataProviderInterface
{
    /**
     * @var ManagerRegistry
     */
    private $managerRegistry;
    /**
     * @var string|null
     */
    private $order;
    /**
     * @var string
     */
    private $pageParameter;
    /**
     * @var int
     */
    private $itemsPerPage;
    /**
     * @var bool
     */
    private $enableClientRequestItemsPerPage;
    /**
     * @var string
     */
    private $itemsPerPageParameter;

    /**
     * @param ManagerRegistry $managerRegistry
     * @param string|null     $order
     * @param string          $pageParameter
     * @param int             $itemsPerPage
     * @param bool            $enableClientRequestItemsPerPage
     * @param string          $itemsPerPageParameter
     */
    public function __construct(
        ManagerRegistry $managerRegistry,
        $order,
        $pageParameter,
        $itemsPerPage,
        $enableClientRequestItemsPerPage,
        $itemsPerPageParameter
    ) {
        $this->managerRegistry = $managerRegistry;
        $this->order = $order;
        $this->pageParameter = $pageParameter;
        $this->itemsPerPage = $itemsPerPage;
        $this->enableClientRequestItemsPerPage = $enableClientRequestItemsPerPage;
        $this->itemsPerPageParameter = $itemsPerPageParameter;
    }

    /**
     * {@inheritdoc}
     */
    public function getItem(ResourceInterface $resource, $id, $fetchData = false)
    {
        $entityClass = $resource->getEntityClass();
        $manager = $this->managerRegistry->getManagerForClass($entityClass);

        if ($fetchData || !method_exists($manager, 'getReference')) {
            return $manager->find($entityClass, $id);
        }

        return $manager->getReference($entityClass, $id);
    }

    /**
     * {@inheritdoc}
     */
    public function getCollection(ResourceInterface $resource, Request $request)
    {
        $entityClass = $resource->getEntityClass();

        $manager = $this->managerRegistry->getManagerForClass($resource->getEntityClass());
        $repository = $manager->getRepository($entityClass);

        $page = (int) $request->get($this->pageParameter, 1);

        $itemsPerPage = $this->itemsPerPage;
        if ($this->enableClientRequestItemsPerPage && $requestedItemsPerPage = $request->get($this->itemsPerPageParameter)) {
            $itemsPerPage = (int) $requestedItemsPerPage;
        }

        $queryBuilder = $repository
            ->createQueryBuilder('o')
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage)
        ;

        foreach ($resource->getFilters() as $filter) {
            if ($filter instanceof FilterInterface) {
                $filter->apply($resource, $queryBuilder, $request);
            }
        }

        $classMetaData = $manager->getClassMetadata($entityClass);
        $identifiers = $classMetaData->getIdentifier();

        foreach ($classMetaData->getAssociationNames() as $i => $association) {
            $mapping = $classMetaData->associationMappings[$association];

            if (ClassMetadataInfo::FETCH_EAGER === $mapping['fetch']) {
                $queryBuilder->leftJoin('o.'.$association, 'a'.$i);
                $queryBuilder->addSelect('a'.$i);
            }
        }

        if (null !== $this->order && 1 === count($identifiers)) {
            $identifier = $identifiers[0];
            $queryBuilder->addOrderBy('o.'.$identifier, $this->order);
        }

        return $this->getPaginator($queryBuilder);
    }

    /**
     * Gets the paginator.
     *
     * @param QueryBuilder $queryBuilder
     *
     * @return Paginator
     */
    protected function getPaginator(QueryBuilder $queryBuilder)
    {
        $doctrineOrmPaginator = new DoctrineOrmPaginator($queryBuilder);
        // Disable output walkers by default (performance)
        $doctrineOrmPaginator->setUseOutputWalkers(false);

        return new Paginator($doctrineOrmPaginator);
    }

    /**
     * {@inheritdoc}
     */
    public function supports(ResourceInterface $resource)
    {
        return null !== $this->managerRegistry->getManagerForClass($resource->getEntityClass());
    }
}
