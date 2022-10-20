<?php

namespace Utopia\Database;

use Exception;
use Throwable;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Structure;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Cache\Cache;

class Database
{
    const VAR_STRING = 'string';
    // Simple Types
    const VAR_INTEGER = 'integer';
    const VAR_FLOAT = 'double';
    const VAR_BOOLEAN = 'boolean';
    const VAR_DATETIME = 'datetime';

    // Relationships Types
    const VAR_DOCUMENT = 'document';

    // Index Types
    const INDEX_KEY = 'key';
    const INDEX_FULLTEXT = 'fulltext';
    const INDEX_UNIQUE = 'unique';
    const INDEX_SPATIAL = 'spatial';
    const INDEX_ARRAY = 'array';

    // Orders
    const ORDER_ASC = 'ASC';
    const ORDER_DESC = 'DESC';

    // Permissions
    const PERMISSION_CREATE= 'create';
    const PERMISSION_READ = 'read';
    const PERMISSION_UPDATE = 'update';
    const PERMISSION_DELETE = 'delete';

    // Aggregate permissions
    const PERMISSION_WRITE = 'write';

    const PERMISSIONS = [
        self::PERMISSION_CREATE,
        self::PERMISSION_READ,
        self::PERMISSION_UPDATE,
        self::PERMISSION_DELETE,
    ];

    // Roles
    const ROLE_ANY = 'any';
    const ROLE_GUESTS = 'guests';
    const ROLE_USERS = 'users';
    const ROLE_USER = 'user';
    const ROLE_TEAM = 'team';
    const ROLE_MEMBER = 'member';

    const ROLES = [
        self::ROLE_ANY,
        self::ROLE_GUESTS,
        self::ROLE_USERS,
        self::ROLE_USER,
        self::ROLE_TEAM,
        self::ROLE_MEMBER,
    ];

    const ROLE_CONFIGURATION = [
        Database::ROLE_ANY => [
            'identifier' => [
                'allowed' => false,
                'required' => false,
            ],
            'dimension' =>[
                'allowed' => false,
                'required' => false,
            ],
        ],
        Database::ROLE_GUESTS => [
            'identifier' => [
                'allowed' => false,
                'required' => false,
            ],
            'dimension' =>[
                'allowed' => false,
                'required' => false,
            ],
        ],
        Database::ROLE_USERS => [
            'identifier' => [
                'allowed' => false,
                'required' => false,
            ],
            'dimension' =>[
                'allowed' => true,
                'required' => false,
                'options' => Database::USER_DIMENSIONS
            ],
        ],
        Database::ROLE_USER => [
            'identifier' => [
                'allowed' => true,
                'required' => true,
            ],
            'dimension' =>[
                'allowed' => true,
                'required' => false,
                'options' => Database::USER_DIMENSIONS
            ],
        ],
        Database::ROLE_TEAM => [
            'identifier' => [
                'allowed' => true,
                'required' => true,
            ],
            'dimension' =>[
                'allowed' => true,
                'required' => false,
            ],
        ],
        Database::ROLE_MEMBER => [
            'identifier' => [
                'allowed' => true,
                'required' => true,
            ],
            'dimension' =>[
                'allowed' => false,
                'required' => false,
            ],
        ],
    ];

    // Dimensions
    const DIMENSION_VERIFIED = 'verified';
    const DIMENSION_UNVERIFIED = 'unverified';

    const USER_DIMENSIONS = [
        self::DIMENSION_VERIFIED,
        self::DIMENSION_UNVERIFIED,
    ];

    // Collections
    const METADATA = '_metadata';
    const METADATA_ATTRIBUTE = '_metadata_attribute';

    // Cursor
    const CURSOR_BEFORE = 'before';
    const CURSOR_AFTER = 'after';

    // Lengths
    const LENGTH_KEY = 255;

    // Cache
    const TTL = 60 * 60 * 24; // 24 hours

    /**
     * @var Adapter
     */
    protected Adapter $adapter;

    /**
     * @var Cache
     */
    protected Cache $cache;

    /**
     * @var array
     */
    protected array $primitives = [
        self::VAR_STRING => true,
        self::VAR_INTEGER => true,
        self::VAR_FLOAT => true,
        self::VAR_BOOLEAN => true,
    ];

    /**
     * List of Internal Ids
     * @var array
     */
    protected array $attributes = [
        [
            '$id' => '$id',
            'key' => '$id',
            'type' => self::VAR_STRING,
            'size' => Database::LENGTH_KEY,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => '$collection',
            'key' => '$collection',
            'type' => self::VAR_STRING,
            'size' => Database::LENGTH_KEY,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => '$createdAt',
            'key' => '$createdAt',
            'type' => Database::VAR_DATETIME,
            'format' => '',
            'size' => 0,
            'signed' => false,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => ['datetime']
        ],
        [
            '$id' => '$updatedAt',
            'key' => '$updatedAt',
            'type' => Database::VAR_DATETIME,
            'format' => '',
            'size' => 0,
            'signed' => false,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => ['datetime']
        ]
    ];

    /**
     * Parent Collection
     * Defines the structure for both system and custom collections
     *
     * @var array
     */
    protected array $collection = [
        '$id' => self::METADATA,
        '$collection' => self::METADATA,
        'name' => 'collections',
        'attributes' => [
            [
                '$id' => 'name',
                'key' => 'name',
                'type' => self::VAR_STRING,
                'size' => 256,
                'required' => true,
                'signed' => false,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'attributes',
                'key' => 'attributes',
                'type' => self::VAR_STRING,
                'size' => 1000000,
                'required' => false,
                'signed' => false,
                'array' => false,
                'filters' => ['subQueryAttributes'],
            ],
            [
                '$id' => 'indexes',
                'key' => 'indexes',
                'type' => self::VAR_STRING,
                'size' => 1000000,
                'required' => false,
                'signed' => false,
                'array' => false,
                'filters' => ['json'],
            ],
        ],
        'indexes' => [],
    ];




