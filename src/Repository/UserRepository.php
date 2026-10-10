<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function findOneByUsername(string $username): ?User
    {
        return $this->findByUsernameInsensitive(['username' => $username])[0] ?? null;
    }

    /**
     * Recherche insensible à la casse, utilisée par la contrainte UniqueEntity sur le pseudo.
     *
     * @param array{username: string} $criteria
     *
     * @return list<User>
     */
    public function findByUsernameInsensitive(array $criteria): array
    {
        return $this->createQueryBuilder('u')
            ->where('LOWER(u.username) = :username')
            ->setParameter('username', mb_strtolower(trim((string) $criteria['username'])))
            ->getQuery()
            ->getResult();
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
