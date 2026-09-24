<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

use Quantum\Database\Schema\SchemaManager;

interface MigrationInterface
{
    public function up(SchemaManager $schema): void;

    public function down(SchemaManager $schema): void;
}