    /**
     * Parent Collection
     * Defines the structure for both system and custom collections
     *
     * @var array
     */
    protected array $collectionAttributes = [
        '$id' => self::METADATA_ATTRIBUTE,
        '$collection' => self::METADATA_ATTRIBUTE,
        'name' => 'collections',
        'attributes' => [
            [
                '$id' => 'collectionId',
                'key' => 'collectionId',
                'type' => Database::VAR_STRING,
                'size' => 50,
                'required' => false,
                'default' => null,
                'signed' => false,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'collectionInternalId',
                'key' => 'collectionInternalId',
                'type' => Database::VAR_STRING,
                'size' => 50,
                'required' => false,
                'default' => null,
                'signed' => false,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'key',
                'key' => 'key',
                'type' => Database::VAR_STRING,
                'size' => 255,
                'required' => false,
                'default' => null,
                'signed' => false,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'type',
                'key' => 'type',
                'type' => Database::VAR_STRING,
                'size' => 255,
                'required' => false,
                'default' => null,
                'signed' => false,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'required',
                'key' => 'required',
                'type' => Database::VAR_BOOLEAN,
                'size' => 0,
                'required' => false,
                'default' => null,
                'signed' => false,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'size',
                'key' => 'size',
                'type' => Database::VAR_INTEGER,
                'size' => 0,
                'required' => false,
                'default' => null,
                'signed' => false,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'signed',
                'key' => 'signed',
                'type' => Database::VAR_BOOLEAN,
                'size' => 0,
                'required' => false,
                'default' => null,
                'signed' => false,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'default',
                'key' => 'default',
                'type' => Database::VAR_STRING,
                'size' => 1000,
                'required' => false,
                'default' => null,
                'signed' => false,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'array',
                'key' => 'array',
                'type' => Database::VAR_BOOLEAN,
                'size' => 0,
                'required' => false,
                'default' => null,
                'signed' => false,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'filters',
                'key' => 'filters',
                'type' => Database::VAR_STRING,
                'required' => false,
                'size' => 1000000,
                'array' => false,
                'default' => null,
                'signed' => false,
                'filters' => ['json'],
            ],
            [
                '$id' => 'format',
                'key' => 'format',
                'type' => Database::VAR_STRING,
                'required' => false,
                'size' => 1000000,
                'array' => false,
                'default' => null,
                'signed' => false,
                'filters' => [],
            ],
            [
                '$id' => 'formatOptions',
                'key' => 'formatOptions',
                'type' => Database::VAR_STRING,
                'required' => false,
                'size' => 1000000,
                'array' => false,
                'default' => null,
                'signed' => false,
                'filters' => ['json'],
            ]
        ],
        'indexes' => [],
    ];



    /**
     * @var array
     */
    static protected array $filters = [];

    /**
     * @var array
     */
    private array $instanceFilters = [];

    /**
     * @param Adapter $adapter
     * @param Cache $cache
     */
    public function __construct(Adapter $adapter, Cache $cache, array $filters = [])
    {
        $this->adapter = $adapter;
        $this->cache = $cache;
        $this->instanceFilters = $filters;

        self::addFilter(
            'json',
            /**
             * @param mixed $value
             * @return mixed
             */
            function ($value) {
                $value = ($value instanceof Document) ? $value->getArrayCopy() : $value;

                if (!is_array($value) && !$value instanceof \stdClass) {
                    return $value;
                }

                return json_encode($value);
            },
            /**
             * @param mixed $value
             * @return mixed
             */
            function ($value) {
                if (!is_string($value)) {
                    return $value;
                }

                $value = json_decode($value, true) ?? [];

                if (array_key_exists('$id', $value)) {
                    return new Document($value);
                } else {
                    $value = array_map(function ($item) {
                        if (is_array($item) && array_key_exists('$id', $item)) { // if `$id` exists, create a Document instance
                            return new Document($item);
                        }
                        return $item;
                    }, $value);
                }

                return $value;
            }
        );

        self::addFilter(
            'datetime',
            /**
             * @param string|null $value
             * @return string|null
             * @throws Exception
             */
            function (?string $value) {
                if (is_null($value)) return null;
                try {
                    $value = new \DateTime($value);
                    $value->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                    return DateTime::format($value);
                } catch (\Throwable $th) {
                    return $value;
                }
            },
            /**
             * @param string|null $value
             * @return string|null
             */
            function (?string $value) {
                return DateTime::formatTz($value);
            }
        );


        self::addFilter(
            'subQueryAttributes',
            function (mixed $value, Document $document, Database $database) {
                return null;
            },
            function (mixed $value, Document $document, Database $database) {

                if($document->getId() == self::METADATA_ATTRIBUTE){
                    var_dump("---------- remove does this happen? ----start decode--------------" . self::METADATA_ATTRIBUTE);
                    die;
                    return [];
                }

                if($document->getId() == self::METADATA){
                    var_dump("----------- remove does this happen?  ---start decode--------------" . self::METADATA);
                    die;
                    return [];
                }

                return Authorization::skip(fn () => $database->find(self::METADATA_ATTRIBUTE, [
                    Query::equal('collectionInternalId', [$document->getInternalId()]),
                    Query::limit(9999),
                ]));

            }
        );
    }

    /**
     * Set Namespace.
     *
     * Set namespace to divide different scope of data sets
     *
     * @param string $namespace
     *
     * @return $this
     *
     * @throws Exception
     */
    public function setNamespace(string $namespace): self
    {
        $this->adapter->setNamespace($namespace);

        return $this;
    }

    /**
     * Get Namespace.
     *
     * Get namespace of current set scope
     *
     * @return string
     *
     * @throws Exception
     */
    public function getNamespace(): string
    {
        return $this->adapter->getNamespace();
    }

    /**
     * Set database to use for current scope
     *
     * @param string $name
     * @param bool $reset
     *
     * @return bool
     * @throws Exception
     */
    public function setDefaultDatabase(string $name, bool $reset = false): bool
    {
        return $this->adapter->setDefaultDatabase($name, $reset);
    }

    /**
     * Get Database.
     *
     * Get Database from current scope
     *
     * @throws Exception
     *
     * @return string
     */
    public function getDefaultDatabase(): string
    {
        return $this->adapter->getDefaultDatabase();
    }

    /**
     * Create Database
     *
     * @param string $name
     *
     * @return bool
     */
    public function create(string $name): bool
    {
        $this->adapter->create($name);
        $this->setDefaultDatabase($name);
        $this->createMetadata();

        return true;
    }

    /**
     * Create Metadata collection.
     * @return bool
     * @throws LimitException
     * @throws AuthorizationException
     * @throws StructureException
     * @throws Throwable
     */
    public function createMetadata(): bool
    {
        $this->adapter->createCollection(self::METADATA);

        foreach ($this->collection['attributes'] as $attribute){
            $this->adapter->createAttribute(
        self::METADATA,
                ID::custom($attribute['$id']),
                $attribute['type'],
                $attribute['size'],
                $attribute['signed'],
                $attribute['array']
            );
        }

        $this->adapter->createCollection(self::METADATA_ATTRIBUTE);

        foreach ($this->collectionAttributes['attributes'] as $attribute){
            $this->adapter->createAttribute(
                self::METADATA_ATTRIBUTE,
                ID::custom($attribute['$id']),
                $attribute['type'],
                $attribute['size'],
                $attribute['signed'],
                $attribute['array']
            );
        }

        return true;
    }

