<?php

namespace Hibira\SymfonyBundle\Tests;

use Hibira\SymfonyBundle\LiveChat\LiveChat;
use Hibira\SymfonyBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class LiveChatTest extends TestCase
{
    public function testUserHash(): void
    {
        self::assertSame(hash_hmac('sha256', '42', 'lcid_s'), LiveChat::userHash('lcid_s', 42));
        $this->expectException(\InvalidArgumentException::class);
        LiveChat::userHash('', 1);
    }

    public function testNothingWithoutAWorkspace(): void
    {
        self::assertSame('', (new LiveChat())->render());
    }

    public function testAnonymousVisitorWithOptions(): void
    {
        $html = (new LiveChat('ws_1', 'lcid_s', new TokenStorage()))->render(['color' => '#0a7', 'lang' => 'ja', 'launcher' => false, 'nonce' => 'n1']);

        self::assertSame('<script src="https://chat.hibira.io/w/v1.js" data-workspace="ws_1" data-color="#0a7" data-lang="ja" data-launcher="none" nonce="n1" async></script>', $html);
    }

    public function testSignedInUserIsVerifiedOnlyWithASecret(): void
    {
        $tokens = new TokenStorage();
        $user = new class implements UserInterface {
            public function getId(): int { return 7; }
            public function getFullName(): string { return 'Aiko "A" <B>'; }
            public function getEmail(): string { return 'aiko@example.com'; }
            public function getRoles(): array { return ['ROLE_USER']; }
            public function eraseCredentials(): void {}
            public function getUserIdentifier(): string { return 'aiko'; }
        };
        $tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));

        self::assertStringNotContainsString('data-user-id', (new LiveChat('ws_1', '', $tokens))->render());

        $html = (new LiveChat('ws_1', 'lcid_s', $tokens))->render();
        self::assertStringContainsString('data-user-id="7" data-user-hash="'.LiveChat::userHash('lcid_s', 7).'"', $html);
        self::assertStringContainsString('data-user-name="Aiko &quot;A&quot; &lt;B&gt;"', $html);
        self::assertStringContainsString('data-user-email="aiko@example.com"', $html);
    }

    public function testIdentityFallsBackToTheUserIdentifier(): void
    {
        $chat = new LiveChat('ws_1', 'lcid_s');
        self::assertSame(['id' => 'ann', 'hash' => LiveChat::userHash('lcid_s', 'ann')], $chat->identity(new InMemoryUser('ann', null)));
        self::assertNull($chat->identity(null));

        $chat->describeUserUsing(static fn (UserInterface $u): array => ['id' => 'u-'.$u->getUserIdentifier(), 'name' => 'Ann']);
        self::assertSame(['id' => 'u-ann', 'hash' => LiveChat::userHash('lcid_s', 'u-ann'), 'name' => 'Ann'], $chat->identity(new InMemoryUser('ann', null)));
    }

    public function testTwigFunctionInTheBundle(): void
    {
        TestKernel::reset();
        $kernel = new TestKernel(['live_chat' => ['workspace' => 'ws_kernel', 'identity_secret' => 'lcid_s']]);
        $kernel->boot();
        try {
            $html = $kernel->getContainer()->get('twig')->createTemplate('{{ hibira_live_chat({lang: "ko"}) }}')->render();
            self::assertSame('<script src="https://chat.hibira.io/w/v1.js" data-workspace="ws_kernel" data-lang="ko" async></script>', $html);
        } finally {
            $kernel->shutdown();
        }
    }
}
