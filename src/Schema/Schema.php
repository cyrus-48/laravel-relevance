<?php

declare(strict_types=1);

namespace Relevance\Schema;

use Relevance\Data\IndexSchema;
use Relevance\Data\IndexSettings;

class Schema
{
    /** @var array<string, Field> */
    private array $fields = [];
    private ?IndexSettings $settings = null;

    public function id(string $name = 'id'): Field
    {
        return $this->fields[$name] = (new Field($name, 'id'))->required();
    }

    public function text(string $name): Field
    {
        return $this->fields[$name] = new Field($name, 'text');
    }

    public function keyword(string $name): Field
    {
        return $this->fields[$name] = new Field($name, 'keyword');
    }

    public function number(string $name): Field
    {
        return $this->fields[$name] = new Field($name, 'number');
    }

    public function bool(string $name): Field
    {
        return $this->fields[$name] = new Field($name, 'bool');
    }

    public function date(string $name): Field
    {
        return $this->fields[$name] = new Field($name, 'date');
    }

    public function geo(string $name): Field
    {
        return $this->fields[$name] = new Field($name, 'geo');
    }

    public function vector(string $name): VectorField
    {
        $field = new VectorField($name);
        $this->fields[$name] = $field;
        return $field;
    }

    public function language(string $name): Field
    {
        return $this->fields[$name] = new Field($name, 'language');
    }

    public function settings(IndexSettings $settings): self
    {
        $this->settings = $settings;
        return $this;
    }

    /**
     * @return array<string, Field>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    public function compile(string $indexName): IndexSchema
    {
        $fieldsMap = [];
        $vectorDims = null;

        foreach ($this->fields as $name => $field) {
            $fieldsMap[$name] = $field->toArray();
        }

        return new IndexSchema(
            name: $indexName,
            fields: $fieldsMap,
            settings: $this->settings,
            embeddingDimensions: $vectorDims,
        );
    }
}
