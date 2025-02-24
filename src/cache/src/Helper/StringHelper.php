<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Cache\Helper;

use Hyperf\Utils\Str;

class StringHelper
{
    /**
     * Format cache key with prefix and arguments.
     */
    public static function format(string $prefix, array $arguments, ?string $value = null): string
    {
        if ($value !== null) {
            if ($matches = StringHelper::parse($value)) {
                foreach ($matches as $search) {
                    $k = str_replace('#{', '', $search);
                    $k = str_replace('}', '', $k);

                    $value = Str::replaceFirst($search, (string) data_get($arguments, $k), $value);
                }
            }
            $key = $prefix . ':' . $value;
        } else {
            $key = $prefix . ':' . implode(':', $arguments);
        }

        return $key;
    }

    /**
     * Parse expression of value.
     */
    public static function parse(string $value): array
    {
        preg_match_all('/\#\{[\w\.]+\}/', $value, $matches);

        return $matches[0] ?? [];
    }
}