    /**
     * Check if database exists
     * Optionally check if collection exists in database
     *
     * @param string $database database name
     * @param string|null $collection (optional) collection name
     *
     * @return bool
     */
    public function exists(string $database, string $collection = null): bool
    {
        return $this->adapter->exists($database, $collection);
    }

    /**
     * List Databases
     *
     * @return array
     */
    public function list(): array
    {
        return $this->adapter->list();
    }

    /**
     * Delete Database
     *
     * @param string $name
     *
     * @return bool
     */
    public function delete(string $name): bool
    {
        return $this->adapter->delete($name);
    }

    /**
     * Create Collection
     *
     * @param string $id
     * @param Document[] $attributes (optional)
     * @param Document[] $indexes (optional)
     *
     * @return Document
     * @throws Exception|Throwable
     */
    public function createCollection(string $id, array $attributes = [], array $indexes = []): Document 
    {
        $collection = $this->getCollection($id);
        if (!$collection->isEmpty() && !in_array($id, [self::METADATA, self::METADATA_ATTRIBUTE])){
            throw new Duplicate('Collection ' . $id . ' Exists!');
        }

        $this->adapter->createCollection($id);

        if ($id === self::METADATA) {
            return new Document($this->collection);
        }

        if ($id === self::METADATA_ATTRIBUTE) {
            return new Document($this->collectionAttributes);
        }

        $collection = new Document([
            '$id' => ID::custom($id),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::create(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'name' => $id,
            'attributes' => [],
            'indexes' => [],
        ]);

        $this->createDocument(self::METADATA, $collection);
        $this->createCollectionAttributes($id, $attributes);
        $this->createCollectionIndexes($id, $indexes);
        $collection->setAttribute('indexes', $indexes);
        $this->updateDocument(self::METADATA, $id, $collection);
        $collection->setAttribute('attributes', $attributes);

        return $collection;
    }


    /**
     * Create Collection Attributes
     *
     * @param string $collection
     * @param Document[] $attributes
     *
     * @return bool
     */
    public function createCollectionAttributes(string $collection, array $attributes = []): bool
    {
        foreach ($attributes as $attribute){
            self::createAttribute(
                $collection,
                $attribute->getId(),
                $attribute->getAttribute('type'),
                $attribute->getAttribute('size') ?? 0,
                $attribute->getAttribute('required'),
                $attribute->getAttribute('default'),
                $attribute->getAttribute('signed') ?? false,
                $attribute->getAttribute('array') ?? false,
                $attribute->getAttribute('format'),
                $attribute->getAttribute('formatOptions')?? [],
                $attribute->getAttribute('filters') ?? []
            );
        }
        return true;
    }

    /**
     * Create Collection Attributes
     *
     * @param string $collection
     * @param Document[] $indexes
     *
     * @return bool
     * @throws Exception
     */
    public function createCollectionIndexes(string $collection, array $indexes = []): bool
    {
        foreach ($indexes as $index){
            self::createIndex(
                $collection,
                $index->getId(),
                $index->getAttribute('type'),
                $index->getAttribute('attributes'),
                $index->getAttribute('lengths'),
                $index->getAttribute('orders'),
            );
        }

        return true;
    }

    /**
     * Get Collection
     *
     * @param string $id
     *
     * @return Document
     * @throws Exception
     */
    public function getCollection(string $id): Document
    {
        return $this->getDocument(self::METADATA, $id);
    }

    /**
     * List Collections
     *
     * @param int $offset
     * @param int $limit
     *
     * @return array
     * @throws Exception
     */
    public function listCollections(int $limit = 25, int $offset = 0): array
    {
        Authorization::disable();

        $result = $this->find(self::METADATA, [
            Query::limit($limit),
            Query::offset($offset)
        ]);

        Authorization::reset();

        return $result;
    }

    /**
     * Delete Collection
     *
     * @param string $id
     *
     * @return bool
     */
    public function deleteCollection(string $id): bool
    {
        $this->adapter->deleteCollection($id);

        return $this->deleteDocument(self::METADATA, $id);
    }

    /**
     * Create Attribute
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param int $size utf8mb4 chars length
     * @param bool $required
     * @param null $default
     * @param bool $signed
     * @param bool $array
     * @param string|null $format optional validation format of attribute
     * @param array $formatOptions assoc array with custom options that can be passed for the format validation
     * @param array $filters
     *
     * @return bool
     * @throws DuplicateException
     * @throws LimitException
     * @throws Exception
     */
    public function createAttribute(string $collection, string $id, string $type, int $size, bool $required, $default = null, bool $signed = false, bool $array = false, string $format = null, array $formatOptions = [], array $filters = []): bool
    {
        if(empty($collection)){
            throw new Exception('CreateAttribute error empty collection');
        }

        if(empty($id)){
            throw new Exception('CreateAttribute error empty id');
        }

        $collection = $this->getCollection($collection);

        // attribute IDs are case insensitive
        $attributes = $collection->getAttribute('attributes', []);

        /** @var Document[] $attributes */
        foreach ($attributes as $attribute) {
            $key = $attribute->getAttribute('key');
            if (\strtolower($key) === \strtolower($id)) {
                throw new DuplicateException('Attribute already exists:' . $key);
            }
        }

        /** Ensure required filters for the attribute are passed */
        $requiredFilters = $this->getRequiredFilters($type);
        if (!empty(array_diff($requiredFilters, $filters))) {
            throw new Exception("Attribute of type: $type requires the following filters: " . implode(",", $requiredFilters));
        }

        if (
            $this->adapter->getAttributeLimit() > 0 &&
            $this->adapter->getAttributeCount($collection) >= $this->adapter->getAttributeLimit()
        ) {
            throw new LimitException('Column limit reached. Cannot create new attribute.');
        }

        if ($format) {
            if (!Structure::hasFormat($format, $type)) {
                throw new Exception('Format ("' . $format . '") not available for this attribute type ("' . $type . '")');
            }
        }

        $collection->setAttribute('attributes', new Document([
            '$id' => ID::custom($id),
            'key' => $id,
            'type' => $type,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'signed' => $signed,
            'array' => $array,
            'format' => $format,
            'formatOptions' => $formatOptions,
            'filters' => $filters,
        ]), Document::SET_TYPE_APPEND);

        if (
            $this->adapter->getRowLimit() > 0 &&
            $this->adapter->getAttributeWidth($collection) >= $this->adapter->getRowLimit()
        ) {
            throw new LimitException('Row width limit reached. Cannot create new attribute.');
        }

        switch ($type) {
            case self::VAR_STRING:
                if ($size > $this->adapter->getStringLimit()) {
                    throw new Exception('Max size allowed for string is: ' . number_format($this->adapter->getStringLimit()));
                }
                break;

            case self::VAR_INTEGER:
                $limit = ($signed) ? $this->adapter->getIntLimit() / 2 : $this->adapter->getIntLimit();
                if ($size > $limit) {
                    throw new Exception('Max size allowed for int is: ' . number_format($limit));
                }
                break;
            case self::VAR_FLOAT:
            case self::VAR_BOOLEAN:
            case self::VAR_DATETIME:
                break;
            default:
                throw new Exception('Unknown attribute type: ' . $type);
        }

        // only execute when $default is given
        if (!\is_null($default)) {
            if ($required === true) {
                throw new Exception('Cannot set a default value on a required attribute');
            }

            $this->validateDefaultTypes($type, $default);
        }

        $attribute = $this->adapter->createAttribute($collection->getId(), $id, $type, $size, $signed, $array);

        if ($collection->getId() !== self::METADATA) {
            $this->updateDocument(self::METADATA, $collection->getId(), $collection);
        }

        if($collection->getId() == self::METADATA){
            return $attribute;
        }

        $d = new Document([
            'collectionId' => $collection->getId(),
            'collectionInternalId' => $collection->getInternalId(),
            'key' => $id,
            'type' => $type,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'signed' => $signed,
            'array' => $array,
            'format' => $format,
            'formatOptions' => $formatOptions,
            'filters' => $filters,
        ]);

        $this->createDocument(self::METADATA_ATTRIBUTE, $d);
        $this->deleteCachedCollection(self::METADATA);
        $this->deleteCachedCollection($collection->getId());

        return $attribute;
    }

    /**
     * Get the list of required filters for each data type
     *
     * @param string $type Type of the attribute
     *
     * @return array
     */
    protected function getRequiredFilters(string $type): array 
    {
        return match ($type) {
            self::VAR_DATETIME => ['datetime'],
            default => [],
        };
    }

    /**
     * Function to validate if the default value of an attribute matches its attribute type
     *
     * @param string $type Type of the attribute
     * @param mixed $default Default value of the attribute
     *
     * @throws Exception
     * @return void
     */
    protected function validateDefaultTypes(string $type, mixed $default): void
    {
        $defaultType = \gettype($default);

        if ($defaultType === 'NULL') {
            // Disable null. No validation required
            return;
        }

        if ($defaultType === 'array') {
            foreach ($default as $value) {
                $this->validateDefaultTypes($type, $value);
            }
            return;
        }

        switch ($type) {
            case self::VAR_STRING:
            case self::VAR_INTEGER:
            case self::VAR_FLOAT:
            case self::VAR_BOOLEAN:
                if ($type !== $defaultType) {
                    throw new Exception('Default value ' . $default . ' does not match given type ' . $type);
                }
                break;
            case self::VAR_DATETIME:
                if ($defaultType !== self::VAR_STRING) {
                    throw new Exception('Default value ' . $default . ' does not match given type ' . $type);
                }
                break;
            default:
                throw new Exception('Unknown attribute type: ' . $type);
                break;
        }
    }

    /**
     * Update attribute metadata. Utility method for update attribute methods.
     *
     * @param string $collection
     * @param string $key
     * @param callable $updateCallback method that recieves document, and returns it with changes applied
     *
     * @return void
     * @throws Throwable
     */
    private function updateAttributeMeta(string $collection, string $key, mixed $value): void
    {
        if (in_array($collection, [self::METADATA, self::METADATA_ATTRIBUTE])) {
            Throw new Exception('Can not update internal collections');
        }

        $collection = $this->getCollection($collection);
        if ($collection->isEmpty()) {
            Throw new Exception('Collection Not found');
        }

        $attribute = $this->findOne(self::METADATA_ATTRIBUTE, [
            Query::equal('collectionInternalId', [$collection->getInternalId()]),
            Query::equal('key', [$key])
        ]);

        if ($attribute === false) {
            throw new Exception('Attribute ' . $key . ' not found');
        }

        var_dump($attribute);

        $attribute->setAttribute($key, $value);
       // $this->updateDocument(self::METADATA_ATTRIBUTE, $attribute->getId(), $attribute);

        // Check this please
        $this->deleteCachedDocument(self::METADATA, $collection->getId());
        $this->deleteCachedCollection($collection->getId());

    }

    /**
     * Update required status of attribute.
     *
     * @param string $collection
     * @param string $id
     * @param bool $required
     *
     * @return void
     * @throws Throwable
     */
    public function updateAttributeRequired(string $collection, string $id, bool $required): void
    {
        $this->updateAttributeMeta($collection, $id, $required);
    }

    /**
     * Update format of attribute.
     *
     * @param string $collection
     * @param string $id
     * @param string $format validation format of attribute
     *
     * @return void
     */
    public function updateAttributeFormat(string $collection, string $id, string $format): void
    {
        $this->updateAttributeMeta($collection, $id, function ($attribute) use ($format) {
            if (!Structure::hasFormat($format, $attribute->getAttribute('type'))) {
                throw new Exception('Format ("' . $format . '") not available for this attribute type ("' . $attribute->getAttribute('type') . '")');
            }

            $attribute->setAttribute('format', $format);
        });
    }

    /**
     * Update format options of attribute.
     *
     * @param string $collection
     * @param string $id
     * @param array $formatOptions assoc array with custom options that can be passed for the format validation
     *
     * @return void
     */
    public function updateAttributeFormatOptions(string $collection, string $id, array $formatOptions): void
    {
        $this->updateAttributeMeta($collection, $id, function ($attribute) use ($formatOptions) {
            $attribute->setAttribute('formatOptions', $formatOptions);
        });
    }

    /**
     * Update filters of attribute.
     *
     * @param string $collection
     * @param string $id
     * @param array $filters
     *
     * @return void
     */
    public function updateAttributeFilters(string $collection, string $id, array $filters): void
    {
        $this->updateAttributeMeta($collection, $id, function ($attribute) use ($filters) {
            $attribute->setAttribute('filters', $filters);
        });
    }

    /**
     * Update default value of attribute
     *
     * @param string $collection
     * @param string $id
     * @param callable|float|object|int|bool|array|string|null $default
     *
     * @return void
     */
    public function updateAttributeDefault(string $collection, string $id, callable|float|object|int|bool|array|string $default = null): void
    {
        $this->updateAttributeMeta($collection, $id, function ($attribute) use ($default) {
            if ($attribute->getAttribute('required') === true) {
                throw new Exception('Cannot set a default value on a required attribute');
            }

            $this->validateDefaultTypes($attribute->getAttribute('type'), $default);

            $attribute->setAttribute('default', $default);
        });
    }

    /**
     * Update Attribute. This method is for updating data that causes underlying structure to change. Check out other updateAttribute methods if you are looking for metadata adjustments.
     *
     * @param string $collection
     * @param string $id
     * @param string|null $type
     * @param int|null $size utf8mb4 chars length
     * @param bool $signed
     * @param bool $array
     *
     * To update attribute key (ID), use renameAttribute instead.
     *
     * @return bool
     * @throws Exception
     */
    public function updateAttribute(string $collection, string $id, string $type = null, int $size = null, bool $signed = null, bool $array = null): bool
    {
        $this->updateAttributeMeta($collection, $id, function ($attribute, $collectionDoc, $attributeIndex) use ($collection, $id, $type, $size, $signed, $array, &$success) {
            if ($type !== null || $size !== null || $signed !== null || $array !== null) {
                $type ??= $attribute->getAttribute('type');
                $size ??= $attribute->getAttribute('size');
                $signed ??= $attribute->getAttribute('signed');
                $array ??= $attribute->getAttribute('array');

                switch ($type) {
                    case self::VAR_STRING:
                        if ($size > $this->adapter->getStringLimit()) {
                            throw new Exception('Max size allowed for string is: ' . number_format($this->adapter->getStringLimit()));
                        }
                        break;

                    case self::VAR_INTEGER:
                        $limit = ($signed) ? $this->adapter->getIntLimit() / 2 : $this->adapter->getIntLimit();
                        if ($size > $limit) {
                            throw new Exception('Max size allowed for int is: ' . number_format($limit));
                        }
                        break;
                    case self::VAR_FLOAT:
                    case self::VAR_BOOLEAN:
                    case self::VAR_DATETIME:
                        break;
                    default:
                        throw new Exception('Unknown attribute type: ' . $type);
                }

                $attribute
                    ->setAttribute('type', $type)
                    ->setAttribute('size', $size)
                    ->setAttribute('signed', $signed)
                    ->setAttribute('array', $array);

                $attributes = $collectionDoc->getAttribute('attributes');
                $attributes[$attributeIndex] = $attribute;
                $collectionDoc->setAttribute('attributes', $attributes, Document::SET_TYPE_ASSIGN);

                if (
                    $this->adapter->getRowLimit() > 0 &&
                    $this->adapter->getAttributeWidth($collectionDoc) >= $this->adapter->getRowLimit()
                ) {
                    throw new LimitException('Row width limit reached. Cannot create new attribute.');
                }

                $this->adapter->updateAttribute($collection, $id, $type, $size, $signed, $array);
            }
        });

        return true;
    }

    /**
     * Checks if attribute can be added to collection.
     * Used to check attribute limits without asking the database
     * Returns true if attribute can be added to collection, throws exception otherwise
     *
     * @param Document $collection
     * @param Document $attribute
     *
     * @throws LimitException
     * @return bool
     */
    public function checkAttribute(Document $collection, Document $attribute): bool
    {
        $collection = clone $collection;
        $collection->setAttribute('attributes', $attribute, Document::SET_TYPE_APPEND);

        if (
            $this->adapter->getAttributeLimit() > 0 &&
            $this->adapter->getAttributeCount($collection) > $this->adapter->getAttributeLimit()
        ) {
            throw new LimitException('Column limit reached. Cannot create new attribute.');
        }

        if (
            $this->adapter->getRowLimit() > 0 &&
            $this->adapter->getAttributeWidth($collection) >= $this->adapter->getRowLimit()
        ) {
            throw new LimitException('Row width limit reached. Cannot create new attribute.');
        }

        return true;
    }

    /**
     * Delete Attribute
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     * @throws Exception
     */
    public function deleteAttribute(string $collection, string $id): bool
    {
        if ($collection === self::METADATA || $collection === self::METADATA_ATTRIBUTE) {
            throw new Exception("Can't delete metadata attributes");
        }

        $collection = $this->getCollection($collection);
        if($collection->isEmpty()){
            throw new Exception('Collection not found');
        }

        Authorization::disable(); // todo: please check me with Authorization :)

        $attribute = $this->findOne(self::METADATA_ATTRIBUTE, [
            Query::equal('collectionId', [$collection->getId()]),
            Query::equal('key', [$id])
        ]);

        if($attribute === false){
            throw new Exception('Attribute not found');
        }

        $res = $this->adapter->deleteAttribute($collection->getId(), $id);
        $this->deleteDocument(self::METADATA_ATTRIBUTE, $attribute->getId());

        Authorization::reset();
        // todo: please check me with cache clean :)
        $this->deleteCachedDocument(self::METADATA, $collection->getId());
        $this->deleteCachedCollection($collection->getId());

        return $res;
    }

    /**
     * Rename Attribute
     *
     * @param string $collection
     * @param string $old Current attribute ID
     * @param string $new
     * @return bool
     * @throws Throwable
     */
    public function renameAttribute(string $collection, string $old, string $new): bool
    {
        if(in_array($collection, [self::METADATA, self::METADATA_ATTRIBUTE])){
            throw new Exception("Can't rename Metadata Attributes");
        }

        $collection = $this->getCollection($collection);
        if($collection->isEmpty()){
            throw new Exception('Collection not found');
        }

        Authorization::disable();

        $attribute = $this->findOne(self::METADATA_ATTRIBUTE, [
            Query::equal('collectionId', [$collection->getId()]),
            Query::equal('key', [$old])
        ]);

        if($attribute === false){
            throw new Exception('Attribute not found');
        }

        $exists = $this->findOne(self::METADATA_ATTRIBUTE, [
            Query::equal('collectionId', [$collection->getId()]),
            Query::equal('key', [$new])
        ]);

        if($exists !== false){
            throw new Exception('Attribute name already used');
        }

        $attribute->setAttribute('key', $new);

        $this->updateDocument(self::METADATA_ATTRIBUTE, $attribute->getId(), $attribute);
        $res = $this->adapter->renameAttribute($collection->getId(), $old, $new);

        $this->deleteCachedDocument(self::METADATA, $collection->getId());
        $this->deleteCachedCollection($collection->getId());

        Authorization::enable();

        return $res;
    }

    /**
     * Rename Index
     *
     * @param string $collection
     * @param string $old
     * @param string $new
     *
     * @return bool
     */
    public function renameIndex(string $collection, string $old, string $new): bool
    {
        $collection = $this->getCollection($collection);

        $indexes = $collection->getAttribute('indexes', []);

        $index = \in_array($old, \array_map(fn ($index) => $index['$id'], $indexes));

        if ($index === false) {
            throw new Exception('Index not found');
        }

        $indexNew = \in_array($new, \array_map(fn ($index) => $index['$id'], $indexes));

        if ($indexNew !== false) {
            throw new DuplicateException('Index name already used');
        }

        foreach ($indexes as $key => $value) {
            if (isset($value['$id']) && $value['$id'] === $old) {
                $indexes[$key]['key'] = $new;
                $indexes[$key]['$id'] = $new;
                break;
            }
        }

        $collection->setAttribute('indexes', $indexes);

        if ($collection->getId() !== self::METADATA) {
            $this->updateDocument(self::METADATA, $collection->getId(), $collection);
        }

        return $this->adapter->renameIndex($collection->getId(), $old, $new);
    }

    /**
     * Create Index
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param array $attributes
     * @param array $lengths
     * @param array $orders
     *
     * @return bool
     */
    public function createIndex(string $collection, string $id, string $type, array $attributes, array $lengths = [], array $orders = []): bool
    {
        if (empty($attributes)) {
            throw new Exception('Missing attributes');
        }

        $collection = $this->getCollection($collection);

        // index IDs are case insensitive
        $indexes = $collection->getAttribute('indexes', []);
        /** @var Document[] $indexes */
        foreach ($indexes as $index) {
            if (\strtolower($index->getId()) === \strtolower($id)) {
                throw new DuplicateException('Index already exists');
            }
        }

        if ($this->adapter->getIndexCount($collection) >= $this->adapter->getIndexLimit()) {
            throw new LimitException('Index limit reached. Cannot create new index.');
        }

        switch ($type) {
            case self::INDEX_KEY:
                if (!$this->adapter->getSupportForIndex()) {
                    throw new Exception('Key index is not supported');
                }
                break;

            case self::INDEX_UNIQUE:
                if (!$this->adapter->getSupportForUniqueIndex()) {
                    throw new Exception('Unique index is not supported');
                }
                break;

            case self::INDEX_FULLTEXT:
                if (!$this->adapter->getSupportForUniqueIndex()) {
                    throw new Exception('Fulltext index is not supported');
                }
                break;

            default:
                throw new Exception('Unknown index type: ' . $type);
                break;
        }

        $index = $this->adapter->createIndex($collection->getId(), $id, $type, $attributes, $lengths, $orders);

        $collection->setAttribute('indexes', new Document([
            '$id' => ID::custom($id),
            'key' => $id,
            'type' => $type,
            'attributes' => $attributes,
            'lengths' => $lengths,
            'orders' => $orders,
        ]), Document::SET_TYPE_APPEND);

        if ($collection->getId() !== self::METADATA) {
            $this->updateDocument(self::METADATA, $collection->getId(), $collection);
        }

        return $index;
    }

    /**
     * Delete Index
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     */
    public function deleteIndex(string $collection, string $id): bool
    {
        $collection = $this->getCollection($collection);

        $indexes = $collection->getAttribute('indexes', []);

        foreach ($indexes as $key => $value) {
            if (isset($value['$id']) && $value['$id'] === $id) {
                unset($indexes[$key]);
            }
        }

        $collection->setAttribute('indexes', $indexes);

        if ($collection->getId() !== self::METADATA) {
            $this->updateDocument(self::METADATA, $collection->getId(), $collection);
        }

        return $this->adapter->deleteIndex($collection->getId(), $id);
    }

    /**
     * Get Document
     *
     * @param string $collection
     * @param string $id
     *
     * @return Document
     */
    public function getDocument(string $collection, string $id): Document
    {

        if ($collection === self::METADATA && $id === self::METADATA) {
            return new Document($this->collection);
        }

        if ($collection === self::METADATA && $id === self::METADATA_ATTRIBUTE) {
            return new Document($this->collectionAttributes);
        }

        if (empty($collection)) {
            throw new Exception('test exception: ' . $collection . ':' . $id);
        }

        $collection = $this->getCollection($collection);
        $validator = new Authorization(self::PERMISSION_READ);

        // TODO@kodumbeats Check if returned cache id matches request
        if ($cache = $this->cache->load('cache-' . $this->getNamespace() . ':' . $collection->getId() . ':' . $id, self::TTL)) {
            $document = new Document($cache);

            if ($collection->getId() !== self::METADATA
                && !$validator->isValid($document->getRead())) {
                return new Document();
            }

            return $document;
        }

        $document = $this->adapter->getDocument($collection->getId(), $id);
        $document->setAttribute('$collection', $collection->getId());

        if ($document->isEmpty()) {
            return $document;
        }

        if ($collection->getId() !== self::METADATA
            && !$validator->isValid($document->getRead())) {
            return new Document();
        }

//        if($collection->getId() === self::METADATA){
//            var_dump("getDocument " . $collection->getId() . " id = " . $id);
//        }

        $document = $this->casting($collection, $document);

        $document = $this->decode($collection, $document);

        $this->cache->save('cache-' . $this->getNamespace() . ':' . $collection->getId() . ':' . $id, $document->getArrayCopy()); // save to cache after fetching from db

        return $document;
    }

    /**
     * Create Document
     *
     * @param string $collection
     * @param Document $document
     *
     * @return Document
     *
     * @throws AuthorizationException
     * @throws StructureException
     * @throws Exception
     */
    public function createDocument(string $collection, Document $document): Document
    {
        $collection = $this->getCollection($collection);
        $time = DateTime::now();

        $document
            ->setAttribute('$id', empty($document->getId()) ? ID::unique() : $document->getId())
            ->setAttribute('$collection', $collection->getId())
            ->setAttribute('$createdAt', $time)
            ->setAttribute('$updatedAt', $time);

        $document = $this->encode($collection, $document);
        $validator = new Structure($collection);

        if (!$validator->isValid($document)) {
            var_dump("StructureException StructureException StructureException StructureException StructureException StructureException");
            var_dump($document);
            var_dump("StructureException StructureException StructureException StructureException StructureException StructureException");

            throw new StructureException($validator->getDescription());
        }
        $document = $this->adapter->createDocument($collection->getId(), $document);

        $document = $this->decode($collection, $document);

        return $document;
    }

    /**
     * Update Document
     *
     * @param string $collection
     * @param string $id
     *
     * @return Document
     *
     * @throws Exception
     */
    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        if (!$document->getId() || !$id) {
            throw new Exception('Must define $id attribute');
        }

        $time = DateTime::now();
        $document->setAttribute('$updatedAt', $time);

        $old = Authorization::skip(fn() => $this->getDocument($collection, $id)); // Skip ensures user does not need read permission for this
        $collection = $this->getCollection($collection);

        $validator = new Authorization(self::PERMISSION_UPDATE);

        if ($collection->getId() !== self::METADATA
            && !$validator->isValid($old->getUpdate())) {
            throw new AuthorizationException($validator->getDescription());
        }

        $document = $this->encode($collection, $document);

        $validator = new Structure($collection);

        if (!$validator->isValid($document)) { // Make sure updated structure still apply collection rules (if any)
            throw new StructureException($validator->getDescription());
        }

        $document = $this->adapter->updateDocument($collection->getId(), $document);
        $document = $this->decode($collection, $document);

        $this->cache->purge('cache-' . $this->getNamespace() . ':' . $collection->getId() . ':' . $id);

        return $document;
    }

