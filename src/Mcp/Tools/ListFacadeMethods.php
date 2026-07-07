<?php

namespace NativeBlade\Mcp\Tools;

use NativeBlade\Mcp\Tool;
use phpDocumentor\Reflection\DocBlock\Tags\Method;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;

class ListFacadeMethods implements Tool
{
    private const FACADES = [
        \NativeBlade\Facades\NativeBlade::class      => 'Runtime / native action builders. Used inside controllers, Livewire components, and event listeners.',
        \NativeBlade\Facades\NativeBladeConfig::class => 'App configuration. Used inside AppServiceProvider::boot() to declare platforms, plugins, and OTA updates.',
    ];

    public function name(): string
    {
        return 'list_facade_methods';
    }

    public function description(): string
    {
        return 'List every method on the NativeBlade and NativeBladeConfig facades with a one-line summary. Call this first to discover what is available; then call describe_facade_method for full signature and example of a specific method.';
    }

    public function inputSchema(): array
    {
        // No arguments: bare object schema — never embed an empty stdClass
        // (PHP cache serialization can corrupt it into __PHP_Incomplete_Class).
        return [
            'type' => 'object',
        ];
    }

    public function run(array $args): string
    {
        $factory = DocBlockFactory::createInstance();
        $out = [];

        foreach (self::FACADES as $class => $purpose) {
            $ref = new ReflectionClass($class);
            $comment = $ref->getDocComment();
            if ($comment === false) continue;

            $block = $factory->create($comment);
            $methods = [];

            foreach ($block->getTagsByName('method') as $tag) {
                if (!$tag instanceof Method) continue;
                $methods[] = [
                    'name' => $tag->getMethodName(),
                    'signature' => $this->signature($tag),
                    'static' => $tag->isStatic(),
                ];
            }

            $out[] = [
                'facade' => $this->shortName($class),
                'class' => $class,
                'purpose' => $purpose,
                'methods' => $methods,
            ];
        }

        return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function shortName(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }

    private function signature(Method $tag): string
    {
        $args = [];
        foreach ($tag->getParameters() as $arg) {
            $type = (string) $arg->getType() ?: 'mixed';
            $args[] = trim($type . ' $' . $arg->getName());
        }

        $return = (string) $tag->getReturnType();
        $name = $tag->getMethodName();

        return sprintf('%s %s(%s)', $return, $name, implode(', ', $args));
    }
}
