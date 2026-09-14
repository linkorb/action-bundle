<?php

declare(strict_types=1);

namespace LinkORB\Bundle\ActionBundle\Security\Voter;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter for the action-bundle's demo/example ActionPacks.
 *
 * Grants any authenticated user access to demo, location, and weather actions.
 * Production ActionPacks should have their own voters in their respective bundles.
 *
 * @extends Voter<string, string>
 */
class ActionDemoVoter extends Voter
{
    private const DEMO_SUBJECTS = ['demo', 'demo-admin', 'location', 'weather', 'infra', 'procedures'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return is_string($subject) && in_array($subject, self::DEMO_SUBJECTS, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if ($user === null) {
            return false;
        }

        if ($subject === 'demo-admin') {
            if (method_exists($user, 'isGroupMember') && $user->isGroupMember('infra-admin-role')) {
                $vote?->addReason('Infrastructure admin team members can execute demo-admin actions');

                return true;
            }

            return false;
        }

        $vote?->addReason('Demo/example actions are accessible to any authenticated user');

        return true;
    }
}
