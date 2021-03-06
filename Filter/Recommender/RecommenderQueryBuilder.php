<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticRecommenderBundle\Filter\Recommender;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\MasterSlaveConnection;
use Doctrine\ORM\EntityManager;
use Mautic\LeadBundle\Segment\RandomParameterName;
use MauticPlugin\MauticRecommenderBundle\Entity\Recommender;
use MauticPlugin\MauticRecommenderBundle\Filter\QueryBuilder;
use MauticPlugin\MauticRecommenderBundle\Filter\Recommender\Decorator\Decorator;
use MauticPlugin\MauticRecommenderBundle\Filter\Recommender\Decorator\RecommenderOrderBy;
use MauticPlugin\MauticRecommenderBundle\Filter\Segment\FilterFactory;
use MauticPlugin\MauticRecommenderBundle\Service\RecommenderToken;

class RecommenderQueryBuilder
{
    /** @var EntityManager */
    private $entityManager;

    /** @var RandomParameterName */
    private $randomParameterName;

    /**
     * @var FilterFactory
     */
    private $filterFactory;

    /**
     * @var Decorator
     */
    private $decorator;

    /**
     * @var RecommenderOrderBy
     */
    private $recommenderOrderBy;

    /**
     * ContactSegmentQueryBuilder constructor.
     *
     * @param EntityManager       $entityManager
     * @param RandomParameterName $randomParameterName
     * @param FilterFactory       $filterFactory
     * @param Decorator           $decorator
     * @param RecommenderOrderBy  $recommenderOrderBy
     */
    public function __construct(
        EntityManager $entityManager,
        RandomParameterName $randomParameterName,
        FilterFactory $filterFactory,
        Decorator $decorator,
        RecommenderOrderBy $recommenderOrderBy
    ) {
        $this->entityManager       = $entityManager;
        $this->randomParameterName = $randomParameterName;
        $this->filterFactory       = $filterFactory;
        $this->decorator           = $decorator;
        $this->recommenderOrderBy = $recommenderOrderBy;
    }

    /**
     * @param RecommenderToken $recommenderToken
     *
     * @return QueryBuilder
     */
    public function assembleContactQueryBuilder(RecommenderToken $recommenderToken)
    {
        /** @var Connection $connection */
        $connection = $this->entityManager->getConnection();
        if ($connection instanceof MasterSlaveConnection) {
            // Prefer a slave connection if available.
            $connection->connect('slave');
        }


        $queryBuilder = new QueryBuilder($connection);

        $queryBuilder->select('l.item_id as id')->from(MAUTIC_TABLE_PREFIX.'recommender_event_log', 'l');
        if ($recommenderToken->getUserId()) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('l.lead_id', ':leadId'))
                ->setParameter('leadId', $recommenderToken->getUserId());

        }

        $recombeeFilters = $recommenderToken->getRecommender()->getFilters();
        foreach ($recombeeFilters as $filter) {
            $filter       = $this->filterFactory->getContactSegmentFilter($filter, $this->decorator);
            $queryBuilder = $filter->applyQuery($queryBuilder);
        }

        $this->setOrderBy($queryBuilder, $recommenderToken->getRecommender());
        $queryBuilder->groupBy('l.item_id');
        $queryBuilder->setMaxResults($recommenderToken->getLimit());

        return $queryBuilder;
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @param Recommender  $recommender
     */
    private function setOrderBy(QueryBuilder $queryBuilder, Recommender $recommender)
    {
        $tableorder = $recommender->getTableOrder();

        if (empty($tableorder['column'])) {
            return;
        }
        $orderBy = $this->recommenderOrderBy->getDictionary($queryBuilder, $tableorder['column']);

        if (!empty($tableorder['function'])) {
            $orderBy = $tableorder['function'].'('.$orderBy.')';
        }
        $queryBuilder->orderBy($orderBy, $tableorder['direction']);
    }


    /**
     * Generate a unique parameter name.
     *
     * @return string
     */
    private function generateRandomParameterName()
    {
        return $this->randomParameterName->generateRandomParameterName();
    }

}
