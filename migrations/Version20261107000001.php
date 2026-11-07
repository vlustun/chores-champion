<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, email VARCHAR(180) NOT NULL, phone_nr VARCHAR(32) DEFAULT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, timezone VARCHAR(64) DEFAULT \'UTC\' NOT NULL, timezone_manual TINYINT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_log (id BIGINT AUTO_INCREMENT NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id VARCHAR(64) DEFAULT NULL, action VARCHAR(16) NOT NULL, changes JSON NOT NULL, ip VARCHAR(45) DEFAULT NULL, request_id VARCHAR(36) DEFAULT NULL, created_at DATETIME(6) NOT NULL, actor_id INT DEFAULT NULL, INDEX idx_audit_entity (entity_type, entity_id), INDEX idx_audit_actor (actor_id), INDEX idx_audit_created (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, priority VARCHAR(16) NOT NULL, reward_value INT DEFAULT NULL, recurrence_kind VARCHAR(16) NOT NULL, recurrence_config JSON DEFAULT NULL, is_active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, type_id INT NOT NULL, created_by_id INT NOT NULL, INDEX idx_task_active_priority (is_active, priority), INDEX IDX_527EDB25C54C8C93 (type_id), INDEX IDX_527EDB25B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_type (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, slug VARCHAR(80) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_task_type_slug (slug), UNIQUE INDEX uniq_task_type_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_user (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(16) NOT NULL, started_at DATETIME(6) DEFAULT NULL, completed_at DATETIME(6) DEFAULT NULL, points_gained INT DEFAULT 0 NOT NULL, deadline_at DATETIME(6) DEFAULT NULL, user_timezone VARCHAR(64) NOT NULL, week_key VARCHAR(10) NOT NULL, active_key VARCHAR(40) DEFAULT NULL, created_at DATETIME(6) NOT NULL, version INT DEFAULT 1 NOT NULL, task_id INT NOT NULL, user_id INT NOT NULL, parent_task_user_id INT DEFAULT NULL, INDEX idx_tu_user_status (user_id, status), INDEX idx_tu_week (week_key), INDEX idx_tu_task (task_id), INDEX idx_tu_deadline (status, deadline_at), UNIQUE INDEX uniq_tu_active_key (active_key), INDEX IDX_FE204232A76ED395 (user_id), INDEX IDX_FE204232E8E1A451 (parent_task_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE weekly_result (id INT AUTO_INCREMENT NOT NULL, week_key VARCHAR(10) NOT NULL, week_start_utc DATETIME(6) NOT NULL, total_points INT DEFAULT 0 NOT NULL, completed_count INT DEFAULT 0 NOT NULL, rank_in_week INT DEFAULT NULL, created_at DATETIME(6) NOT NULL, user_id INT NOT NULL, INDEX idx_weekly_week (week_key), UNIQUE INDEX uniq_weekly_user_week (user_id, week_key), INDEX IDX_EFBE35EA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F510DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25C54C8C93 FOREIGN KEY (type_id) REFERENCES task_type (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE task_user ADD CONSTRAINT FK_FE2042328DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE task_user ADD CONSTRAINT FK_FE204232A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE task_user ADD CONSTRAINT FK_FE204232E8E1A451 FOREIGN KEY (parent_task_user_id) REFERENCES task_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE weekly_result ADD CONSTRAINT FK_EFBE35EA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE RESTRICT');

        // Microsecond UTC instants (DATETIME(6)) - DBAL does not emit the precision on MySQL by itself.
        $this->addSql("INSERT INTO task_type (name, slug, is_active) VALUES
            ('Cleaning', 'cleaning', 1), ('Cooking', 'cooking', 1), ('Pet', 'pet', 1),
            ('Hygiene', 'hygiene', 1), ('Medication', 'medication', 1),
            ('Errands', 'errands', 1), ('Others', 'others', 1)");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F510DAF24A');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_527EDB25C54C8C93');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_527EDB25B03A8386');
        $this->addSql('ALTER TABLE task_user DROP FOREIGN KEY FK_FE2042328DB60186');
        $this->addSql('ALTER TABLE task_user DROP FOREIGN KEY FK_FE204232A76ED395');
        $this->addSql('ALTER TABLE task_user DROP FOREIGN KEY FK_FE204232E8E1A451');
        $this->addSql('ALTER TABLE weekly_result DROP FOREIGN KEY FK_EFBE35EA76ED395');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE task');
        $this->addSql('DROP TABLE task_type');
        $this->addSql('DROP TABLE task_user');
        $this->addSql('DROP TABLE weekly_result');
    }
}