    /**
     * Delete Document
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     *
     * @throws AuthorizationException
     */
    public function deleteDocument(string $collection, string $id): bool
    {
        $validator = new Authorization(self::PERMISSION_DELETE);

        $document = Authorization::skip(fn() => $this->getDocument($collection, $id)); // Skip ensures user does not need read permission for this
        $collection = $this->getCollection($collection);

        if ($collection->getId() !== self::METADATA
            && !$validator->isValid($document->getDelete())) {
            throw new AuthorizationException($validator->getDescription());
        }

        $this->cache->purge('cache-' . $this->getNamespace() . ':' . $collection->getId() . ':' . $id);

        return $this->adapter->deleteDocument($collection->getId(), $id);
    }

    /**
     * Cleans the all the collection's documents from the cache
     *
     * @param string $collection
     *
     * @return bool
     */
    public function deleteCachedCollection(string $collection): bool
    {
        return $this->cache->purge('cache-' . $this->getNamespace() . ':' . $collection . ':*');
    }

    /**
     * Cleans a specific document from cache
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     */
    public function deleteCachedDocument(string $collection, string $id): bool
    {
        return $this->cache->purge('cache-' . $this->getNamespace() . ':' . $collection . ':' . $id);
    }

    /**
     * Find Documents
     *
     * @param string $collection
     * @param Query[] $queries
     *
     * @return Document[]
     * @throws Exception|Throwable
     */
    public function find(string $collection, array $queries = []): array
    {
        $collection = $this->getCollection($collection);
        if($collection->isEmpty()){
            throw new Exception("Collection not found");
        }

        $grouped = Query::groupByType($queries);
        /** @var Query[] */ $filters = $grouped['filters'];
        /** @var int */ $limit = $grouped['limit'];
        /** @var int */ $offset = $grouped['offset'];
        /** @var string[] */ $orderAttributes = $grouped['orderAttributes'];
        /** @var string[] */ $orderTypes = $grouped['orderTypes'];
        /** @var Document */ $cursor = $grouped['cursor'];
        /** @var string */ $cursorDirection = $grouped['cursorDirection'];

        if (!empty($cursor) && $cursor->getCollection() !== $collection->getId()) {
            throw new Exception("cursor Document must be from the same Collection.");
        }

        $cursor = empty($cursor) ? [] : $cursor->getArrayCopy();

        $queries = self::convertQueries($collection, $filters);

        $results = $this->adapter->find(
            $collection->getId(),
            $queries,
            $limit ?? 25,
            $offset ?? 0,
            $orderAttributes,
            $orderTypes,
            $cursor ?? [],
            $cursorDirection ?? Database::CURSOR_AFTER,
        );

        foreach ($results as &$node) {
            $node = $this->casting($collection, $node);
            $node = $this->decode($collection, $node);
            $node->setAttribute('$collection', $collection->getId());
        }

        return $results;
    }

