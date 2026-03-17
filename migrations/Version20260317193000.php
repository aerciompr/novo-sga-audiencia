<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Migrations\AbstractVersion;
use Doctrine\DBAL\Schema\Schema;

final class Version20260317193000 extends AbstractVersion
{
    public function getDescription(): string
    {
        return 'Cria tabelas para módulo de audiências (partes e testemunhas associadas)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "CREATE TABLE IF NOT EXISTS audiencia (
                id INT AUTO_INCREMENT NOT NULL,
                unidade_id INT NOT NULL,
                titulo VARCHAR(120) NOT NULL,
                sala VARCHAR(60) NOT NULL,
                status VARCHAR(20) NOT NULL,
                criado_em DATETIME NOT NULL,
                INDEX IDX_AUDIENCIA_UNIDADE (unidade_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
        );

        $this->addSql(
            "CREATE TABLE IF NOT EXISTS audiencia_pessoa (
                id INT AUTO_INCREMENT NOT NULL,
                audiencia_id INT NOT NULL,
                parte_id INT DEFAULT NULL,
                atendimento_id INT DEFAULT NULL,
                tipo VARCHAR(20) NOT NULL,
                nome VARCHAR(120) NOT NULL,
                documento VARCHAR(30) DEFAULT NULL,
                criado_em DATETIME NOT NULL,
                INDEX IDX_AUDIENCIA_PESSOA_AUDIENCIA (audiencia_id),
                INDEX IDX_AUDIENCIA_PESSOA_PARTE (parte_id),
                INDEX IDX_AUDIENCIA_PESSOA_ATENDIMENTO (atendimento_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB"
        );

        $this->addSql('ALTER TABLE audiencia ADD CONSTRAINT FK_AUDIENCIA_UNIDADE FOREIGN KEY (unidade_id) REFERENCES unidades (id)');
        $this->addSql('ALTER TABLE audiencia_pessoa ADD CONSTRAINT FK_AUDIENCIA_PESSOA_AUDIENCIA FOREIGN KEY (audiencia_id) REFERENCES audiencia (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audiencia_pessoa ADD CONSTRAINT FK_AUDIENCIA_PESSOA_PARTE FOREIGN KEY (parte_id) REFERENCES audiencia_pessoa (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE audiencia_pessoa ADD CONSTRAINT FK_AUDIENCIA_PESSOA_ATENDIMENTO FOREIGN KEY (atendimento_id) REFERENCES atendimentos (id) ON DELETE SET NULL');
    }
}
