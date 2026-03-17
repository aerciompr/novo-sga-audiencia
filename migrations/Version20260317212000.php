<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migrations\AbstractVersion;
use Doctrine\DBAL\Schema\Schema;

final class Version20260317212000 extends AbstractVersion
{
    public function getDescription(): string
    {
        return 'Adiciona controle de situacao de pessoas da audiencia';
    }

    public function up(Schema $schema): void
    {
        if (!$this->existsColumn('audiencia_pessoa', 'situacao')) {
            $this->addSql("ALTER TABLE audiencia_pessoa ADD situacao VARCHAR(20) NOT NULL DEFAULT 'aguardando'");
        }
        if (!$this->existsColumn('audiencia_pessoa', 'dt_chamada')) {
            $this->addSql('ALTER TABLE audiencia_pessoa ADD dt_chamada DATETIME DEFAULT NULL');
        }
    }
}

