<?php

namespace App\Support;

use HTMLPurifier as HtmlPurifierEngine;
use HTMLPurifier_Config;

class HtmlPurifier
{
    private static ?HtmlPurifierEngine $instance = null;

    public static function clean(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        return self::instance()->purify($html);
    }

    private static function instance(): HtmlPurifierEngine
    {
        if (self::$instance === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('Cache.SerializerPath', storage_path('app/htmlpurifier'));

            self::$instance = new HtmlPurifierEngine($config);
        }

        return self::$instance;
    }
}
