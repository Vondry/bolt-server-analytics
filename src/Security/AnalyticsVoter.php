<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Throwable;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;

/**
 * Who may see the statistics (VIEW) and who may delete them (DELETE): the
 * roles of `access.view` and `access.delete`, any one of them, through the
 * role hierarchy (Bolt's ROLE_DEVELOPER includes ROLE_ADMIN). Deleting also
 * needs a role that sees them.
 *
 * Both need a login, like the rest of Bolt's backend: a full one, or one
 * restored from the "remember me" cookie (Bolt's own access control asks for
 * no more). Otherwise the menu item and the dashboard card would be gone
 * whenever a session was restored from the cookie, with nothing to say why.
 * Deleting is guarded besides by a CSRF token of its own, a same-origin
 * check and a confirmation.
 *
 * With a broken config the default roles apply (ROLE_ADMIN), so an
 * administrator still gets to the page that says what is wrong.
 *
 * @extends Voter<string, mixed>
 */
final class AnalyticsVoter extends Voter
{
    public const VIEW = 'request_analytics_view';
    public const DELETE = 'request_analytics_delete';

    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW || $attribute === self::DELETE;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (! $this->accessDecisionManager->decide($token, ['IS_AUTHENTICATED_REMEMBERED'])) {
            return false;
        }

        try {
            $config = $this->configLoader->load();
            $view = $config->viewRoles;
            $delete = $config->deleteRoles;
        } catch (Throwable) {
            $view = $delete = RequestAnalyticsConfig::ACCESS_ROLES_DEFAULT;
        }

        return $this->hasAny($token, $view) && ($attribute === self::VIEW || $this->hasAny($token, $delete));
    }

    /**
     * One role at a time: with the "unanimous" strategy a single decide() with
     * several roles would need all of them.
     *
     * @param list<string> $roles
     */
    private function hasAny(TokenInterface $token, array $roles): bool
    {
        return array_any($roles, fn ($role): bool => $this->accessDecisionManager->decide($token, [$role]));
    }
}
