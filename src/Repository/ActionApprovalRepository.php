<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use LinkORB\Bundle\ActionBundle\Entity\ActionApproval;

/**
 * @extends ServiceEntityRepository<ActionApproval>
 *
 * @method ActionApproval|null find($id, $lockMode = null, $lockVersion = null)
 * @method ActionApproval|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method ActionApproval[]    findAll()
 * @method ActionApproval[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
class ActionApprovalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionApproval::class);
    }

    public function findOneByXuid(string $xuid): ?ActionApproval
    {
        return $this->findOneBy(['xuid' => $xuid]);
    }

    /**
     * @return ActionApproval[]
     */
    public function findAllPending(): array
    {
        return $this->findBy(
            ['status' => ActionApproval::STATUS_PENDING],
            ['createdAt' => 'DESC'],
        );
    }

    /**
     * @return ActionApproval[]
     */
    public function findWithFilters(?string $status = null, ?string $caller = null, ?string $actionName = null): array
    {
        $qb = $this->createQueryBuilder('a');

        if ($status !== null) {
            $qb->andWhere('a.status = :status')
               ->setParameter('status', $status);
        }

        if ($caller !== null) {
            $qb->andWhere('a.callerIdentifier = :caller')
               ->setParameter('caller', $caller);
        }

        if ($actionName !== null) {
            $qb->andWhere('a.actionName = :actionName')
               ->setParameter('actionName', $actionName);
        }

        $qb->orderBy('a.createdAt', 'DESC');

        /** @var ActionApproval[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Re-read the entity from the database, bypassing Doctrine's identity map cache.
     */
    public function refresh(ActionApproval $approval): ActionApproval
    {
        $em = $this->getEntityManager();
        $em->refresh($approval);

        return $approval;
    }
}
