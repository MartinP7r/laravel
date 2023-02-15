<?php

/**
 * @package php-tmdb\laravel
 * @author Mark Redeman <markredeman@gmail.com>
 * @copyright (c) 2014, Mark Redeman
 */

namespace Tmdb\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Tmdb\Client;
use Tmdb\Event\Listener\Request\AcceptJsonRequestListener;
use Tmdb\Event\Listener\Request\ApiTokenRequestListener;
use Tmdb\Event\Listener\Request\ContentTypeJsonRequestListener;
use Tmdb\Event\Listener\Request\UserAgentRequestListener;
use Tmdb\Event\Listener\RequestListener;
use Tmdb\Laravel\Cache\DoctrineCacheBridge;
use Tmdb\Laravel\EventDispatcher\EventDispatcherBridge;
use Tmdb\Model\Configuration;
use Tmdb\Repository\ConfigurationRepository;
use Tmdb\Token\Api\ApiToken;

class TmdbServiceProvider extends ServiceProvider
{
    protected const CONFIG_PATH = __DIR__ . '/../config/tmdb.php';

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            self::CONFIG_PATH => config_path('tmdb.php'),
        ], 'config');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'tmdb');

        // Let the IoC container be able to make a Symfony event dispatcher
        $this->app->bindIf(
            'Symfony\Component\EventDispatcher\EventDispatcherInterface',
            'Symfony\Component\EventDispatcher\EventDispatcher'
        );

        $this->app->singleton(Client::class, function (Application $app) {
            $token = new ApiToken(config('tmdb.api_key'));
            $ed = new EventDispatcher();

            $options = [
                /** @var ApiToken|BearerToken */
                'api_token' => $token,
                'event_dispatcher' => [
                    'adapter' => $ed
                ],
                'http' => [
                    'client' => null,
                    'request_factory' => null,
                    'response_factory' => null,
                    'stream_factory' => null,
                    'uri_factory' => null,
                ]
            ];

            $client = new Client($options);

            if (!Arr::has($options, 'cache.handler')) {
                $repository = app('cache')->store(config('tmdb.cache_store'));

                if (!empty(config('tmdb.cache_tag'))) {
                    $repository = $repository->tags(config('tmdb.cache_tag'));
                }

                Arr::set($options, 'cache.handler', new DoctrineCacheBridge($repository));
            }

            if (!Arr::has($options, 'event_dispatcher')) {
                Arr::set($options, 'event_dispatcher', $app->make(EventDispatcherBridge::class));
            }

            $requestListener = new RequestListener($client->getHttpClient(), $ed);
            $ed->addListener(RequestEvent::class, $requestListener);

            $apiTokenListener = new ApiTokenRequestListener($client->getToken());
            $ed->addListener(BeforeRequestEvent::class, $apiTokenListener);

            $acceptJsonListener = new AcceptJsonRequestListener();
            $ed->addListener(BeforeRequestEvent::class, $acceptJsonListener);

            $jsonContentTypeListener = new ContentTypeJsonRequestListener();
            $ed->addListener(BeforeRequestEvent::class, $jsonContentTypeListener);

            $userAgentListener = new UserAgentRequestListener();
            $ed->addListener(BeforeRequestEvent::class, $userAgentListener);

            return $client;
        });

        // bind the configuration (used by the image helper)
        $this->app->bind(Configuration::class, function () {
            $configuration = $this->app->make(ConfigurationRepository::class);
            return $configuration->load();
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            Client::class,
        ];
    }
}