    /**
     * @param string $collection
     * @param array $queries
     * @return bool|Document
     * @throws Exception|Throwable
     */
    public function findOne(string $collection, array $queries = []): bool|Document
    {
        $results = $this->find($collection, \array_merge([Query::limit(1)], $queries));
        return \reset($results);
    }

    /**
     * Count Documents
     *
     * Count the number of documents. Pass $max=0 for unlimited count
     *
     * @param string $collection
     * @param Query[] $queries
     * @param int $max
     *
     * @return int
     * @throws Exception
     */
    public function count(string $collection, array $queries = [], int $max = 0): int
    {
        $collection = $this->getCollection($collection);

        if ($collection->isEmpty()) {
            throw new Exception("Collection not found");
        }

        $queries = self::convertQueries($collection, $queries);

        return $this->adapter->count($collection->getId(), $queries, $max);
    }

    /**
     * Sum an attribute
     *
     * Sum an attribute for all the documents. Pass $max=0 for unlimited count
     *
     * @param string $collection
     * @param string $attribute
     * @param Query[] $queries
     * @param int $max
     *
     * @return int|float
     * @throws Exception
     */
    public function sum(string $collection, string $attribute, array $queries = [], int $max = 0)
    {
        $collection = $this->getCollection($collection);

        if ($collection->isEmpty()) {
            throw new Exception("Collection not found");
        }

        $queries = self::convertQueries($collection, $queries);
        return $this->adapter->sum($collection->getId(), $attribute, $queries, $max);
    }

