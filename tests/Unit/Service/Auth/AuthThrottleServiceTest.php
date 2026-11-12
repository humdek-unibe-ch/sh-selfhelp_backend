<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\AuthThrottleService;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The brute-force counters of login, two-factor and password recovery: one per
 * account and one per visitor address. The address only counts when the
 * backend really sees the visitor's address; a trusted proxy that does not say
 * who the visitor is would otherwise put every visitor in one bucket.
 */
#[TestGroup('security')]
final class AuthThrottleServiceTest extends TestCase
{
    private const LIMIT = 3;
    private const VISITOR = '203.0.113.9';
    private const OTHER_VISITOR = '203.0.113.10';
    private const PROXY = '10.0.0.5';

    /** @var array<string> */
    private array $trustedProxies;

    /** @var array<string, RateLimiterFactoryInterface> */
    private array $factories = [];

    protected function setUp(): void
    {
        // Request keeps the trusted proxies in a process-wide static.
        $this->trustedProxies = Request::getTrustedProxies();
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    protected function tearDown(): void
    {
        // The framework's default header set; a booting kernel sets its own again.
        Request::setTrustedProxies(
            $this->trustedProxies,
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO
        );
    }

    // -- which address counts ------------------------------------------------

    public function testADirectCallIsCountedByItsOwnAddress(): void
    {
        self::assertSame(self::VISITOR, $this->service()->visitorAddress($this->request(self::VISITOR)));
    }

    public function testAForwardedForHeaderFromAnUntrustedPeerIsIgnored(): void
    {
        // Anyone can add the header: only a trusted proxy may say who the visitor is.
        $request = $this->request(self::VISITOR, forwardedFor: self::OTHER_VISITOR);

        self::assertSame(self::VISITOR, $this->service()->visitorAddress($request));
    }

    public function testATrustedProxyPassesTheVisitorAddress(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);

        self::assertSame(
            self::VISITOR,
            $this->service()->visitorAddress($this->request(self::PROXY, forwardedFor: self::VISITOR))
        );
        self::assertSame(
            self::VISITOR,
            $this->service()->visitorAddress($this->request(self::PROXY, forwardedFor: self::VISITOR . ', 10.0.0.7')),
            'A second trusted hop in the chain does not hide the visitor'
        );
    }

    public function testATrustedProxyThatDoesNotSayWhoTheVisitorIsIsNotCounted(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);

        self::assertNull($this->service()->visitorAddress($this->request(self::PROXY)), 'No header');
        self::assertNull(
            $this->service()->visitorAddress($this->request(self::PROXY, forwardedFor: 'unknown')),
            'Not an address'
        );

