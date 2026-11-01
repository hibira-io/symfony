<?php

namespace Hibira\SymfonyBundle\Command;

use Hibira\SymfonyBundle\Hibira;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * bin/console hibira:check: credentials, then one call to the Channels API.
 */
final class CheckCommand extends Command
{
    public function __construct(
        private Hibira $hibira,
        private bool $turbo = false,
        private bool $messenger = false,
    ) {
        parent::__construct();
    }

    public static function getDefaultName(): string
    {
        return 'hibira:check';
    }

    public static function getDefaultDescription(): string
    {
        return 'Checks the Hibira credentials and connection';
    }

    protected function configure(): void
    {
        $this->setName(self::getDefaultName())
            ->setDescription(self::getDefaultDescription())
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Only check the configuration, without calling Hibira');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $client = $this->hibira->clientConfig();

        $missing = $this->hibira->missingCredentials();
        if ($missing !== []) {
            $env = ['app_id' => 'HIBIRA_APP_ID', 'key' => 'HIBIRA_APP_KEY', 'secret' => 'HIBIRA_APP_SECRET'];
            $io->error(sprintf('Missing %s. Copy them from your app page on https://hibira.io/portal into .env.', implode(', ', array_map(fn ($n) => $env[$n], $missing))));

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Host' => sprintf('%s:%d (%s)', $client['host'], $client['port'], $client['tls'] ? 'TLS' : 'no TLS')],
            ['App key' => $client['key']],
            ['Turbo Streams' => $this->turbo ? 'on (transport "hibira")' : 'off'],
            ['Messenger' => $this->messenger ? 'on (route PublishEvent to a transport to send from a worker)' : 'off (events are sent during the request)'],
            ['Push notifications' => $this->hibira->hasPush() ? 'configured' : 'not configured'],
        );

        if ($input->getOption('offline')) {
            $io->success('Configuration looks complete.');

            return Command::SUCCESS;
        }

        try {
            $channels = $this->hibira->pusher()->getChannels();
        } catch (\Throwable $e) {
            $io->error(sprintf('Hibira refused the request: %s. Check HIBIRA_APP_ID, HIBIRA_APP_KEY, HIBIRA_APP_SECRET and HIBIRA_HOST.', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf('Connected to Hibira. %d occupied channel(s) right now.', \count((array) ($channels->channels ?? []))));

        return Command::SUCCESS;
    }
}
