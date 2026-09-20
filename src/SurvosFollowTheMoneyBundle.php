<?php

declare(strict_types=1);

namespace Survos\FollowTheMoneyBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Survos\FollowTheMoney\Model;
use Survos\FtmResolver\{ResolutionProvider, YenteProvider};
use Survos\FollowTheMoneyBundle\Review\{ReviewStore, EvidenceLinker, NullEvidenceLinker};
use Survos\FollowTheMoneyBundle\Service\ReviewService;
use Survos\FollowTheMoneyBundle\Controller\ReviewController;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosFollowTheMoneyBundle extends AbstractSurvosBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('base_uri')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('token')->defaultNull()->end()
            ->scalarNode('provider_service')->defaultValue(YenteProvider::class)->end()
            ->scalarNode('review_role')->defaultValue('ROLE_ADMIN')->end()
            ->scalarNode('base_template')->defaultValue('base.html.twig')->end()
            ->arrayNode('datasets')->scalarPrototype()->end()->end()
        ->end();
    }
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set('survos.ftm.model', Model::class)->factory([Model::class,'bundled']);
        $services->set(YenteProvider::class)->args([service('http_client'),service('serializer'),service('survos.ftm.model'),$config['base_uri'],$config['token']]);
        $services->alias(ResolutionProvider::class, $config['provider_service']);
        $services->set(ReviewStore::class)->args([service('doctrine.dbal.default_connection'),service('survos.ftm.model')]);
        $services->set(NullEvidenceLinker::class);
        $services->alias(EvidenceLinker::class, NullEvidenceLinker::class);
        $services->set(ReviewService::class);
        $services->set(ReviewController::class)->arg('$datasets', $config['datasets'])->arg('$role', $config['review_role'])->arg('$baseTemplate', $config['base_template'])->arg('$model', service('survos.ftm.model'));
    }
}
