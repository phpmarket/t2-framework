<?php

namespace App\Bootstrap;

use T2\Bootstrap;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Session as SessionBase;
use Workerman\Worker;
use function config;
use function property_exists;

class Session implements Bootstrap
{
    /**
     * @param Worker|null $worker
     *
     * @return void
     */
    public static function start(?Worker $worker): void
    {
        $config = config('session');
        if (property_exists(SessionBase::class, 'name')) {
            SessionBase::$name = $config['session_name'];
        } else {
            Http::sessionName($config['session_name']);
        }
        SessionBase::handlerClass($config['handler'], $config['config'][$config['type']]);
        $map = [
            'auto_update_timestamp' => 'autoUpdateTimestamp',
            'cookie_lifetime'       => 'cookieLifetime',
            'gc_probability'        => 'gcProbability',
            'cookie_path'           => 'cookiePath',
            'http_only'             => 'httpOnly',
            'same_site'             => 'sameSite',
            'lifetime'              => 'lifetime',
            'domain'                => 'domain',
            'secure'                => 'secure'
        ];
        foreach ($map as $key => $name) {
            if (isset($config[$key]) && property_exists(SessionBase::class, $name)) {
                SessionBase::${$name} = $config[$key];
            }
        }
    }
}
