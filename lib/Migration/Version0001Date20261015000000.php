<?php
declare(strict_types=1);

namespace OCA\RetentionLock\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version0001Date20261015000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('retention_purges')) {
            return null;
        }
        $table = $schema->createTable('retention_purges');
        $table->addColumn('operation_id', Types::STRING, ['length' => 36, 'notnull' => true]);
        $table->addColumn('actor_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('owner_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('request_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
        $table->addColumn('target', Types::TEXT, ['notnull' => true]);
        $table->addColumn('reason', Types::TEXT, ['notnull' => true]);
        $table->addColumn('requested_at', Types::BIGINT, ['notnull' => true]);
        $table->addColumn('state', Types::STRING, ['length' => 16, 'notnull' => true]);
        $table->setPrimaryKey(['operation_id']);
        return $schema;
    }
}
