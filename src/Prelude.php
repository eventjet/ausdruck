<?php

declare(strict_types=1);

namespace Eventjet\Ausdruck;

/** Language-provided declarations, using the same definitions available to consumers.
 * @api
 */
final class Prelude
{
    public static function option(): EnumDefinition
    {
        /** @var EnumDefinition|null $option */
        static $option = null;
        return $option ??= new EnumDefinition('Option', ['T'], ['Some' => [Type::var('T')], 'None' => []]);
    }
}
