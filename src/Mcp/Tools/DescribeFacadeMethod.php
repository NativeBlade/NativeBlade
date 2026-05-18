<?php

namespace NativeBlade\Mcp\Tools;

use NativeBlade\Mcp\Tool;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Method;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlockFactory;
use ReflectionClass;
use ReflectionMethod;

class DescribeFacadeMethod implements Tool
{
    private const FACADES = [
        \NativeBlade\Facades\NativeBlade::class,
        \NativeBlade\Facades\NativeBladeConfig::class,
    ];

    private const IMPL_CLASSES = [
        \NativeBlade\NativeResponse::class,
        \NativeBlade\ShellConfig::class,
    ];

    public function name(): string
    {
        return 'describe_facade_method';
    }

    public function description(): string
    {
        return 'Get the full signature, description, parameters, return type, and (when available) usage example of a specific facade method. Pair with list_facade_methods to discover names first.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Method name as listed by list_facade_methods, e.g. "notification" or "isMobile".',
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function run(array $args): string
    {
        $name = $args['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException('Argument "name" is required.');
        }

        $factory = DocBlockFactory::createInstance();

        $facadeMethod = $this->findFacadeMethod($factory, $name);
        $implMethod = $this->findImplementation($factory, $name);

        if ($facadeMethod === null && $implMethod === null) {
            return json_encode([
                'found' => false,
                'message' => "Method '$name' was not found on any facade or implementation class.",
            ], JSON_PRETTY_PRINT);
        }

        $payload = [
            'found' => true,
            'name' => $name,
            'facade' => $facadeMethod['facade'] ?? null,
            'signature' => $facadeMethod['signature'] ?? ($implMethod['signature'] ?? null),
            'summary' => $implMethod['summary'] ?? null,
            'description' => $implMethod['description'] ?? null,
            'params' => $implMethod['params'] ?? [],
            'return' => $implMethod['return'] ?? null,
            'example' => $implMethod['example'] ?? null,
            'source' => $implMethod['source'] ?? null,
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{facade:string, signature:string}|null
     */
    private function findFacadeMethod(DocBlockFactory $factory, string $name): ?array
    {
        foreach (self::FACADES as $facade) {
            $ref = new ReflectionClass($facade);
            $comment = $ref->getDocComment();
            if ($comment === false) continue;

            $block = $factory->create($comment);
            foreach ($block->getTagsByName('method') as $tag) {
                if ($tag instanceof Method && $tag->getMethodName() === $name) {
                    return [
                        'facade' => $facade,
                        'signature' => $this->signatureFromTag($tag),
                    ];
                }
            }
        }
        return null;
    }

    /**
     * @return array{signature:string, summary:?string, description:?string, params:array<int,array<string,string>>, return:?string, example:?string, source:string}|null
     */
    private function findImplementation(DocBlockFactory $factory, string $name): ?array
    {
        foreach (self::IMPL_CLASSES as $class) {
            $ref = new ReflectionClass($class);
            if (!$ref->hasMethod($name)) continue;

            $method = $ref->getMethod($name);
            if (!$method->isPublic() || $method->isConstructor()) continue;

            $signature = $this->signatureFromReflection($method);
            $summary = null;
            $description = null;
            $params = [];
            $return = null;
            $example = null;

            $comment = $method->getDocComment();
            if ($comment !== false) {
                $block = $factory->create($comment);
                $summary = $block->getSummary() ?: null;
                $description = (string) $block->getDescription() ?: null;

                foreach ($block->getTagsByName('param') as $tag) {
                    if (!$tag instanceof Param) continue;
                    $params[] = [
                        'name' => $tag->getVariableName() ?? '',
                        'type' => (string) $tag->getType(),
                        'description' => (string) $tag->getDescription(),
                    ];
                }

                foreach ($block->getTagsByName('return') as $tag) {
                    $return = $tag->render();
                    break;
                }

                $example = $this->extractExample($block);
            }

            return [
                'signature' => $signature,
                'summary' => $summary,
                'description' => $description,
                'params' => $params,
                'return' => $return,
                'example' => $example,
                'source' => $class . '::' . $name . '()',
            ];
        }
        return null;
    }

    private function signatureFromTag(Method $tag): string
    {
        $args = [];
        foreach ($tag->getParameters() as $arg) {
            $type = (string) $arg->getType() ?: 'mixed';
            $args[] = trim($type . ' $' . $arg->getName());
        }
        return sprintf('%s %s(%s)', (string) $tag->getReturnType(), $tag->getMethodName(), implode(', ', $args));
    }

    private function signatureFromReflection(ReflectionMethod $method): string
    {
        $params = [];
        foreach ($method->getParameters() as $p) {
            $type = $p->hasType() ? (string) $p->getType() . ' ' : '';
            $name = '$' . $p->getName();
            $default = $p->isDefaultValueAvailable()
                ? ' = ' . var_export($p->getDefaultValue(), true)
                : '';
            $params[] = trim($type . $name . $default);
        }
        $return = $method->hasReturnType() ? ': ' . (string) $method->getReturnType() : '';
        return sprintf('%s(%s)%s', $method->getName(), implode(', ', $params), $return);
    }

    private function extractExample(DocBlock $block): ?string
    {
        foreach ($block->getTagsByName('example') as $tag) {
            $rendered = $tag->render();
            if ($rendered !== '') return trim(preg_replace('/^@example\s*/', '', $rendered) ?? $rendered);
        }

        $desc = (string) $block->getDescription();
        if (preg_match('/```php\s*\n(.*?)\n```/s', $desc, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