    /**
     * Add Attribute Filter
     *
     * @param string $name
     * @param callable $encode
     * @param callable $decode
     *
     * @return void
     */
    static public function addFilter(string $name, callable $encode, callable $decode): void
    {
        self::$filters[$name] = [
            'encode' => $encode,
            'decode' => $decode,
        ];
    }

    /**
     * @return array Document
     * @throws Exception
     */
    public function getInternalAttributes(): array
    {
        $attributes = [];
        foreach ($this->attributes as $internal){
            $attributes[] = new Document($internal);
        }
        return $attributes;
    }

    /**
     * Encode Document
     *
     * @param Document $collection
     * @param Document $document
     *
     * @return Document
     * @throws Exception|Throwable
     */
    public function encode(Document $collection, Document $document): Document
    {
        $attributes = $collection->getAttribute('attributes', []);
        $attributes = array_merge($attributes, $this->getInternalAttributes());
        foreach ($attributes as $attribute) {
            //$key = $attribute['$id'] ?? '';
            $key = $attribute['key'] ?? ''; // todo:We have a problem...
            $array = $attribute['array'] ?? false;
            $default = $attribute['default'] ?? null;
            $filters = $attribute['filters'] ?? [];
            $value = $document->getAttribute($key, null);

            // continue on optional param with no default
            if (is_null($value) && is_null($default)) {
                continue;
            }

            // assign default only if no no value provided
            if (is_null($value) && !is_null($default)) {
                $value = ($array) ? json_decode($default) : [json_decode($default)];
            } else {
                $value = ($array) ? $value : [$value];
            }

            foreach ($value as &$node) {
                if (($node !== null)) {
                    foreach ($filters as $filter) {
                        $node = $this->encodeAttribute($filter, $node, $document);
                    }
                }
            }

            if (!$array) {
                $value = $value[0];
            }

            if($document->getCollection() == self::METADATA_ATTRIBUTE && $key === 'default'){
                $value = json_encode($value);
            }

            $document->setAttribute($key, $value);
        }

        return $document;
    }

