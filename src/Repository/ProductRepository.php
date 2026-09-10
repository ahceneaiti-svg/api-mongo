<?php

declare(strict_types=1);

namespace App\Repository;

use App\Document\Product;
use Doctrine\Bundle\MongoDBBundle\Repository\ServiceDocumentRepository;
use Doctrine\ODM\MongoDB\Query\Builder;
use Doctrine\Persistence\ManagerRegistry;
use MongoDB\BSON\Regex;

/**
 * @extends ServiceDocumentRepository<Product>
 */
class ProductRepository extends ServiceDocumentRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @param array{q?: ?string, category?: ?string, brand?: ?string, active?: ?bool} $filters
     *
     * @return list<Product>
     */
    public function search(array $filters, int $page, int $limit): array
    {
        $qb = $this->buildFilteredQuery($filters)
            ->sort('createdAt', 'desc')
            ->skip(($page - 1) * $limit)
            ->limit($limit);

        return array_values($qb->getQuery()->execute()->toArray());
    }

    /**
     * @param array{q?: ?string, category?: ?string, brand?: ?string, active?: ?bool} $filters
     */
    public function countFiltered(array $filters): int
    {
        return $this->buildFilteredQuery($filters)->count()->getQuery()->execute();
    }

    /**
     * @param array{q?: ?string, category?: ?string, brand?: ?string, active?: ?bool} $filters
     */
    private function buildFilteredQuery(array $filters): Builder
    {
        $qb = $this->createQueryBuilder();

        $q = $filters['q'] ?? null;
        if (\is_string($q) && '' !== trim($q)) {
            $regex = new Regex(preg_quote(trim($q), '/'), 'i');
            $qb->addAnd(
                $qb->expr()->addOr(
                    $qb->expr()->field('name')->equals($regex),
                    $qb->expr()->field('sku')->equals($regex),
                    $qb->expr()->field('brand')->equals($regex),
                    $qb->expr()->field('description')->equals($regex),
                )
            );
        }

        if (!empty($filters['category'])) {
            $qb->field('category')->equals($filters['category']);
        }

        if (!empty($filters['brand'])) {
            $qb->field('brand')->equals($filters['brand']);
        }

        if (isset($filters['active']) && null !== $filters['active']) {
            $qb->field('active')->equals((bool) $filters['active']);
        }

        return $qb;
    }
}
