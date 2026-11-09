<?php

namespace Hibira\SymfonyBundle\Tests;

use Hibira\SymfonyBundle\Tests\Fixtures\TestKernel;
use Hibira\SymfonyBundle\WaitingRoom\WaitingRooms;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WaitingRoomTest extends TestCase
{
    // Signed by the Hibira server (internal/waitroom NewPass) at 1800000000, ttl 30 min.
    private const SECRET = 'wr-secret-0123456789';
    private const PASS = 'eyJ2IjoxLCJhcHAiOiJ3ci1hcHAiLCJyb29tIjoicHJlc2FsZSIsIm4iOjQyLCJ0aWQiOiJkNzlmZTc4OWEzOGI4NjMxYjUiLCJpYXQiOjE4MDAwMDAwMDAsImV4cCI6MTgwMDAwMTgwMH0.q7Uc_O6XKwNA_LVLQITlgv_YtDi9dIwiRzUUUSsGa08';
    private const TICKET = 'eyJ2IjoxLCJhcHAiOiJ3ci1hcHAiLCJyb29tIjoicHJlc2FsZSIsIm4iOjQyLCJ0aWQiOiJkNzlmZTc4OWEzOGI4NjMxYjUiLCJpYXQiOjE4MDAwMDAwMDAsImV4cCI6MTgwMDA4NjQwMH0.l-KNMOVwV_xJJCQaQ-JXQKsrj-XlEdsTxG7X4Kp_2ck';
    private const NOW = 1800000100;

    private ?TestKernel $kernel = null;

    /** @var callable|null */
    private $exceptionHandler;

    protected function setUp(): void
    {
        $this->exceptionHandler = set_exception_handler(null);
        restore_exception_handler();
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        for ($i = 0; $i < 10; ++$i) {
            $h = set_exception_handler(null);
            restore_exception_handler();
            if ($h === $this->exceptionHandler) {
                break;
            }
            restore_exception_handler();
        }
    }

    private static function sign(array $claims, string $secret = 'app-secret'): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');

        return $payload.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', 'hibira-waitroom-pass.'.$payload, $secret, true)), '+/', '-_'), '=');
    }

    private static function fresh(string $room = 'presale'): string
    {
        return self::sign(['v' => 1, 'app' => '1', 'room' => $room, 'n' => 7, 'tid' => 't7', 'iat' => time(), 'exp' => time() + 600]);
    }

    private function get(string $url, string $method = 'GET', array $cookies = []): Response
    {
        if (null === $this->kernel) {
            $this->kernel = new TestKernel(['waiting_room' => ['paths' => ['/vip' => 'vip']]]);
            $this->kernel->boot();
        }

        return $this->kernel->handle(Request::create($url, $method, [], $cookies));
    }

    public function testVerifiesAServerPass(): void
    {
        $pass = WaitingRooms::verifyPass(self::PASS, self::SECRET, 'presale', 'wr-app', self::NOW);
        $this->assertSame([42, 'd79fe789a38b8631b5', 1800001800], [$pass?->number, $pass?->ticketId, $pass?->expiresAt]);
        $this->assertNotNull(WaitingRooms::verifyPass(self::PASS, ['old', self::SECRET], now: self::NOW));
    }

    public function testRejectsForgedExpiredForeignAndTickets(): void
    {
        $this->assertNull(WaitingRooms::verifyPass(self::PASS, 'wrong', now: self::NOW));
        $this->assertNull(WaitingRooms::verifyPass(self::PASS, self::SECRET, 'other', now: self::NOW));
        $this->assertNull(WaitingRooms::verifyPass(self::PASS, self::SECRET, appId: 'other', now: self::NOW));
        $this->assertNull(WaitingRooms::verifyPass(self::PASS, self::SECRET, now: 1800001800));
        $this->assertNull(WaitingRooms::verifyPass(self::TICKET, self::SECRET, now: self::NOW));
        $this->assertNull(WaitingRooms::verifyPass(substr(self::PASS, 0, -3).'AAA', self::SECRET, now: self::NOW));
        foreach (['', null, 'a.b.c', '!!.??'] as $bad) {
            $this->assertNull(WaitingRooms::verifyPass($bad, self::SECRET, now: self::NOW));
        }
    }

    public function testSendsVisitorsWithoutAPassToTheQueue(): void
    {
        $res = $this->get('https://shop.example.com/drop/1?a=b');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('https://hibira.test/wait/app-key/presale?return=https%3A%2F%2Fshop.example.com%2Fdrop%2F1%3Fa%3Db', $res->headers->get('Location'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertSame('open', $this->get('/open')->getContent());
        $post = $this->get('/drop/1', 'POST');
        $this->assertSame(403, $post->getStatusCode());
        $this->assertSame('waiting_room', json_decode((string) $post->getContent(), true)['error']);
    }

    public function testTurnsThePassIntoACookie(): void
    {
        $pass = self::fresh();
        $res = $this->get('https://shop.example.com/drop/1?a=b&hibira_pass='.$pass);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('https://shop.example.com/drop/1?a=b', $res->headers->get('Location'));
        $cookie = $res->headers->getCookies()[0];
        $this->assertSame(['hibira_wr_presale', $pass, true, true, 'lax'], [$cookie->getName(), $cookie->getValue(), $cookie->isHttpOnly(), $cookie->isSecure(), $cookie->getSameSite()]);
        $this->assertSame('in #7', $this->get('/drop/1', 'GET', ['hibira_wr_presale' => $pass])->getContent());
        $this->assertSame(302, $this->get('/drop/1', 'GET', ['hibira_wr_presale' => self::fresh('other')])->getStatusCode());
    }

    public function testConfiguredPathPrefixes(): void
    {
        $this->assertStringContainsString('/wait/app-key/vip?', (string) $this->get('/vip/1')->headers->get('Location'));
        $this->assertSame('vip #7', $this->get('/vip/1', 'GET', ['hibira_wr_vip' => self::fresh('vip')])->getContent());
    }
}
