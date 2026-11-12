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

    private string $id;

    public function __construct(string $environment, bool $debug, string $id = 'orchestrator')
    {
        $this->id = $id;
        parent::__construct($environment, $debug);
    }

    public function getAppDir(): string
    {
        return $this->getProjectDir().'/apps/'.$this->id;
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->id.'/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir().'/var/log/'.$this->id;
    }

    public function registerBundles(): iterable
    {
        $contents = require $this->getAppDir().'/config/bundles.php';
        foreach ($contents as $class => $envs) {
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                yield new $class();
            }
        }
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $sharedConfigDir = $this->getProjectDir().'/shared/config';
        $appConfigDir = $this->getAppDir().'/config';

        // 1. Chargement de la configuration partagée (shared/config)
        if (is_dir($sharedConfigDir)) {
            $loader->load($sharedConfigDir.'/{packages}/*.yaml', 'glob');
            $loader->load($sharedConfigDir.'/{packages}/'.$this->environment.'/*.yaml', 'glob');
            if (file_exists($sharedConfigDir.'/services.yaml')) {
                $loader->load($sharedConfigDir.'/services.yaml');
            }
        }

        // 2. Chargement de la configuration propre au site actif (apps/<id>/config)
        $loader->load($appConfigDir.'/{packages}/*.yaml', 'glob');
        $loader->load($appConfigDir.'/{packages}/'.$this->environment.'/*.yaml', 'glob');
        $loader->load($appConfigDir.'/services.yaml');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $appConfigDir = $this->getAppDir().'/config';
        $routes->import($appConfigDir.'/{routes}/'.$this->environment.'/*.yaml');
        $routes->import($appConfigDir.'/{routes}/*.yaml');
        $routes->import($appConfigDir.'/routes.yaml');
    }
}
