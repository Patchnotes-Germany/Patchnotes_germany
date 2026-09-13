<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Config\PatchnotesConfig;
use App\User\Doctrine\EncryptedJsonType;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Everything that can be configured lives under the "patchnotes" key (SPEC.md § 24.19):
 * repository URLs, forges, sources and federal states, AI providers and routing, review policy,
 * notification rules, legal details and retention.
 *
 * Nodes whose values come from %env()% are declared as plain scalars on purpose; their enum/number
 * validation happens at runtime in App\Core\Config\ConfigurationChecker (SPEC.md § 24.18).
 */
final class PatchnotesBundle extends AbstractBundle
{
    protected string $extensionAlias = 'patchnotes';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('languages')
                    ->info('Content and interface languages. Adding one must require config + translations only.')
                    ->scalarPrototype()->end()
                    ->requiresAtLeastOneElement()
                    ->defaultValue(['ru', 'uk', 'en', 'tr'])
                ->end()
                ->scalarNode('master_language')
                    ->info('The language AI writes first, directly from the German source; others are translated from it.')
                    ->defaultValue('en')
                ->end()
                ->arrayNode('language_settings')
                    ->useAttributeAsKey('language')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('locale')->isRequired()->end()
                            ->scalarNode('dir')->defaultValue('ltr')->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('timezone')->defaultValue('Europe/Berlin')->end()
                ->scalarNode('donation_url')->defaultNull()->end()

