<?php

namespace Hibira\SymfonyBundle\Tests\Fixtures;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** A TestKernel whose hibira config sets no credentials, so they come from HIBIRA_* env vars. */
final class EnvKernel extends \Symfony\Component\HttpKernel\Kernel
{
    use \Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;

    public function __construct()
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new \Symfony\Bundle\FrameworkBundle\FrameworkBundle();
        yield new \Hibira\SymfonyBundle\HibiraSymfonyBundle();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/hibira-symfony-tests/env-'.self::VERSION.'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/hibira-symfony-tests/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', ['secret' => 'test', 'test' => true, 'http_method_override' => false]);
        $container->addCompilerPass(new class implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition('hibira')->setPublic(true);
            }
        });
    }
}
