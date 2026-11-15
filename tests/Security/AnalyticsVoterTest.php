<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Strategy\UnanimousStrategy;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;
use TomVondracek\RequestAnalytics\Security\AnalyticsVoter;
use TomVondracek\RequestAnalytics\Tests\Support\Project;

/**
 * With Bolt's role hierarchy and Symfony's own voters, as in a project.
 */
final class AnalyticsVoterTest extends TestCase
{
    /**
     * config/packages/security.yaml of Bolt.
     */
    private const BOLT_ROLES = [
        'ROLE_DEVELOPER' => ['ROLE_ADMIN', 'ROLE_CAN_SWITCH_USER'],
        'ROLE_ADMIN' => ['ROLE_CHIEF_EDITOR'],
        'ROLE_CHIEF_EDITOR' => ['ROLE_EDITOR'],
        'ROLE_EDITOR' => ['ROLE_USER'],
        'ROLE_USER' => [],
        'ROLE_WEBSERVICE' => [],
    ];

    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testByDefaultAdministratorsAndDevelopersOnly(): void
    {
        foreach (['ROLE_DEVELOPER', 'ROLE_ADMIN'] as $role) {
            self::assertSame([true, true], $this->may($this->login($role)), $role);
        }

        foreach (['ROLE_CHIEF_EDITOR', 'ROLE_EDITOR', 'ROLE_USER', 'ROLE_WEBSERVICE'] as $role) {
            self::assertSame([false, false], $this->may($this->login($role)), $role);
        }
    }

    /**
     * A "remember me" cookie lives for weeks and travels with every request:
     * not enough, Symfony asks to log in again.
     */
    public function testNeedsAFullLogin(): void
    {
        $remembered = new RememberMeToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main');

        self::assertSame([false, false], $this->may($remembered));
    }

    public function testRolesComeFromTheConfig(): void
    {
        $this->project->configure([
            'access' => [
                'view' => ['ROLE_ADMIN', 'ROLE_CHIEF_EDITOR'],
                'delete' => ['ROLE_DEVELOPER'],
            ],
        ]);

        self::assertSame([true, true], $this->may($this->login('ROLE_DEVELOPER')));
        self::assertSame([true, false], $this->may($this->login('ROLE_ADMIN')), 'sees, but may not delete');
        self::assertSame([true, false], $this->may($this->login('ROLE_CHIEF_EDITOR')));
        self::assertSame([false, false], $this->may($this->login('ROLE_EDITOR')));
    }

    /**
     * Deleting happens on the Analytics page: a role that may only delete
     * does not get there.
     */
    public function testDeletingAlsoNeedsARoleThatSees(): void
    {
        $this->project->configure([
            'access' => [
                'view' => ['ROLE_DEVELOPER'],
                'delete' => ['ROLE_ADMIN'],
            ],
        ]);

        self::assertSame([false, false], $this->may($this->login('ROLE_ADMIN')));
    }

    /**
     * Administrators still get to the page that says what is wrong.
     */
    public function testABrokenConfigFallsBackToTheDefault(): void
    {
        $this->project->configure([
            'enabled' => 'maybe',
        ]);

        self::assertSame([true, true], $this->may($this->login('ROLE_ADMIN')));
        self::assertSame([false, false], $this->may($this->login('ROLE_EDITOR')));
    }

    public function testOtherAttributesAreNotItsBusiness(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter()->vote($this->login('ROLE_ADMIN'), null, ['dashboard']));
    }

    /**
     * With the "unanimous" strategy too: any one of the roles is enough.
     */
    public function testAnyOneOfTheRolesWithEveryStrategy(): void
    {
        $this->project->configure([
            'access' => [
                'view' => ['ROLE_DEVELOPER', 'ROLE_CHIEF_EDITOR'],
            ],
        ]);

        self::assertSame([true, false], $this->may($this->login('ROLE_CHIEF_EDITOR'), new UnanimousStrategy()));
    }

    /**
     * @return array{bool, bool} VIEW and DELETE
     */
    private function may(TokenInterface $token, ?UnanimousStrategy $strategy = null): array
    {
        $voter = $this->voter($strategy);

        return [
            $voter->vote($token, null, [AnalyticsVoter::VIEW]) === VoterInterface::ACCESS_GRANTED,
            $voter->vote($token, null, [AnalyticsVoter::DELETE]) === VoterInterface::ACCESS_GRANTED,
        ];
    }

    private function voter(?UnanimousStrategy $strategy = null): AnalyticsVoter
    {
        return new AnalyticsVoter($this->project->configLoader(), new AccessDecisionManager([
            new RoleHierarchyVoter(new RoleHierarchy(self::BOLT_ROLES)),
            new AuthenticatedVoter(new AuthenticationTrustResolver()),
        ], $strategy));
    }

    private function login(string $role): UsernamePasswordToken
    {
        return new UsernamePasswordToken(new InMemoryUser('user', null, [$role]), 'main', [$role]);
    }
}
