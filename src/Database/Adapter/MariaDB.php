<?php

namespace Utopia\Database\Adapter;

use Exception;
use PDO;
use PDOException;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Timeout;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

class MariaDB extends SQL
{
    /**
     * Create Database
     *
     * @param string $name
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function create(string $name): bool
    {
        $name = $this->filter($name);

        return $this->getPDO()
            ->prepare("CREATE DATABASE IF NOT EXISTS `{$name}` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;")
            ->execute();
    }

    /**
     * Delete Database
     *
     * @param string $name
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function delete(string $name): bool
    {
        $name = $this->filter($name);

        return $this->getPDO()
            ->prepare("DROP DATABASE `{$name}`;")
            ->execute();
    }

    /**
     * Create Collection
     *
     * @param string $name
     * @param array<Document> $attributes
     * @param array<Document> $indexes
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function createCollection(string $name, array $attributes = [], array $indexes = []): bool
    {
        $database = $this->getDefaultDatabase();
        $namespace = $this->getNamespace();
        $id = $this->filter($name);

        /** @var array<string> $attributeStrings */
        $attributeStrings = [];

        /** @var array<string> $indexStrings */
        $indexStrings = [];

        foreach ($attributes as $key => $attribute) {
            $attrId = $this->filter($attribute->getId());
            $attrType = $this->getSQLType($attribute->getAttribute('type'), $attribute->getAttribute('size', 0), $attribute->getAttribute('signed', true));

            if ($attribute->getAttribute('array')) {
                $attrType = 'LONGTEXT';
            }

            $attributeStrings[$key] = "`{$attrId}` {$attrType}, ";
        }

        foreach ($indexes as $key => $index) {
            $indexId = $this->filter($index->getId());
            $indexType = $index->getAttribute('type');

            $indexAttributes = $index->getAttribute('attributes');
            foreach ($indexAttributes as $nested => $attribute) {
                $indexLength = $index->getAttribute('lengths')[$nested] ?? '';
                $indexLength = (empty($indexLength)) ? '' : '(' . (int)$indexLength . ')';
                $indexOrder = $index->getAttribute('orders')[$nested] ?? '';
                $indexAttribute = $this->filter($attribute);

                if ($indexType === Database::INDEX_FULLTEXT) {
                    $indexOrder = '';
                }

                $indexAttributes[$nested] = "`{$indexAttribute}`{$indexLength} {$indexOrder}";
            }

            $indexStrings[$key] = "{$indexType} `{$indexId}` (" . \implode(", ", $indexAttributes) . " ),";
        }

        try {
            $this->getPDO()
                ->prepare("CREATE TABLE IF NOT EXISTS `{$database}`.`{$namespace}_{$id}` (
                        `_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                        `_uid` CHAR(255) NOT NULL,
                        `_createdAt` datetime(3) DEFAULT NULL,
                        `_updatedAt` datetime(3) DEFAULT NULL,
                        `_permissions` MEDIUMTEXT DEFAULT NULL,
                        " . \implode(' ', $attributeStrings) . "
                        PRIMARY KEY (`_id`),
                        " . \implode(' ', $indexStrings) . "
                        UNIQUE KEY `_uid` (`_uid`),
                        KEY `_created_at` (`_createdAt`),
                        KEY `_updated_at` (`_updatedAt`)
                    )")
                ->execute();

            $this->getPDO()
                ->prepare("CREATE TABLE IF NOT EXISTS `{$database}`.`{$namespace}_{$id}_perms` (
                        `_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                        `_type` VARCHAR(12) NOT NULL,
                        `_permission` VARCHAR(255) NOT NULL,
                        `_document` VARCHAR(255) NOT NULL,
                        PRIMARY KEY (`_id`),
                        UNIQUE INDEX `_index1` (`_document`,`_type`,`_permission`),
                        INDEX `_permission` (`_permission`,`_type`,`_document`)
                    )")
                ->execute();
        } catch (\Exception $th) {
            $this->getPDO()
                ->prepare("DROP TABLE IF EXISTS {$this->getSQLTable($id)}, {$this->getSQLTable($id . '_perms')};")
                ->execute();
            throw $th;
        }