        // A trusted proxy that is itself listed by a public address is no visitor either.
        Request::setTrustedProxies(['198.51.100.0/24'], Request::HEADER_X_FORWARDED_FOR);
        self::assertNull($this->service()->visitorAddress($this->request('198.51.100.7')), 'Public proxy, no header');
    }

    public function testAPrivateOrReservedAddressIsInfrastructureNotAVisitor(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);
        $service = $this->service();

        // Direct calls from the container network, the LAN or the machine itself.
        foreach (['172.18.0.5', '192.168.1.20', '127.0.0.1', '::1', 'fe80::1', '::ffff:10.0.0.1'] as $peer) {
            self::assertNull($service->visitorAddress($this->request($peer)), $peer);
        }

        // A proxy in front of the proxy: the "visitor" it forwards is infrastructure,
        // shared by everyone behind it, so counting it would lock them all out together.
        foreach (['10.0.0.9', '172.18.0.2', '192.168.1.20'] as $forwarded) {
            self::assertNull(
                $service->visitorAddress($this->request(self::PROXY, forwardedFor: $forwarded)),
                $forwarded
            );
        }
    }

    public function testAnIpv6VisitorIsCountedPerNetwork(): void
    {
        $service = $this->service();

        $first = $service->visitorAddress($this->request('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        $sameNetwork = $service->visitorAddress($this->request('2001:db8:1:2:1111:2222:3333:4444'));
        $otherNetwork = $service->visitorAddress($this->request('2001:db8:1:3:aaaa:bbbb:cccc:dddd'));

        self::assertNotNull($first);
        self::assertSame($first, $sameNetwork, 'Rotating inside one /64 does not dodge the limit');
        self::assertNotSame($first, $otherNetwork);
    }

    public function testACallWithoutAPeerAddressIsNotCounted(): void
    {
        self::assertNull($this->service()->visitorAddress($this->request('')));
    }

    // -- login ---------------------------------------------------------------

    public function testFailuresOverManyAccountsFillTheAddressWindow(): void
    {
        $service = $this->service();
        $visitor = $this->request(self::VISITOR);

        foreach (['one@selfhelp.test', 'two@selfhelp.test', 'three@selfhelp.test'] as $email) {
            $attempt = $service->login($visitor, $email);
            self::assertNull($attempt->blockedBy());
            $attempt->failed();
        }

        // Each account has one failure, but the address already has three.
        self::assertNotNull($service->login($visitor, 'four@selfhelp.test')->blockedBy());
        // Another visitor is not affected.
        self::assertNull($service->login($this->request(self::OTHER_VISITOR), 'four@selfhelp.test')->blockedBy());
    }

    public function testASuccessClearsTheAccountButNeverTheAddress(): void
    {
        $service = $this->service();
        $visitor = $this->request(self::VISITOR);

        $attempt = $service->login($visitor, 'One@Selfhelp.test');
        $attempt->failed();
        $attempt->failed();
        self::assertSame(self::LIMIT - 2, $this->remaining('auth_login', 'login:one@selfhelp.test'));
        self::assertSame(self::LIMIT - 2, $this->remaining('auth_login_ip', 'login-ip:' . self::VISITOR));

        $attempt->succeeded();

        self::assertSame(self::LIMIT, $this->remaining('auth_login', 'login:one@selfhelp.test'));
        self::assertSame(
            self::LIMIT - 2,
            $this->remaining('auth_login_ip', 'login-ip:' . self::VISITOR),
            'A valid account must not wipe the address counter'
        );
    }

    public function testWithoutAVisitorAddressOnlyTheAccountIsCounted(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);
        $service = $this->service();
        $viaProxy = $this->request(self::PROXY);

        foreach (['one', 'two', 'three', 'four'] as $name) {
            $attempt = $service->login($viaProxy, $name . '@selfhelp.test');
            self::assertNull($attempt->blockedBy());
            $attempt->failed();
        }

        // Counting the proxy's own address would have blocked everybody by now.
        self::assertNull($service->login($viaProxy, 'five@selfhelp.test')->blockedBy());
        // The account counter still works.
        $attempt = $service->login($viaProxy, 'one@selfhelp.test');
        $attempt->failed();
        $attempt->failed();
        self::assertNotNull($service->login($viaProxy, 'one@selfhelp.test')->blockedBy());
    }

    // -- two-factor ----------------------------------------------------------

    public function testTwoFactorCountsPerUserAndPerAddress(): void
    {
        $service = $this->service();
        $visitor = $this->request(self::VISITOR);

        foreach ([11, 12, 13] as $userId) {
            $attempt = $service->twoFactor($visitor, $userId);
            self::assertNull($attempt->blockedBy());
            $attempt->failed();
        }

        self::assertNotNull($service->twoFactor($visitor, 14)->blockedBy());
        self::assertNull($service->twoFactor($this->request(self::OTHER_VISITOR), 14)->blockedBy());

        $service->twoFactor($visitor, 11)->succeeded();
        self::assertSame(self::LIMIT, $this->remaining('auth_two_factor', '2fa:11'));
        self::assertSame(0, $this->remaining('auth_two_factor_ip', '2fa-ip:' . self::VISITOR));
    }

    // -- recovery ------------------------------------------------------------

    public function testRecoveryIsCountedPerAddressAndPerEmail(): void
    {
        $service = $this->service();
        $visitor = $this->request(self::VISITOR);

        // Three different emails from one address use up that address.
        self::assertNull($service->recoveryBlockedBy($visitor, 'one@selfhelp.test'));
        self::assertNull($service->recoveryBlockedBy($visitor, 'two@selfhelp.test'));
        self::assertNull($service->recoveryBlockedBy($visitor, 'three@selfhelp.test'));
        self::assertNotNull($service->recoveryBlockedBy($visitor, 'four@selfhelp.test'));

        // One email asked for from many addresses uses up that email.
        $other = $this->request(self::OTHER_VISITOR);
        self::assertNull($service->recoveryBlockedBy($other, 'ONE@selfhelp.test'), 'Second request for one@');
        self::assertNull($service->recoveryBlockedBy($other, 'one@selfhelp.test'), 'Third request for one@');
        self::assertNotNull($service->recoveryBlockedBy($other, 'one@selfhelp.test'), 'Fourth request for one@');
    }

    // -- helpers -------------------------------------------------------------

    private function request(string $peer, ?string $forwardedFor = null): Request
    {
        $server = ['REMOTE_ADDR' => $peer];
        if ($forwardedFor !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }

        return Request::create('/cms-api/v1/auth/login', 'POST', [], [], [], $server);
    }

    /**
     * A service whose six counters all allow {@see self::LIMIT} hits per hour,
     * on in-memory storage so nothing outlives the test.
     */
    private function service(): AuthThrottleService
    {
        foreach (['auth_login', 'auth_login_ip', 'auth_two_factor', 'auth_two_factor_ip', 'auth_forgot_password', 'auth_forgot_password_ip'] as $name) {
            $this->factories[$name] = new RateLimiterFactory(
                ['id' => $name, 'policy' => 'sliding_window', 'limit' => self::LIMIT, 'interval' => '1 hour'],
                new InMemoryStorage()
            );
        }

        return new AuthThrottleService(
            $this->factories['auth_login'],
            $this->factories['auth_login_ip'],
            $this->factories['auth_two_factor'],
            $this->factories['auth_two_factor_ip'],
            $this->factories['auth_forgot_password'],
            $this->factories['auth_forgot_password_ip'],
        );
    }

    private function remaining(string $limiter, string $key): int
    {
        return $this->factories[$limiter]->create($key)->consume(0)->getRemainingTokens();
    }
}
