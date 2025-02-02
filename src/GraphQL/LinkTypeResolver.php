<?php

namespace SilverStripe\Link\GraphQL;

use GraphQL\Type\Definition\ResolveInfo;
use SilverStripe\GraphQL\Schema\DataObject\Resolver;
use SilverStripe\Link\Type\Registry;
use SilverStripe\Link\Type\Type;

class LinkTypeResolver extends Resolver
{
    public static function resolve($obj, $args = [], $context = [], ?ResolveInfo $info = null)
    {
        if (isset($args['keys']) && !is_array($args['keys'])) {
            throw new \InvalidArgumentException('If `keys` is provdied, it must be an array');
        }

        $types = Registry::singleton()->list();
        $flattenType = array_map(function (Type $type, string $key) {
            return [
                'key' => $key,
                'handlerName' => $type->LinkTypeHandlerName(),
                'title' => $type->LinkTypeTile()
            ];
        }, $types, array_keys($types));

        return $flattenType;
    }
}
