<?php

/**
 * PolicyEngine unit tests.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Tests\Unit\Policy;

use WPNerve\Policy\PolicyDecision;
use WPNerve\Policy\PolicyEngine;
use WPNerve\Policy\RiskLevel;
use WPNerve\Tests\Fixtures\WPState;
use WPNerve\Tests\Unit\TestCase;

final class PolicyEngineTest extends TestCase
{
    private PolicyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new PolicyEngine();
    }

    public function testDiscoverableRequiresWpNervePrefix(): void
    {
        $ability = $this->makeAbility('core/some-ability');

        self::assertFalse($this->engine->isDiscoverable($ability));
    }

    public function testDiscoverableRequiresEnabledByDefault(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'meta' => $this->wpNerveMeta(array('enabled_by_default' => false)),
        ));

        self::assertFalse($this->engine->isDiscoverable($ability));
    }

    public function testDiscoverableRequiresCapability(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status');

        WPState::$userCan = false;

        self::assertFalse($this->engine->isDiscoverable($ability));

        WPState::$userCan = true;

        self::assertTrue($this->engine->isDiscoverable($ability));
    }

    public function testDiscoverableRejectsNonStringCapability(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'meta' => $this->wpNerveMeta(array('capability' => array('not', 'a', 'string'))),
        ));

        self::assertFalse($this->engine->isDiscoverable($ability));
    }

    public function testDiscoverableAppliesFilter(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status');

        add_filter('wp_nerve_ability_is_discoverable', static fn (): bool => false, 10, 2);

        self::assertFalse($this->engine->isDiscoverable($ability));
    }

    public function testAuthorizeDeniesWhenNotExposed(): void
    {
        $ability = $this->makeAbility('core/some-ability');

        $decision = $this->engine->authorize($ability);

        self::assertInstanceOf(PolicyDecision::class, $decision);
        self::assertFalse($decision->allowed);
        self::assertSame('ability_not_exposed', $decision->code);
    }

    public function testAuthorizeHidesDestructiveRiskByDefault(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'meta' => $this->wpNerveMeta(array('risk' => RiskLevel::Destructive->value)),
        ));

        $decision = $this->engine->authorize($ability);

        self::assertFalse($decision->allowed);
        self::assertSame('ability_not_exposed', $decision->code);
    }

    public function testAuthorizeHidesPrivilegedRiskByDefault(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'meta' => $this->wpNerveMeta(array('risk' => RiskLevel::Privileged->value)),
        ));

        $decision = $this->engine->authorize($ability);

        self::assertFalse($decision->allowed);
        self::assertSame('ability_not_exposed', $decision->code);
    }

    public function testDestructiveAuthorizedWhenRiskClassEnabled(): void
    {
        WPState::$options['wp_nerve_enabled_risk_classes'] = array('read', 'write', 'destructive');

        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'meta' => $this->wpNerveMeta(array('risk' => RiskLevel::Destructive->value)),
        ));

        $decision = $this->engine->authorize($ability);

        self::assertTrue($decision->allowed);
        self::assertTrue($this->engine->isDiscoverable($ability));
    }

    public function testPrivilegedAuthorizedWhenRiskClassEnabledViaFilter(): void
    {
        add_filter('wp_nerve_enabled_risk_classes', static fn (array $classes): array => array_merge($classes, array('privileged')));

        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'meta' => $this->wpNerveMeta(array('risk' => RiskLevel::Privileged->value)),
        ));

        $decision = $this->engine->authorize($ability);

        self::assertTrue($decision->allowed);
    }

    public function testDisabledAbilityCanBeEnabledViaFilter(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'meta' => $this->wpNerveMeta(array('enabled_by_default' => false)),
        ));

        self::assertFalse($this->engine->isDiscoverable($ability));

        add_filter('wp_nerve_ability_is_enabled', static fn (): bool => true, 10, 2);

        self::assertTrue($this->engine->isDiscoverable($ability));
    }

    public function testAuthorizeAllowsReadRisk(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status');

        $decision = $this->engine->authorize($ability);

        self::assertTrue($decision->allowed);
        self::assertSame('allowed', $decision->code);
    }

    public function testAuthorizeAllowsWriteRisk(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'meta' => $this->wpNerveMeta(array('risk' => RiskLevel::Write->value)),
        ));

        $decision = $this->engine->authorize($ability);

        self::assertTrue($decision->allowed);
    }

    public function testAuthorizeFilterCanOverrideDecision(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status');

        add_filter('wp_nerve_policy_decision', static function (PolicyDecision $decision): PolicyDecision {
            return PolicyDecision::deny('custom_denial', 'Blocked by filter.');
        }, 10, 3);

        $decision = $this->engine->authorize($ability);

        self::assertFalse($decision->allowed);
        self::assertSame('custom_denial', $decision->code);
    }

    public function testAuthorizeRejectsInvalidFilterReturn(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status');

        add_filter('wp_nerve_policy_decision', static fn (): string => 'not a decision', 10, 3);

        $decision = $this->engine->authorize($ability);

        self::assertFalse($decision->allowed);
        self::assertSame('invalid_policy_decision', $decision->code);
    }

    public function testRiskFallsBackToPrivilegedWhenMissing(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'meta' => $this->wpNerveMeta(array('risk' => null)),
        ));

        self::assertSame(RiskLevel::Privileged, $this->engine->risk($ability));
    }

    public function testRiskFallsBackToPrivilegedWhenUnknown(): void
    {
        $ability = $this->makeAbility('wp-nerve/site-status', array(
            'meta' => $this->wpNerveMeta(array('risk' => 'definitely-not-a-risk')),
        ));

        self::assertSame(RiskLevel::Privileged, $this->engine->risk($ability));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function wpNerveMeta(array $overrides): array
    {
        return array(
            'public'       => true,
            'show_in_rest' => false,
            'annotations'  => array('readonly' => true, 'destructive' => false, 'idempotent' => true),
            'wp_nerve'     => array_merge(
                array(
                    'risk'               => 'read',
                    'capability'         => 'edit_posts',
                    'enabled_by_default' => true,
                ),
                $overrides
            ),
        );
    }
}
