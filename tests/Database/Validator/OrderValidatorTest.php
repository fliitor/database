<?php

namespace Utopia\Tests\Validator;

use Utopia\Database\Validator\OrderAttributes;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

class OrderValidatorTest extends TestCase
{
    /**
     * @var array<Document>
     */
    protected array $schema;

    /**
     * @var array<Document>
     */
    protected array $indexesSchema;

    /**
     * @var array<array<string, mixed>>
     */
    protected array $attributes = [
        [
            '$id' => 'title',
            'key' => 'title',
            'type' => Database::VAR_STRING,
            'size' => 256,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'description',
            'key' => 'description',
            'type' => Database::VAR_STRING,
            'size' => 1000000,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'rating',
            'key' => 'rating',
            'type' => Database::VAR_INTEGER,
            'size' => 5,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'price',
            'key' => 'price',
            'type' => Database::VAR_FLOAT,
            'size' => 5,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'published',
            'key' => 'published',
            'type' => Database::VAR_BOOLEAN,
            'size' => 5,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => 'tags',
            'key' => 'tags',
            'type' => Database::VAR_STRING,
            'size' => 55,
            'required' => true,
            'signed' => true,
            'array' => true,
            'filters' => [],
        ],
    ];

    /**
     * @var array<array<string, mixed>>
     */
    protected array $indexes = [
        [
            '$id' => 'index1',
            'type' => Database::INDEX_KEY,
            'attributes' => ['title'],
            'lengths' => [256],
            'orders' => ['ASC'],
        ],
        [
            '$id' => 'index2',
            'type' => Database::INDEX_KEY,
            'attributes' => ['price'],
            'lengths' => [],
            'orders' => ['DESC'],
        ],
        [
            '$id' => 'index3',
            'type' => Database::INDEX_KEY,
            'attributes' => ['published'],
            'lengths' => [],
            'orders' => ['DESC'],
        ],
    ];

    /**
     * @throws \Exception
     */
    public function setUp(): void
    {
        // Query validator expects array<Document>
        foreach ($this->attributes as $attribute) {
            $this->schema[] = new Document($attribute);
        }

        // Query validator expects array<Document>
        foreach ($this->indexes as $index) {
            $this->indexesSchema[] = new Document($index);
        }
    }

    public function tearDown(): void
    {
    }

    public function testQuery(): void
    {
        $validator = new OrderAttributes($this->schema, $this->indexesSchema);

        $this->assertEquals(true, $validator->isValid(['$id']));
        $this->assertEquals(true, $validator->isValid(['title']));
        $this->assertEquals(true, $validator->isValid(['published']));
        $this->assertEquals(false, $validator->isValid(['_uid']));
    }
}