    /**
     * Decode Document
     *
     * @param Document $collection
     * @param Document $document
     *
     * @return Document
     * @throws Throwable|Exception
     */
    public function decode(Document $collection, Document $document): Document
    {
        $attributes = $collection->getAttribute('attributes', []);
        $attributes = array_merge($attributes, $this->getInternalAttributes());
        foreach ($attributes as $attribute) {
            //$key = $attribute['$id'] ?? '';
            $key = $attribute['key'] ?? '';
            $array = $attribute['array'] ?? false;
            $filters = $attribute['filters'] ?? [];
            $value = $document->getAttribute($key, null);

            $value = ($array) ? $value : [$value];
            $value = (is_null($value)) ? [] : $value;

            foreach ($value as &$node) {
                foreach (array_reverse($filters) as $filter) {
                    $node = $this->decodeAttribute($filter, $node, $document);
                }
            }

            $document->setAttribute($key, ($array) ? $value : $value[0]);
        }

        return $document;
    }

    /**
     * Casting
     *
     * @param Document $collection
     * @param Document $document
     *
     * @return Document
     */
    public function casting(Document $collection, Document $document): Document
    {
        if ($this->adapter->getSupportForCasting()) {
            return $document;
        }

        $attributes = $collection->getAttribute('attributes', []);

        foreach ($attributes as $attribute) {
            //$key = $attribute['$id'] ?? '';
            $key = $attribute['key'] ?? '';
            $type = $attribute['type'] ?? '';
            $array = $attribute['array'] ?? false;
            $value = $document->getAttribute($key, null);
            if (is_null($value)) {
                continue;
            }

            if ($array) {
                $value = (!is_string($value)) ? ($value ?? []) : json_decode($value, true);
            } else {
                $value = [$value];
            }

            foreach ($value as &$node) {
                if (is_null($value)) {
                    continue;
                }
                switch ($type) {
                    case self::VAR_BOOLEAN:
                        $node = (bool)$node;
                        break;
                    case self::VAR_INTEGER:
                        $node = (int)$node;
                        break;
                    case self::VAR_FLOAT:
                        $node = (float)$node;
                        break;
                    case self::VAR_DATETIME:
                        break;
                    default:
                        # code...
                        break;
                }
            }

            $document->setAttribute($key, ($array) ? $value : $value[0]);
        }

        return $document;
    }

