<?php

namespace NativeBlade\Mcp;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /**
     * JSON Schema for the tool's input arguments.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Run the tool against the given arguments. The returned string is
     * delivered to the agent as a `text` content block.
     *
     * @param  array<string, mixed>  $args
     */
    public function run(array $args): string;
}