                ->append($this->normalizationNode())
                ->append($this->repositoriesNode())
                ->append($this->gitNode())
                ->append($this->sourcesNode())
                ->append($this->featuresNode())
                ->append($this->aiNode())
                ->append($this->reviewNode())
                ->append($this->notificationsNode())
                ->append($this->alertsNode())
                ->append($this->billingNode())
                ->append($this->legalNode())
                ->append($this->retentionNode())
            ->end()
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The whole tree is available as one parameter and through the typed PatchnotesConfig service.
        $builder->setParameter('patchnotes.config', $config);
        // Resolved at runtime and handed to the Doctrine type in boot() below.
        $builder->setParameter('patchnotes.encryption_key', '%env(default::APP_ENCRYPTION_KEY)%');
        $builder->setParameter('patchnotes.languages', $config['languages']);
        $builder->setParameter('patchnotes.master_language', $config['master_language']);
        $builder->setParameter('patchnotes.timezone', $config['timezone']);
        /** @var array{repeal_confirmations: int} $sources */
        $sources = $config['sources'];
        $builder->setParameter('patchnotes.sources.repeal_confirmations', $sources['repeal_confirmations']);
    }

    /**
     * Doctrine types cannot be autowired, so the encryption key is handed to EncryptedJsonType once
     * the container is available (SPEC.md § 10: profile tags are encrypted at rest).
     */
    public function boot(): void
    {
        parent::boot();

        $key = $this->container?->getParameter('patchnotes.encryption_key');
        EncryptedJsonType::setEncryptionKey(\is_string($key) && '' !== $key ? $key : null);
    }

    /**
     * Rules of the deterministic source-to-Markdown conversion (SPEC.md § 4.2).
     */
    private function normalizationNode(): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $node = new \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition('normalization');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('abbreviations')
                    ->info('German abbreviations after which a full stop never ends a sentence; extends the built-in list.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
            ->end()
        ;

        return $node;
    }

    private function repositoriesNode(): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $node = new \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition('repositories');

        $repository = static function (string $name): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition {
            $repo = new \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition($name);
            $repo
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('url')->defaultValue('')->end()
                    ->scalarNode('default_branch')->defaultValue('main')->end()
                    // github | gitlab | gitea | none — validated at runtime (SPEC.md § 24.18)
                    ->scalarNode('forge')->defaultValue('none')->end()
                    ->scalarNode('forge_api_url')->defaultNull()->end()
                    ->scalarNode('forge_project')->defaultNull()->end()
                    ->arrayNode('auth')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->scalarNode('ssh_key_path')->defaultNull()->end()
                            ->scalarNode('token')->defaultNull()->end()
                        ->end()
                    ->end()
                    ->scalarNode('webhook_secret')->defaultNull()->end()
                    ->scalarNode('local_path')->isRequired()->end()
                ->end()
            ;

            return $repo;
        };

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->append($repository('laws'))
                ->append($repository('content'))
            ->end()
        ;

        return $node;
    }

    private function gitNode(): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $node = new \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition('git');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('bot_name')->defaultValue('Patchnotes Bot')->end()
                ->scalarNode('bot_email')->defaultValue('bot@patchnotes.local')->end()
                ->scalarNode('push_enabled')->defaultFalse()->end()
                ->scalarNode('known_hosts')->defaultNull()->end()
            ->end()
        ;

        return $node;
    }

    private function sourcesNode(): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $node = new \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition('sources');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('crawler')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('user_agent')->isRequired()->end()
                        ->scalarNode('max_rps_per_host')->defaultValue(1)->end()
                        ->scalarNode('timeout_seconds')->defaultValue(60)->end()
                    ->end()
                ->end()
                ->arrayNode('bund')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('gii')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('enabled')->defaultTrue()->end()
                            ->end()
                        ->end()
                        ->arrayNode('neuris')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('enabled')->defaultFalse()->end()
                            ->end()
                        ->end()
                        ->arrayNode('bgbl')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('enabled')->defaultTrue()->end()
                            ->end()
                        ->end()
                        ->arrayNode('dip')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('enabled')->defaultTrue()->end()
                                ->scalarNode('api_key')->defaultNull()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('laender')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('enabled')
                            ->info('Federal states whose adapters are switched on (ISO 3166-2:DE codes, lower case).')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('slots')
                            ->info('Nightly synchronisation slot per state; never 02:00-03:00 (DST), see SPEC.md § 24.16.')
                            ->useAttributeAsKey('land')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('repeal_confirmations')
                    ->info('A law counts as repealed only after this many consecutive successful runs without it.')
                    ->defaultValue(3)
                ->end()
                ->arrayNode('safeguards')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('max_law_deletion_ratio')->defaultValue(0.4)->end()
                        ->scalarNode('max_changed_laws_ratio')->defaultValue(0.3)->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function featuresNode(): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $node = new \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition('features');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('preview_prs')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('bund')->defaultTrue()->end()
                        ->scalarNode('laender')->defaultFalse()->end()
                    ->end()
                ->end()
                ->scalarNode('translate_impact_zero')->defaultFalse()->end()
                ->scalarNode('telegram_login')->defaultFalse()->end()
                ->scalarNode('analytics')->defaultFalse()->end()
                ->scalarNode('backfill')->defaultFalse()->end()
            ->end()
        ;

        return $node;
    }

    private function aiNode(): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $node = new \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition('ai');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('providers')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            // openai | anthropic | openai_compatible
                            ->scalarNode('type')->isRequired()->end()
                            ->scalarNode('api_key')->defaultNull()->end()
                            ->scalarNode('base_url')->defaultNull()->end()
                            // direct | remote_worker (SPEC.md § 8.3)
                            ->scalarNode('execution')->defaultValue('direct')->end()
                            ->scalarNode('supports_json_schema')
                                ->info('Whether the server enforces a JSON schema itself; otherwise answers are validated and repaired here.')
                                ->defaultFalse()
                            ->end()
                            ->scalarNode('timeout')
                                ->info('Seconds to wait for an answer. A local model on a laptop may think for minutes.')
                                ->defaultValue(300)
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('models')
                    ->info('Model aliases in "provider:model-id" form; never hardcoded in the code.')
                    ->useAttributeAsKey('alias')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('tasks')
                    ->useAttributeAsKey('task')
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('chain')
                                ->info('Model aliases tried in order; "@large" refers to models.large.')
                                ->scalarPrototype()->end()
                                ->defaultValue([])
                            ->end()
                            ->floatNode('temperature')->defaultValue(0.0)->end()
                            ->scalarNode('batch')->defaultNull()->end()
                            ->scalarNode('prefer_different_provider_than')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('budget')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('monthly_limit_eur')->defaultValue(0)->end()
                        // degrade | pause
                        ->scalarNode('on_exceed')->defaultValue('degrade')->end()
                        ->arrayNode('alert_thresholds')
                            ->floatPrototype()->end()
                            ->defaultValue([0.8, 1.0])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('pricing')
                    ->info('Price per million tokens, keyed by "provider:model-id", in the provider currency.')
                    ->useAttributeAsKey('model')
                    ->arrayPrototype()
                        ->children()
                            ->floatNode('input_per_mtok')->defaultValue(0)->end()
                            ->floatNode('output_per_mtok')->defaultValue(0)->end()
                            ->floatNode('cached_input_per_mtok')->defaultNull()->end()
                            ->scalarNode('currency')->defaultValue('USD')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('fx')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('usd_eur')->defaultValue(0.92)->end()
                    ->end()
                ->end()
                ->scalarNode('cache')->defaultTrue()->end()
                ->scalarNode('local_worker_fallback_after_minutes')->defaultValue(60)->end()
                ->scalarNode('max_verify_score_single_provider')->defaultValue(0.85)->end()
                ->arrayNode('pretranslate_laws')
                    ->info('Norm references "{jurisdiction}/{law-slug}" translated in the background within budget.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('on_demand_translation_limit_per_day')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('user')->defaultValue(50)->end()
                        ->integerNode('ip')->defaultValue(20)->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function reviewNode(): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $node = new \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition('review');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('auto_publish')->defaultTrue()->end()
                ->floatNode('min_verify_score')->defaultValue(0.8)->end()
                ->scalarNode('require_human_for_impact')->defaultNull()->end()
                ->integerNode('hold_alerts_minutes')->defaultValue(0)->end()
                ->scalarNode('unreviewed_badge')->defaultTrue()->end()
                ->integerNode('settling_window_hours')
                    ->info('change_analyze starts when all target laws reflect the act, or after this window.')
                    ->defaultValue(72)
                ->end()
            ->end()
        ;

        return $node;
    }

    private function notificationsNode(): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $node = new \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition('notifications');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('instant_min_impact')->defaultValue(2)->end()
                ->integerNode('max_instant_per_day')->defaultValue(3)->end()
                ->arrayNode('quiet_hours')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('start')->defaultValue('22:00')->end()
                        ->scalarNode('end')->defaultValue('08:00')->end()
                    ->end()
                ->end()
                ->arrayNode('reminders_days_before')
                    ->integerPrototype()->end()
                    ->defaultValue([14, 1])
                ->end()
                ->arrayNode('digest')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('day')->defaultValue('sunday')->end()
                        ->scalarNode('generate_at')->defaultValue('15:00')->end()
                        ->scalarNode('send_at')->defaultValue('18:00')->end()
                    ->end()
                ->end()
                ->arrayNode('email')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('from')->defaultValue('noreply@patchnotes.local')->end()
                    ->end()
                ->end()
                ->arrayNode('telegram')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('bot_token')->defaultNull()->end()
                        ->scalarNode('webhook_secret')->defaultNull()->end()
                        ->scalarNode('admin_chat_id')->defaultNull()->end()
                        ->arrayNode('channels')
                            ->useAttributeAsKey('language')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->integerNode('channel_max_posts_per_day')->defaultValue(5)->end()
                    ->end()
                ->end()
                ->arrayNode('webpush')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('public_key')->defaultNull()->end()
                        ->scalarNode('private_key')->defaultNull()->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function alertsNode(): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $node = new \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition('alerts');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('admin_email')->defaultNull()->end()
            ->end()
        ;

        return $node;
    }

    private function billingNode(): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $node = new \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition('billing');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('enabled')->defaultFalse()->end()
                ->scalarNode('stripe_secret')->defaultNull()->end()
                ->scalarNode('stripe_webhook_secret')->defaultNull()->end()
            ->end()
        ;

        return $node;
    }

    private function legalNode(): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $node = new \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition('legal');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('operator')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('name')->defaultNull()->end()
                        ->scalarNode('address')->defaultNull()->end()
                        ->scalarNode('email')->defaultNull()->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function retentionNode(): \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition
    {
        $node = new \Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition('retention');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('logs_days')->defaultValue(30)->end()
                ->integerNode('notification_details_days')->defaultValue(90)->end()
                ->integerNode('unconfirmed_accounts_days')->defaultValue(7)->end()
            ->end()
        ;

        return $node;
    }
}
