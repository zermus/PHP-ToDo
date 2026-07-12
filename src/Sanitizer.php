<?php

declare(strict_types=1);

namespace App;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Server-side HTML sanitizer for rich-text task details. This is the
 * authoritative defense; client-side DOMPurify is UX only.
 */
final class Sanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function html(string $dirty): string
    {
        return self::purifier()->purify($dirty);
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier === null) {
            $config = HTMLPurifier_Config::createDefault();

            // Everything Quill's toolbar can produce, nothing more.
            $config->set('HTML.Allowed', implode(',', [
                'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
                'a[href]', 'ul', 'ol', 'li',
                'h1', 'h2', 'h3', 'blockquote', 'pre', 'code',
                'span[class]', 'div[class]', 'sub', 'sup',
            ]));
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('HTML.Nofollow', true);
            $config->set('HTML.TargetBlank', true);
            $config->set('Attr.AllowedClasses', [
                'ql-align-center', 'ql-align-right', 'ql-align-left', 'ql-align-justify',
                'ql-indent-1', 'ql-indent-2', 'ql-indent-3',
                'ql-syntax',
            ]);

            $cacheDir = sys_get_temp_dir() . '/php-todo-purifier';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0700, true);
            }
            if (is_dir($cacheDir) && is_writable($cacheDir)) {
                $config->set('Cache.SerializerPath', $cacheDir);
            } else {
                $config->set('Cache.DefinitionImpl', null);
            }

            self::$purifier = new HTMLPurifier($config);
        }

        return self::$purifier;
    }
}
