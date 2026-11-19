<?php

namespace App\Ai\Tools;

use App\Ai\AgentContext;
use Closure;

/**
 * One capability an agent can use. Tools never take raw database queries or code: each one is a
 * narrow, validated operation that runs with the owner's own permissions.
 */
final class Tool
{
    /**
     * @param  array<string, mixed>  $schema  JSON schema shown to the model
     * @param  array<string, mixed>  $rules  Laravel validation rules applied to the model's input
     * @param  string|null  $action  the permission it needs (see AgentCapabilities::ACTIONS); null for read-only tools
     * @param  Closure(array<string, mixed>, AgentContext, string): array<string, mixed>  $handler  receives the input, context and policy
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $schema,
        public readonly array $rules,
        public readonly ?string $action,
        public readonly Closure $handler,
    ) {}

    public function writes(): bool
    {
        return $this->action !== null;
    }

    /** @return array{name: string, description: string, schema: array<string, mixed>} */
    public function definition(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'schema' => $this->schema];
    }

    /**
     * A small helper for writing schemas.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @param  array<int, string>  $required
     */
    public static function object(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass : $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }
}
