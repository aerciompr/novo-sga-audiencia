<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migrations\AbstractVersion;
use Doctrine\DBAL\Schema\Schema;

final class Version20260317195500 extends AbstractVersion
{
    public function getDescription(): string
    {
        return 'Adiciona coluna sala na tabela audiencia';
    }

    public function up(Schema $schema): void
    {
        if (!$this->existsColumn('audiencia', 'sala')) {
            $this->addSql("ALTER TABLE audiencia ADD sala VARCHAR(60) NOT NULL DEFAULT 'Sala'");
        }
    }
}
