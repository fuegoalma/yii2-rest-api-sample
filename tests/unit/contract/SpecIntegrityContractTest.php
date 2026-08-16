<?php

namespace tests\unit\contract;

use RuntimeException;

/**
 * The document lints itself.
 *
 * A dangling `$ref` or an operation with no responses breaks Swagger UI without
 * breaking anything the other gates look at — and a malformed document breaks
 * every one of them at once, with a stack trace instead of an explanation. This
 * is the check an external OpenAPI linter would perform; doing it here keeps a
 * second toolchain (Node) out of a PHP image, and it runs on every `make test`.
 */
final class SpecIntegrityContractTest extends ContractTestCase
{
    public function testTheDocumentParsesAndDeclaresItself(): void
    {
        $operations = $this->spec()->operations();

        $this->assertNotEmpty($operations, 'The document declares no operations.');
        $this->assertNotEmpty($this->spec()->schemaNames(), 'The document declares no schemas.');
    }

    public function testEveryReferenceResolves(): void
    {
        $broken = [];

        foreach ($this->references($this->spec()->operations()) as $reference) {
            try {
                $this->spec()->resolve(['$ref' => $reference]);
            } catch (RuntimeException) {
                $broken[] = $reference;
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($broken)),
            'Unresolvable $ref pointers in config/openapi.yaml. Swagger UI renders these as empty '
            . 'sections rather than failing, so nothing else would report them'
        );
    }

    public function testEveryOperationDeclaresAtLeastOneResponse(): void
    {
        $silent = [];

        foreach ($this->spec()->operations() as $name => $operation) {
            if (($operation['responses'] ?? []) === []) {
                $silent[] = $name;
            }
        }

        $this->assertSame([], $silent, 'Operations in config/openapi.yaml that document no response');
    }

    /**
     * Every `$ref` value anywhere below the given node.
     *
     * @return string[]
     */
    private function references(array $node): array
    {
        $found = [];

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $found[] = $value;
            } elseif (is_array($value)) {
                $found = [...$found, ...$this->references($value)];
            }
        }

        return $found;
    }
}