        // Update $this->getCountOfIndexes when adding another default index
        return true;
    }

    /**
     * Delete Collection
     * @param string $id
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function deleteCollection(string $id): bool
    {
        $id = $this->filter($id);

        return $this->getPDO()
            ->prepare("DROP TABLE {$this->getSQLTable($id)}, {$this->getSQLTable($id . '_perms')};")
            ->execute();
    }

    /**
     * Create Attribute
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param int $size
     * @param bool $signed
     * @param bool $array
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function createAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);
        $type = $this->getSQLType($type, $size, $signed);

        if ($array) {
            $type = 'LONGTEXT';
        }

        return $this->getPDO()
            ->prepare("ALTER TABLE {$this->getSQLTable($name)} ADD COLUMN `{$id}` {$type};")
            ->execute();
    }

    /**
     * Update Attribute
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param int $size
     * @param bool $signed
     * @param bool $array
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function updateAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);
        $type = $this->getSQLType($type, $size, $signed);

        if ($array) {
            $type = 'LONGTEXT';
        }

        return $this->getPDO()
            ->prepare("ALTER TABLE {$this->getSQLTable($name)} MODIFY `{$id}` {$type};")
            ->execute();
    }

    /**
     * Delete Attribute
     *
     * @param string $collection
     * @param string $id
     * @param bool $array
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function deleteAttribute(string $collection, string $id, bool $array = false): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);

        return $this->getPDO()
            ->prepare("ALTER TABLE {$this->getSQLTable($name)} DROP COLUMN `{$id}`;")
            ->execute();
    }

    /**
     * Rename Attribute
     *
     * @param string $collection
     * @param string $old
     * @param string $new
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function renameAttribute(string $collection, string $old, string $new): bool
    {
        $collection = $this->filter($collection);
        $old = $this->filter($old);
        $new = $this->filter($new);

        return $this->getPDO()
            ->prepare("ALTER TABLE {$this->getSQLTable($collection)} RENAME COLUMN `{$old}` TO `{$new}`;")
            ->execute();
    }

    /**
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param string $relatedCollection
     * @param bool $twoWay
     * @param string $twoWayKey
     * @return bool
     * @throws Exception
     */
    public function createRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay = false,
        string $id = '',
        string $twoWayKey = ''
    ): bool {
        $name = $this->filter($collection);
        $relatedName = $this->filter($relatedCollection);
        $table = $this->getSQLTable($name);
        $relatedTable = $this->getSQLTable($relatedName);
        $id = $this->filter($id);
        $twoWayKey = $this->filter($twoWayKey);
        $sqlType = $this->getSQLType(Database::VAR_RELATIONSHIP, 0, false);

        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                $sql = "ALTER TABLE {$table} ADD COLUMN `{$id}` {$sqlType} DEFAULT NULL;";

                if ($twoWay) {
                    $sql .= "ALTER TABLE {$relatedTable} ADD COLUMN `{$twoWayKey}` {$sqlType} DEFAULT NULL;";
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                $sql = "ALTER TABLE {$relatedTable} ADD COLUMN `{$twoWayKey}` {$sqlType} DEFAULT NULL;";
                break;
            case Database::RELATION_MANY_TO_ONE:
                $sql = "ALTER TABLE {$table} ADD COLUMN `{$id}` {$sqlType} DEFAULT NULL;";
                break;
            case Database::RELATION_MANY_TO_MANY:
                return true;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        return $this->getPDO()
            ->prepare($sql)
            ->execute();
    }

    /**
     * @param string $collection
     * @param string $relatedCollection
     * @param string $type
     * @param bool $twoWay
     * @param string $key
     * @param string $twoWayKey
     * @param string|null $newKey
     * @param string|null $newTwoWayKey
     * @return bool
     * @throws Exception
     */
    public function updateRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay,
        string $key,
        string $twoWayKey,
        ?string $newKey = null,
        ?string $newTwoWayKey = null,
    ): bool {
        $name = $this->filter($collection);
        $relatedName = $this->filter($relatedCollection);
        $table = $this->getSQLTable($name);
        $relatedTable = $this->getSQLTable($relatedName);
        $key = $this->filter($key);
        $twoWayKey = $this->filter($twoWayKey);

        if (!\is_null($newKey)) {
            $newKey = $this->filter($newKey);
        }
        if (!\is_null($newTwoWayKey)) {
            $newTwoWayKey = $this->filter($newTwoWayKey);
        }

        $sql = '';

        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                if (!\is_null($newKey)) {
                    $sql = "ALTER TABLE {$table} RENAME COLUMN `{$key}` TO `{$newKey}`;";
                }
                if ($twoWay && !\is_null($newTwoWayKey)) {
                    $sql .= "ALTER TABLE {$relatedTable} RENAME COLUMN `{$twoWayKey}` TO `{$newTwoWayKey}`;";
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($twoWay && !\is_null($newTwoWayKey)) {
                    $sql = "ALTER TABLE {$relatedTable} RENAME COLUMN `{$twoWayKey}` TO `{$newTwoWayKey}`;";
                }
                break;
            case Database::RELATION_MANY_TO_ONE:
                if (!\is_null($newKey)) {
                    $sql = "ALTER TABLE {$table} RENAME COLUMN `{$key}` TO `{$newKey}`;";
                }
                break;
            case Database::RELATION_MANY_TO_MANY:
                $collection = $this->getDocument(Database::METADATA, $collection);
                $relatedCollection = $this->getDocument(Database::METADATA, $relatedCollection);

                $junction = $this->getSQLTable('_' . $collection->getInternalId() . '_' . $relatedCollection->getInternalId());

                if (!\is_null($newKey)) {
                    $sql = "ALTER TABLE {$junction} RENAME COLUMN `{$key}` TO `{$newKey}`;";
                }
                if ($twoWay && !\is_null($newTwoWayKey)) {
                    $sql .= "ALTER TABLE {$junction} RENAME COLUMN `{$twoWayKey}` TO `{$newTwoWayKey}`;";
                }
                break;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        if (empty($sql)) {
            return true;
        }

        return $this->getPDO()
            ->prepare($sql)
            ->execute();
    }

    public function deleteRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay,
        string $key,
        string $twoWayKey,
        string $side
    ): bool {
        $name = $this->filter($collection);
        $relatedName = $this->filter($relatedCollection);
        $table = $this->getSQLTable($name);
        $relatedTable = $this->getSQLTable($relatedName);
        $key = $this->filter($key);
        $twoWayKey = $this->filter($twoWayKey);

        $sql = '';

        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                $sql = "ALTER TABLE {$table} DROP COLUMN `{$key}`;";
                if ($twoWay) {
                    $sql .= "ALTER TABLE {$relatedTable} DROP COLUMN `{$twoWayKey}`;";
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $sql = "ALTER TABLE {$relatedTable} DROP COLUMN `{$twoWayKey}`;";
                } elseif ($twoWay) {
                    $sql = "ALTER TABLE {$table} DROP COLUMN `{$key}`;";
                }
                break;
            case Database::RELATION_MANY_TO_ONE:
                if ($twoWay && $side === Database::RELATION_SIDE_CHILD) {
                    $sql = "ALTER TABLE {$relatedTable} DROP COLUMN `{$twoWayKey}`;";
                } else {
                    $sql = "ALTER TABLE {$table} DROP COLUMN `{$key}`;";
                }
                break;
            case Database::RELATION_MANY_TO_MANY:
                $collection = $this->getDocument(Database::METADATA, $collection);
                $relatedCollection = $this->getDocument(Database::METADATA, $relatedCollection);

                $junction = $side === Database::RELATION_SIDE_PARENT
                    ? $this->getSQLTable('_' . $collection->getInternalId() . '_' . $relatedCollection->getInternalId())
                    : $this->getSQLTable('_' . $relatedCollection->getInternalId() . '_' . $collection->getInternalId());

                $perms = $side === Database::RELATION_SIDE_PARENT
                    ? $this->getSQLTable('_' . $collection->getInternalId() . '_' . $relatedCollection->getInternalId() . '_perms')
                    : $this->getSQLTable('_' . $relatedCollection->getInternalId() . '_' . $collection->getInternalId() . '_perms');

                $sql = "DROP TABLE {$junction}; DROP TABLE {$perms}";
                break;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        if (empty($sql)) {
            return true;
        }

        return $this->getPDO()
            ->prepare($sql)
            ->execute();
    }

    /**
     * Rename Index
     *
     * @param string $collection
     * @param string $old
     * @param string $new
     * @return bool
     * @throws Exception
     */
    public function renameIndex(string $collection, string $old, string $new): bool
    {
        $collection = $this->filter($collection);
        $old = $this->filter($old);
        $new = $this->filter($new);

        return $this->getPDO()
            ->prepare("ALTER TABLE {$this->getSQLTable($collection)} RENAME INDEX `{$old}` TO `{$new}`;")
            ->execute();
    }

    /**
     * Create Index
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param array<string> $attributes
     * @param array<int> $lengths
     * @param array<string> $orders
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function createIndex(string $collection, string $id, string $type, array $attributes, array $lengths, array $orders): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);

        $attributes = \array_map(fn ($attribute) => match ($attribute) {
            '$id' => '_uid',
            '$createdAt' => '_createdAt',
            '$updatedAt' => '_updatedAt',
            default => $attribute
        }, $attributes);

        foreach ($attributes as $key => $attribute) {
            $length = $lengths[$key] ?? '';
            $length = (empty($length)) ? '' : '(' . (int)$length . ')';
            $order = $orders[$key] ?? '';
            $attribute = $this->filter($attribute);

            if (Database::INDEX_FULLTEXT === $type) {
                $order = '';
            }

            $attributes[$key] = "`{$attribute}`{$length} {$order}";
        }

        return $this->getPDO()
            ->prepare($this->getSQLIndex($name, $id, $type, $attributes))
            ->execute();
    }

    /**
     * Delete Index
     *
     * @param string $collection
     * @param string $id
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function deleteIndex(string $collection, string $id): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);

        return $this->getPDO()
            ->prepare("ALTER TABLE {$this->getSQLTable($name)} DROP INDEX `{$id}`;")
            ->execute();
    }

    /**
     * Create Document
     *
     * @param string $collection
     * @param Document $document
     * @return Document
     * @throws Exception
     * @throws PDOException
     * @throws Duplicate
     */
    public function createDocument(string $collection, Document $document): Document
    {
        $attributes = $document->getAttributes();
        $attributes['_createdAt'] = $document->getCreatedAt();
        $attributes['_updatedAt'] = $document->getUpdatedAt();
        $attributes['_permissions'] = json_encode($document->getPermissions());

        $name = $this->filter($collection);
        $columns = '';
        $columnNames = '';

        $this->getPDO()->beginTransaction();

        /**
         * Insert Attributes
         */
        $bindIndex = 0;
        foreach ($attributes as $attribute => $value) { // Parse statement
            $column = $this->filter($attribute);
            $bindKey = 'key_' . $bindIndex;
            $columns .= "`{$column}`, ";
            $columnNames .= ':' . $bindKey . ', ';
            $bindIndex++;
        }

        $stmt = $this->getPDO()
            ->prepare("INSERT INTO {$this->getSQLTable($name)}
                ({$columns}_uid) VALUES ({$columnNames}:_uid)");

        $stmt->bindValue(':_uid', $document->getId(), PDO::PARAM_STR);

        $attributeIndex = 0;
        foreach ($attributes as $attribute => $value) {
            if (is_array($value)) { // arrays & objects should be saved as strings
                $value = json_encode($value);
            }

            $bindKey = 'key_' . $attributeIndex;
            $attribute = $this->filter($attribute);
            $value = (is_bool($value)) ? (int)$value : $value;
            $stmt->bindValue(':' . $bindKey, $value, $this->getPDOType($value));
            $attributeIndex++;
        }

        $permissions = [];
        foreach (Database::PERMISSIONS as $type) {
            foreach ($document->getPermissionsByType($type) as $permission) {
                $permission = \str_replace('"', '', $permission);
                $permissions[] = "('{$type}', '{$permission}', '{$document->getId()}')";
            }
        }

        if (!empty($permissions)) {
            $queryPermissions = "INSERT INTO {$this->getSQLTable($name . '_perms')} (_type, _permission, _document) VALUES " . implode(', ', $permissions);
            $stmtPermissions = $this->getPDO()->prepare($queryPermissions);
        }

        try {
            $stmt->execute();

            $document['$internalId'] = $this->getDocument($collection, $document->getId())->getInternalId();

            if (isset($stmtPermissions)) {
                $stmtPermissions->execute();
            }
        } catch (PDOException $e) {
            $this->getPDO()->rollBack();
            switch ($e->getCode()) {
                case 1062:
                case 23000:
                    throw new Duplicate('Duplicated document: ' . $e->getMessage());

                default:
                    throw $e;
            }
        }

        if (!$this->getPDO()->commit()) {
            throw new DatabaseException('Failed to commit transaction');
        }

        return $document;
    }

    /**
     * Update Document
     *
     * @param string $collection
     * @param Document $document
     * @return Document
     * @throws Exception
     * @throws PDOException
     * @throws Duplicate
     */
    public function updateDocument(string $collection, Document $document): Document
    {
        $attributes = $document->getAttributes();
        $attributes['_createdAt'] = $document->getCreatedAt();
        $attributes['_updatedAt'] = $document->getUpdatedAt();
        $attributes['_permissions'] = json_encode($document->getPermissions());

        $name = $this->filter($collection);
        $columns = '';

        /**
         * Get current permissions from the database
         */
        $permissionsStmt = $this->getPDO()->prepare("
                SELECT _type, _permission
                FROM {$this->getSQLTable($name . '_perms')} p
                WHERE p._document = :_uid
        ");
        $permissionsStmt->bindValue(':_uid', $document->getId());
        $permissionsStmt->execute();
        $permissions = $permissionsStmt->fetchAll();

        $initial = [];
        foreach (Database::PERMISSIONS as $type) {
            $initial[$type] = [];
        }

        $permissions = array_reduce($permissions, function (array $carry, array $item) {
            $carry[$item['_type']][] = $item['_permission'];

            return $carry;
        }, $initial);

        $this->getPDO()->beginTransaction();

        /**
         * Get removed Permissions
         */
        $removals = [];
        foreach (Database::PERMISSIONS as $type) {
            $diff = \array_diff($permissions[$type], $document->getPermissionsByType($type));
            if (!empty($diff)) {
                $removals[$type] = $diff;
            }
        }

        /**
         * Get added Permissions
         */
        $additions = [];
        foreach (Database::PERMISSIONS as $type) {
            $diff = \array_diff($document->getPermissionsByType($type), $permissions[$type]);
            if (!empty($diff)) {
                $additions[$type] = $diff;
            }
        }

        /**
         * Query to remove permissions
         */
        $removeQuery = '';
        if (!empty($removals)) {
            $removeQuery = 'AND (';
            foreach ($removals as $type => $permissions) {
                $removeQuery .= "(
                    _type = '{$type}'
                    AND _permission IN (" . implode(', ', \array_map(fn (string $i) => ":_remove_{$type}_{$i}", \array_keys($permissions))) . ")
                )";
                if ($type !== \array_key_last($removals)) {
                    $removeQuery .= ' OR ';
                }
            }
        }
        if (!empty($removeQuery)) {
            $removeQuery .= ')';
            $stmtRemovePermissions = $this->getPDO()
                ->prepare("
                DELETE
                FROM {$this->getSQLTable($name . '_perms')}
                WHERE
                    _document = :_uid
                    {$removeQuery}
            ");
            $stmtRemovePermissions->bindValue(':_uid', $document->getId());

            foreach ($removals as $type => $permissions) {
                foreach ($permissions as $i => $permission) {
                    $stmtRemovePermissions->bindValue(":_remove_{$type}_{$i}", $permission);
                }
            }
        }

        /**
         * Query to add permissions
         */
        if (!empty($additions)) {
            $values = [];
            foreach ($additions as $type => $permissions) {
                foreach ($permissions as $i => $_) {
                    $values[] = "( :_uid, '{$type}', :_add_{$type}_{$i} )";
                }
            }

            $stmtAddPermissions = $this->getPDO()
                ->prepare(
                    "
                    INSERT INTO {$this->getSQLTable($name . '_perms')}
                    (_document, _type, _permission) VALUES " . \implode(', ', $values)
                );

            $stmtAddPermissions->bindValue(":_uid", $document->getId());
            foreach ($additions as $type => $permissions) {
                foreach ($permissions as $i => $permission) {
                    $stmtAddPermissions->bindValue(":_add_{$type}_{$i}", $permission);
                }
            }
        }

        /**
         * Update Attributes
         */

        $bindIndex = 0;
        foreach ($attributes as $attribute => $value) {
            $column = $this->filter($attribute);
            $bindKey = 'key_' . $bindIndex;
            $columns .= "`{$column}`" . '=:' . $bindKey . ',';
            $bindIndex++;
        }

        $stmt = $this->getPDO()
            ->prepare("UPDATE {$this->getSQLTable($name)}
                SET {$columns} _uid = :_uid WHERE _uid = :_uid");

        $stmt->bindValue(':_uid', $document->getId());

        $attributeIndex = 0;
        foreach ($attributes as $attribute => $value) {
            if (is_array($value)) { // arrays & objects should be saved as strings
                $value = json_encode($value);
            }

            $bindKey = 'key_' . $attributeIndex;
            $attribute = $this->filter($attribute);
            $value = (is_bool($value)) ? (int)$value : $value;
            $stmt->bindValue(':' . $bindKey, $value, $this->getPDOType($value));
            $attributeIndex++;
        }

        try {
            $stmt->execute();
            if (isset($stmtRemovePermissions)) {
                $stmtRemovePermissions->execute();
            }
            if (isset($stmtAddPermissions)) {
                $stmtAddPermissions->execute();
            }
        } catch (PDOException $e) {
            $this->getPDO()->rollBack();
            switch ($e->getCode()) {
                case 1062:
                case 23000:
                    throw new Duplicate('Duplicated document: ' . $e->getMessage());

                default:
                    throw $e;
            }
        }

        if (!$this->getPDO()->commit()) {
            throw new DatabaseException('Failed to commit transaction');
        }

        return $document;
    }

    /**
     * Increase or decrease an attribute value
     *
     * @param string $collection
     * @param string $id
     * @param string $attribute
     * @param int|float $value
     * @param int|float|null $min
     * @param int|float|null $max
     * @return bool
     * @throws Exception
     */
    public function increaseDocumentAttribute(string $collection, string $id, string $attribute, int|float $value, int|float|null $min = null, int|float|null $max = null): bool
    {
        $name = $this->filter($collection);
        $attribute = $this->filter($attribute);

        $sqlMax = $max ? " AND `{$attribute}` <= {$max}" : "";
        $sqlMin = $min ? " AND `{$attribute}` >= {$min}" : "";

        $sql = "UPDATE {$this->getSQLTable($name)} SET `{$attribute}` = `{$attribute}` + :val WHERE _uid = :_uid" . $sqlMax . $sqlMin;
        $stmt = $this->getPDO()->prepare($sql);
        $stmt->bindValue(':_uid', $id);
        $stmt->bindValue(':val', $value);

        $stmt->execute() || throw new DatabaseException('Failed to update attribute');
        return true;
    }

    /**
     * Delete Document
     *
     * @param string $collection
     * @param string $id
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function deleteDocument(string $collection, string $id): bool
    {
        $name = $this->filter($collection);

        $this->getPDO()->beginTransaction();

        $stmt = $this->getPDO()->prepare("DELETE FROM {$this->getSQLTable($name)} WHERE _uid = :_uid");
        $stmt->bindValue(':_uid', $id);

        $stmtPermissions = $this->getPDO()->prepare("DELETE FROM {$this->getSQLTable($name . '_perms')} WHERE _document = :_uid");
        $stmtPermissions->bindValue(':_uid', $id);

        try {
            $stmt->execute() || throw new DatabaseException('Failed to delete document');
            $stmtPermissions->execute() || throw new DatabaseException('Failed to clean permissions');
        } catch (\Throwable $th) {
            $this->getPDO()->rollBack();
            throw new DatabaseException($th->getMessage());
        }

        if (!$this->getPDO()->commit()) {
            throw new DatabaseException('Failed to commit transaction');
        }

        return true;
    }

    /**
     * Find Documents
     *
     * @param string $collection
     * @param array<Query> $queries
     * @param int|null $limit
     * @param int|null $offset
     * @param array<string> $orderAttributes
     * @param array<string> $orderTypes
     * @param array<string, mixed> $cursor
     * @param string $cursorDirection
     *
     * @return array<Document>
     * @throws Exception
     * @throws PDOException
     */
    public function find(string $collection, array $queries = [], ?int $limit = 25, ?int $offset = null, array $orderAttributes = [], array $orderTypes = [], array $cursor = [], string $cursorDirection = Database::CURSOR_AFTER, ?int $timeout = null): array
    {
        $name = $this->filter($collection);
        $roles = Authorization::getRoles();
        $where = [];
        $orders = [];

        $orderAttributes = \array_map(fn ($orderAttribute) => match ($orderAttribute) {
            '$id' => '_uid',
            '$createdAt' => '_createdAt',
            '$updatedAt' => '_updatedAt',
            default => $orderAttribute
        }, $orderAttributes);

        $hasIdAttribute = false;
        foreach ($orderAttributes as $i => $attribute) {
            if ($attribute === '_uid') {
                $hasIdAttribute = true;
            }

            $attribute = $this->filter($attribute);
            $orderType = $this->filter($orderTypes[$i] ?? Database::ORDER_ASC);

            // Get most dominant/first order attribute
            if ($i === 0 && !empty($cursor)) {
                $orderMethodInternalId = Query::TYPE_GREATER; // To preserve natural order
                $orderMethod = $orderType === Database::ORDER_DESC ? Query::TYPE_LESSER : Query::TYPE_GREATER;

                if ($cursorDirection === Database::CURSOR_BEFORE) {
                    $orderType = $orderType === Database::ORDER_ASC ? Database::ORDER_DESC : Database::ORDER_ASC;
                    $orderMethodInternalId = $orderType === Database::ORDER_ASC ? Query::TYPE_LESSER : Query::TYPE_GREATER;
                    $orderMethod = $orderType === Database::ORDER_DESC ? Query::TYPE_LESSER : Query::TYPE_GREATER;
                }

                $where[] = "(
                        table_main.`{$attribute}` {$this->getSQLOperator($orderMethod)} :cursor 
                        OR (
                            table_main.`{$attribute}` = :cursor 
                            AND
                            table_main._id {$this->getSQLOperator($orderMethodInternalId)} {$cursor['$internalId']}
                        )
                    )";
            } elseif ($cursorDirection === Database::CURSOR_BEFORE) {
                $orderType = $orderType === Database::ORDER_ASC ? Database::ORDER_DESC : Database::ORDER_ASC;
            }

            $orders[] = "`${attribute}` ${orderType}";
        }

        // Allow after pagination without any order
        if (empty($orderAttributes) && !empty($cursor)) {
            $orderType = $orderTypes[0] ?? Database::ORDER_ASC;

            if ($cursorDirection === Database::CURSOR_AFTER) {
                $orderMethod = $orderType === Database::ORDER_DESC
                    ? Query::TYPE_LESSER
                    : Query::TYPE_GREATER;
            } else {
                $orderMethod = $orderType === Database::ORDER_DESC
                    ? Query::TYPE_GREATER
                    : Query::TYPE_LESSER;
            }

            $where[] = "( table_main._id {$this->getSQLOperator($orderMethod)} {$cursor['$internalId']} )";
        }

        // Allow order type without any order attribute, fallback to the natural order (_id)
        if (!$hasIdAttribute) {
            if (empty($orderAttributes) && !empty($orderTypes)) {
                $order = $orderTypes[0] ?? Database::ORDER_ASC;
                if ($cursorDirection === Database::CURSOR_BEFORE) {
                    $order = $order === Database::ORDER_ASC ? Database::ORDER_DESC : Database::ORDER_ASC;
                }

                $orders[] = 'table_main._id ' . $this->filter($order);
            } else {
                $orders[] = 'table_main._id ' . ($cursorDirection === Database::CURSOR_AFTER ? Database::ORDER_ASC : Database::ORDER_DESC); // Enforce last ORDER by '_id'
            }
        }

        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_SELECT) {
                continue;
            }
            $where[] = $this->getSQLCondition($query);
        }


        if (Authorization::$status) {
            $where[] = $this->getSQLPermissionsCondition($name, $roles);
        }

        $sqlWhere = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sqlOrder = 'ORDER BY ' . implode(', ', $orders);
        $sqlLimit = \is_null($limit) ? '' : 'LIMIT :limit';
        $sqlLimit .= \is_null($offset) ? '' : ' OFFSET :offset';

        $selections = $this->getAttributeSelections($queries);

        $sql = "
            SELECT {$this->getAttributeProjection($selections, 'table_main')}
            FROM {$this->getSQLTable($name)} as table_main
            {$sqlWhere}
            {$sqlOrder}
            {$sqlLimit};
        ";

        if ($timeout) {
            $sql = $this->setTimeout($sql, $timeout);
        }

        $stmt = $this->getPDO()->prepare($sql);
        foreach ($queries as $query) {
            $this->bindConditionValue($stmt, $query);
        }

        if (!empty($cursor) && !empty($orderAttributes) && array_key_exists(0, $orderAttributes)) {
            $attribute = $orderAttributes[0];

            $attribute = match ($attribute) {
                '_uid' => '$id',
                '_createdAt' => '$createdAt',
                '_updatedAt' => '$updatedAt',
                default => $attribute
            };

            if (is_null($cursor[$attribute] ?? null)) {
                throw new DatabaseException("Order attribute '{$attribute}' is empty");
            }
            $stmt->bindValue(':cursor', $cursor[$attribute], $this->getPDOType($cursor[$attribute]));
        }

        if (!\is_null($limit)) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        if (!\is_null($offset)) {
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }

        try {
            $stmt->execute();
        } catch (PDOException $e) {
            $this->processException($e);
        }

        $results = $stmt->fetchAll();

        foreach ($results as $key => $value) {
            $results[$key]['$id'] = $value['_uid'];
            $results[$key]['$internalId'] = $value['_id'];
            $results[$key]['$createdAt'] = $value['_createdAt'];
            $results[$key]['$updatedAt'] = $value['_updatedAt'];
            $results[$key]['$permissions'] = json_decode($value['_permissions'] ?? '[]', true);

            unset($results[$key]['_uid']);
            unset($results[$key]['_id']);
            unset($results[$key]['_createdAt']);
            unset($results[$key]['_updatedAt']);
            unset($results[$key]['_permissions']);

            $results[$key] = new Document($results[$key]);
        }

        if ($cursorDirection === Database::CURSOR_BEFORE) {
            $results = array_reverse($results);
        }

        return $results;
    }

    /**
     * Count Documents
     *
     * @param string $collection
     * @param array<Query> $queries
     * @param int|null $max
     * @return int
     * @throws Exception
     * @throws PDOException
     */
    public function count(string $collection, array $queries = [], ?int $max = null): int
    {
        $name = $this->filter($collection);
        $roles = Authorization::getRoles();
        $where = [];
        $limit = \is_null($max) ? '' : 'LIMIT :max';

        foreach ($queries as $query) {
            $where[] = $this->getSQLCondition($query);
        }

        if (Authorization::$status) {
            $where[] = $this->getSQLPermissionsCondition($name, $roles);
        }

        $sqlWhere = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT COUNT(1) as sum
            FROM
                (
                    SELECT 1
                    FROM {$this->getSQLTable($name)} table_main
                    " . $sqlWhere . "
                    {$limit}
                ) table_count
        ";
        $stmt = $this->getPDO()->prepare($sql);
        foreach ($queries as $query) {
            $this->bindConditionValue($stmt, $query);
        }

        if (!\is_null($max)) {
            $stmt->bindValue(':max', $max, PDO::PARAM_INT);
        }

        $stmt->execute();

        $result = $stmt->fetch();

        return $result['sum'] ?? 0;
    }

    /**
     * Sum an Attribute
     *
     * @param string $collection
     * @param string $attribute
     * @param array<Query> $queries
     * @param int|null $max
     * @return int|float
     * @throws Exception
     * @throws PDOException
     */
    public function sum(string $collection, string $attribute, array $queries = [], ?int $max = null): int|float
    {
        $name = $this->filter($collection);
        $roles = Authorization::getRoles();
        $where = [];
        $limit = \is_null($max) ? '' : 'LIMIT :max';

        foreach ($queries as $query) {
            $where[] = $this->getSQLCondition($query);
        }

        if (Authorization::$status) {
            $where[] = $this->getSQLPermissionsCondition($name, $roles);
        }

        $sqlWhere = !empty($where) ? 'where ' . implode(' AND ', $where) : '';

        $stmt = $this->getPDO()->prepare("
            SELECT SUM({$attribute}) as sum
            FROM (
                SELECT {$attribute}
                FROM {$this->getSQLTable($name)} table_main
                 " . $sqlWhere . "
                {$limit}
            ) table_count
        ");

        foreach ($queries as $query) {
            $this->bindConditionValue($stmt, $query);
        }

        if (!\is_null($max)) {
            $stmt->bindValue(':max', $max, PDO::PARAM_INT);
        }

        $stmt->execute();

        $result = $stmt->fetch();

        return $result['sum'] ?? 0;
    }

    /**
     * Get the SQL projection given the selected attributes
     *
     * @param array<string> $selections
     * @param string $prefix
     * @return mixed
     * @throws Exception
     */
    protected function getAttributeProjection(array $selections, string $prefix = ''): mixed
    {
        if (empty($selections) || \in_array('*', $selections)) {
            if (!empty($prefix)) {
                return "`{$prefix}`.*";
            }
            return '*';
        }

        $selections[] = '_uid';
        $selections[] = '_id';
        $selections[] = '_createdAt';
        $selections[] = '_updatedAt';
        $selections[] = '_permissions';

        if (!empty($prefix)) {
            foreach ($selections as &$selection) {
                $selection = "`{$prefix}`.`{$this->filter($selection)}`";
            }
        } else {
            foreach ($selections as &$selection) {
                $selection = "`{$this->filter($selection)}`";
            }
        }

        return \implode(', ', $selections);
    }

    /*
     * Get SQL Condition
     *
     * @param Query $query
     * @return string
     * @throws Exception
     */
    protected function getSQLCondition(Query $query): string
    {
        $query->setAttribute(match ($query->getAttribute()) {
            '$id' => '_uid',
            '$createdAt' => '_createdAt',
            '$updatedAt' => '_updatedAt',
            default => $query->getAttribute()
        });

        $attribute = "`{$query->getAttribute()}`" ;
        $placeholder = $this->getSQLPlaceholder($query);

        switch ($query->getMethod()) {
            case Query::TYPE_SEARCH:
                return "MATCH(table_main.{$attribute}) AGAINST (:{$placeholder}_0 IN BOOLEAN MODE)";

            case Query::TYPE_BETWEEN:
                return "table_main.{$attribute} BETWEEN :{$placeholder}_0 AND :{$placeholder}_1";

            case Query::TYPE_IS_NULL:
            case Query::TYPE_IS_NOT_NULL:
                return "table_main.{$attribute} {$this->getSQLOperator($query->getMethod())}";

            default:
                $conditions = [];
                foreach ($query->getValues() as $key => $value) {
                    $conditions[] = $attribute.' '.$this->getSQLOperator($query->getMethod()).' :'.$placeholder.'_'.$key;
                }
                $condition = implode(' OR ', $conditions);
                return empty($condition) ? '' : '(' . $condition . ')';
        }
    }

    /**
     * Get SQL Type
     *
     * @param string $type
     * @param int $size
     * @param bool $signed
     * @return string
     * @throws Exception
     */
    protected function getSQLType(string $type, int $size, bool $signed = true): string
    {
        switch ($type) {
            case Database::VAR_STRING:
                // $size = $size * 4; // Convert utf8mb4 size to bytes
                if ($size > 16777215) {
                    return 'LONGTEXT';
                }

                if ($size > 65535) {
                    return 'MEDIUMTEXT';
                }

                if ($size > $this->getMaxVarcharLength()) {
                    return 'TEXT';
                }

                return "VARCHAR({$size})";

            case Database::VAR_INTEGER:  // We don't support zerofill: https://stackoverflow.com/a/5634147/2299554
                $signed = ($signed) ? '' : ' UNSIGNED';

                if ($size >= 8) { // INT = 4 bytes, BIGINT = 8 bytes
                    return 'BIGINT' . $signed;
                }

                return 'INT' . $signed;

            case Database::VAR_FLOAT:
                $signed = ($signed) ? '' : ' UNSIGNED';
                return 'DOUBLE' . $signed;

            case Database::VAR_BOOLEAN:
                return 'TINYINT(1)';

            case Database::VAR_RELATIONSHIP:
                return 'VARCHAR(255)';

            case Database::VAR_DATETIME:
                return 'DATETIME(3)';

            default:
                throw new DatabaseException('Unknown type: ' . $type . '. Must be one of ' . Database::VAR_STRING . ', ' . Database::VAR_INTEGER .  ', ' . Database::VAR_FLOAT . ', ' . Database::VAR_BOOLEAN . ', ' . Database::VAR_DATETIME . ', ' . Database::VAR_RELATIONSHIP);
        }
    }

    /**
     * Get SQL Index
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param array<string> $attributes
     * @return string
     * @throws Exception
     */
    protected function getSQLIndex(string $collection, string $id, string $type, array $attributes): string
    {
        $type = match ($type) {
            Database::INDEX_KEY,
            Database::INDEX_ARRAY => 'INDEX',
            Database::INDEX_UNIQUE => 'UNIQUE INDEX',
            Database::INDEX_FULLTEXT => 'FULLTEXT INDEX',
            default => throw new DatabaseException('Unknown index type: ' . $type . '. Must be one of ' . Database::INDEX_KEY . ', ' . Database::INDEX_UNIQUE . ', ' . Database::INDEX_ARRAY . ', ' . Database::INDEX_FULLTEXT),
        };

        return "CREATE {$type} `{$id}` ON {$this->getSQLTable($collection)} ( " . implode(', ', $attributes) . " )";
    }

    /**
     * Get PDO Type
     *
     * @param mixed $value
     * @return int
     * @throws Exception
     */
    protected function getPDOType(mixed $value): int
    {
        return match (gettype($value)) {
            'double', 'string' => PDO::PARAM_STR,
            'integer', 'boolean' => PDO::PARAM_INT,
            'NULL' => PDO::PARAM_NULL,
            default => throw new DatabaseException('Unknown PDO Type for ' . \gettype($value)),
        };
    }

    /**
     * Is fulltext Wildcard index supported?
     *
     * @return bool
     */
    public function getSupportForFulltextWildcardIndex(): bool
    {
        return true;
    }

    /**
     * Are timeouts supported?
     *
     * @return bool
     */
    public function getSupportForTimeouts(): bool
    {
        return true;
    }

    /**
     * Returns Max Execution Time
     * @param string $sql
     * @param int $milliseconds
     * @return string
     */
    protected function setTimeout(string $sql, int $milliseconds): string
    {
        if (!$this->getSupportForTimeouts()) {
            return $sql;
        }

        $seconds = $milliseconds / 1000;
        return "SET STATEMENT max_statement_time = {$seconds} FOR " . $sql;
    }

    /**
     * @param PDOException $e
     * @throws Timeout
     */
    protected function processException(PDOException $e): void
    {
        // Regular PDO
        if ($e->getCode() === '70100' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1969) {
            throw new Timeout($e->getMessage());
        }

        // PDOProxy switches errorInfo PDOProxy.php line 64
        if ($e->getCode() === 1969 && isset($e->errorInfo[0]) && $e->errorInfo[0] === '70100') {
            throw new Timeout($e->getMessage());
        }

        throw $e;
    }
}
