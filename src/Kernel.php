<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private string $appId;

    public function __construct(string $environment, bool $debug, ?string $appId = null)
    {
        $resolved = $appId ?? $_SERVER['APP_ID'] ?? $_ENV['APP_ID'] ?? getenv('APP_ID') ?: 'orchestrator';
        $this->appId = in_array($resolved, ['orchestrator', 'ezs_lab', 'ezs_assistant', 'ezstudio_dev', 'ezstudio'], true)
            ? $resolved
            : 'orchestrator';

        parent::__construct($environment, $debug);
    }

    public function getAppId(): string
    {
        return $this->appId;
    }

    public function getAppDir(): string
    {
        return $this->getProjectDir() . '/apps/' . $this->appId;
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/' . $this->appId . '/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log/' . $this->appId;
    }

    public function getBundlesPath(): string
    {
        $appBundles = $this->getAppDir() . '/config/bundles.php';
        return is_file($appBundles) ? $appBundles : parent::getBundlesPath();
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $configDir = $this->getAppDir() . '/config';
        $container->setParameter('container.dumper.inline_class_loader', \PHP_VERSION_ID < 70400 || $this->debug);
        $container->setParameter('container.dumper.inline_factories', true);

        $loader->load($configDir . '/{packages}/*.{php,yaml}', 'glob');
        $loader->load($configDir . '/{packages}/' . $this->environment . '/*.{php,yaml}', 'glob');

        if (is_file($configDir . '/services.yaml')) {
            $loader->load($configDir . '/services.yaml');
            $loader->load($configDir . '/{services}_' . $this->environment . '.yaml', 'glob');
        } else {
            $loader->load($configDir . '/{services}.php');
            $loader->load($configDir . '/{services}_' . $this->environment . '.php');
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $configDir = $this->getAppDir() . '/config';
        $routes->import($configDir . '/{routes}/' . $this->environment . '/*.{php,yaml}');
        $routes->import($configDir . '/{routes}/*.{php,yaml}');

        if (is_file($configDir . '/routes.yaml')) {
            $routes->import($configDir . '/routes.yaml');
        } else {
            $routes->import($configDir . '/{routes}.php');
        }
    }
}
