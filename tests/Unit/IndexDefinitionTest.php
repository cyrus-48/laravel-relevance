<?php

declare(strict_types=1);

namespace Relevance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Relevance\Schema\IndexDefinition;
use Relevance\Schema\Schema;

class TestProductIndex extends IndexDefinition
{
    public function name(): string
    {
        return 'test_products';
    }

    public function schema(Schema $s): void
    {
        $s->id('id');
        $s->text('title')->weight(3.0)->highlight();
        $s->text('description')->weight(1.0);
        $s->keyword('brand')->facet()->filterable();
        $s->number('price')->sortable()->filterable()->facet([[0, 50], [50, 200]]);
        $s->bool('in_stock')->filterable();
        $s->vector('embedding')->from(['title', 'description'])->provider('openai');
    }
}

class IndexDefinitionTest extends TestCase
{
    public function test_schema_compilation_and_field_attributes(): void
    {
        $def = new TestProductIndex();
        $compiled = $def->compiledSchema();

        $this->assertSame('test_products', $compiled->name);
        $this->assertArrayHasKey('title', $compiled->fields);
        $this->assertSame(3.0, $compiled->fields['title']['weight']);
        $this->assertTrue($compiled->fields['title']['highlight']);

        $this->assertTrue($compiled->fields['brand']['filterable']);
        $this->assertTrue($compiled->fields['price']['sortable']);
        $this->assertSame(['title', 'description'], $compiled->fields['embedding']['sources']);
        $this->assertSame('openai', $compiled->fields['embedding']['provider']);

        $hash1 = $def->schemaHash();
        $hash2 = $def->schemaHash();
        $this->assertSame($hash1, $hash2);
        $this->assertSame(64, strlen($hash1));
    }
}
