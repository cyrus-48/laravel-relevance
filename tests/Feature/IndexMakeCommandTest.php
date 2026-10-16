<?php

declare(strict_types=1);

namespace Relevance\Tests\Feature;

use Relevance\Schema\IndexDefinition;
use Relevance\Tests\TestCase;

class IndexMakeCommandTest extends TestCase
{
    private string $generated = '';

    protected function tearDown(): void
    {
        if ($this->generated !== '' && file_exists($this->generated)) {
            unlink($this->generated);
        }

        parent::tearDown();
    }

    /**
     * The generated class must actually load and compile against the real Schema API
     * (it used to reference IndexSchemaBuilder / string() / float() which do not exist).
     */
    public function test_generated_index_definition_loads_and_compiles(): void
    {
        $this->generated = app_path('Search/StubCheckIndex.php');
        if (file_exists($this->generated)) {
            unlink($this->generated);
        }

        $this->artisan('make:relevance-index', ['name' => 'StubCheck'])->assertExitCode(0);

        $this->assertFileExists($this->generated);
        require_once $this->generated;

        $class = '\\App\\Search\\StubCheckIndex';
        $this->assertTrue(class_exists($class));

        /** @var IndexDefinition $def */
        $def = new $class();

        $this->assertSame('stub_check', $def->name());

        $fields = $def->compiledSchema()->fields;
        $this->assertSame('vector', $fields['embedding']['type'] ?? null);
        $this->assertSame('embedding', $def->vectorFieldName());
        $this->assertSame('text', $fields['title']['type'] ?? null);
        $this->assertSame('number', $fields['price']['type'] ?? null);
        $this->assertSame('bool', $fields['in_stock']['type'] ?? null);
    }
}
