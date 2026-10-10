<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener]
final class LoginSubscriber
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        // Uniquement pour les connexions interactives (pas pour chaque requête API avec un jeton)
        if ('main' !== $event->getFirewallName()) {
            return;
        }

        $user = $event->getUser();
        if ($user instanceof User) {
            $user->setLastLoginAt(new \DateTimeImmutable());
            $this->em->flush();
        }
    }
}