    /**
     * Encode Attribute
     *
     * Passes the attribute $value, and $document context to a predefined filter
     *  that allow you to manipulate the input format of the given attribute.
     *
     * @param string $name
     * @param mixed $value
     * @param Document $document
     *
     * @return mixed
     * @throws Throwable
     */
    protected function encodeAttribute(string $name, $value, Document $document)
    {
        if (!array_key_exists($name, self::$filters) && !array_key_exists($name, $this->instanceFilters)) {
            throw new Exception('Filter not found');
        }

        try {
            if (array_key_exists($name, $this->instanceFilters)) {
                $value = $this->instanceFilters[$name]['encode']($value, $document, $this);
            } else {
                $value = self::$filters[$name]['encode']($value, $document, $this);
            }
        } catch (\Throwable $th) {
            throw $th;
        }

        return $value;
    }

    /**
     * Decode Attribute
     *
     * Passes the attribute $value, and $document context to a predefined filter
     *  that allow you to manipulate the output format of the given attribute.
     *
     * @param string $name
     * @param mixed $value
     * @param Document $document
     *
     * @return mixed
     */
    protected function decodeAttribute(string $name, $value, Document $document)
    {
        if (!array_key_exists($name, self::$filters) && !array_key_exists($name, $this->instanceFilters)) {
            throw new Exception('Filter not found');
        }

        try {
            if (array_key_exists($name, $this->instanceFilters)) {
                $value = $this->instanceFilters[$name]['decode']($value, $document, $this);
            } else {
                $value = self::$filters[$name]['decode']($value, $document, $this);
            }
        } catch (\Throwable $th) {
            throw $th;
        }

        return $value;
    }

    /**
     * Get adapter attribute limit, accounting for internal metadata
     * Returns 0 to indicate no limit
     *
     * @return int
     */
    public function getAttributeLimit()
    {
        // If negative, return 0
        // -1 ==> virtual columns count as total, so treat as buffer
        return \max($this->adapter->getAttributeLimit() - $this->adapter->getNumberOfDefaultAttributes() - 1, 0);
    }

    /**
     * Get adapter index limit
     *
     * @return int
     */
    public function getIndexLimit()
    {
        return $this->adapter->getIndexLimit() - $this->adapter->getNumberOfDefaultIndexes();
    }

    /**
     * @param Document $collection
     * @param Query[] $queries
     * @return Query[]
     * @throws Exception
     */
    public static function convertQueries(Document $collection, array $queries): array
    {
        $attributes = $collection->getAttribute('attributes', []);

        foreach ($attributes as $v) {
            /* @var $v Document */
            switch ($v->getAttribute('type')) {
                case Database::VAR_DATETIME:
                    foreach ($queries as $qk => $q) {
                        if ($q->getAttribute() === $v->getId()) {
                            $arr = $q->getValues();
                            foreach ($arr as $vk => $vv) {
                                $arr[$vk] = DateTime::setTimezone($vv);
                            }
                            $q->setValues($arr);
                            $queries[$qk] = $q;
                        }
                    }
                    break;
            }
        }
        return $queries;
    }
}
