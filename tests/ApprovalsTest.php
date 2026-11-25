<?php

namespace Hibira\SymfonyBundle\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Hibira\SymfonyBundle\Exception\ApprovalException;
use Hibira\SymfonyBundle\Hibira;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ApprovalsTest extends TestCase
{
    private array $history = [];

    private MockHandler $responses;

    private function hibira(): Hibira
    {
        $this->responses = new MockHandler();
        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->history));

        return new Hibira([
            'app_id' => '1',
            'key' => 'app-key',
            'secret' => 'app-secret',
            'host' => 'hibira.test',
            'port' => 443,
            'scheme' => 'https',
            'push' => ['instance_id' => null, 'secret_key' => null, 'endpoint' => Hibira::DEFAULT_PUSH_ENDPOINT],
        ], null, null, new Client(['handler' => $stack]));
    }

    private static function approval(string $status = 'pending'): string
    {
        return json_encode(['approval_id' => 'apr_1', 'title' => 'Refund?', 'options' => ['Approve', 'Reject'], 'status' => $status, 'url' => 'https://approvals.hibira.io/a/apr_1']);
    }

    private function assertSigned(RequestInterface $request): void
    {
        parse_str($request->getUri()->getQuery(), $params);
        $signature = $params['auth_signature'];
        unset($params['auth_signature']);
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $k.'='.$v;
        }
        $expected = hash_hmac('sha256', $request->getMethod()."\n".$request->getUri()->getPath()."\n".implode('&', $pairs), 'app-secret');
        $this->assertSame($expected, $signature);
        $body = (string) $request->getBody();
        if ($body !== '') {
            $this->assertSame(md5($body), $params['body_md5']);
        }
    }

    public function testRequestApprovalPostsASignedRequest(): void
    {
        $hibira = $this->hibira();
        $this->responses->append(new Response(201, [], self::approval()));

        $out = $hibira->requestApproval('Refund?', ['options' => ['Approve', 'Reject'], 'channel' => 'private-ops', 'agent' => null]);

        $this->assertSame('apr_1', $out['approval_id']);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://hibira.test/apps/1/approvals', preg_replace('/\?.*/', '', (string) $request->getUri()));
        $this->assertSame(['title' => 'Refund?', 'options' => ['Approve', 'Reject'], 'channel' => 'private-ops'], json_decode((string) $request->getBody(), true));
        $this->assertSigned($request);
    }

    public function testRequestApprovalChecksItsArguments(): void
    {
        $hibira = $this->hibira();
        $this->expectException(\InvalidArgumentException::class);
        $hibira->requestApproval('x', ['colour' => 'red']);
    }

    public function testApprovalCapsWaitAndEscapesTheId(): void
    {
        $hibira = $this->hibira();
        $this->responses->append(new Response(200, [], self::approval('approved')));

        $this->assertSame('approved', $hibira->approval('apr/1', 90)['status']);
        $request = $this->history[0]['request'];
        $this->assertSame('/apps/1/approvals/apr%2F1', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $params);
        $this->assertSame('25', $params['wait']);
        $this->assertSigned($request);
    }

    public function testWaitForApprovalPollsUntilAnswered(): void
    {
        $hibira = $this->hibira();
        $this->responses->append(new Response(200, [], self::approval()), new Response(200, [], self::approval('rejected')));

        $this->assertSame('rejected', $hibira->waitForApproval('apr_1', 600)['status']);
        $this->assertCount(2, $this->history);
    }

    public function testErrorsCarryTheStatusAndMessage(): void
    {
        $hibira = $this->hibira();
        $this->responses->append(new Response(403, [], '{"error":"Approvals is not on this plan"}'));

        try {
            $hibira->requestApproval('x');
            $this->fail('expected an ApprovalException');
        } catch (ApprovalException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('Approvals is not on this plan', $e->getMessage());
        }
    }
}
