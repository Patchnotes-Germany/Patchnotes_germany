<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911230913 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ai_job (id INT AUTO_INCREMENT NOT NULL, task VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, provider VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, model VARCHAR(128) DEFAULT NULL, input_hash VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, payload JSON NOT NULL, result JSON DEFAULT NULL, error LONGTEXT DEFAULT NULL, attempts INT DEFAULT 0 NOT NULL, priority INT DEFAULT 0 NOT NULL, lease_until DATETIME DEFAULT NULL, subject_type VARCHAR(16) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, subject_id VARCHAR(96) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, leased_by_id INT DEFAULT NULL, INDEX idx_ai_job_claim (status, provider, priority, created_at), INDEX idx_ai_job_lease (status, lease_until), INDEX idx_ai_job_input (task, input_hash), INDEX IDX_71BF4E7EF6EAE6D0 (leased_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE ai_usage (id INT AUTO_INCREMENT NOT NULL, task VARCHAR(32) NOT NULL, provider VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, model VARCHAR(128) NOT NULL, input_tokens INT DEFAULT 0 NOT NULL, output_tokens INT DEFAULT 0 NOT NULL, cached_tokens INT DEFAULT 0 NOT NULL, cost_eur NUMERIC(12, 6) DEFAULT \'0.000000\' NOT NULL, duration_ms INT DEFAULT 0 NOT NULL, success TINYINT NOT NULL, subject_type VARCHAR(16) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, subject_id VARCHAR(96) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, created_at DATETIME NOT NULL, INDEX idx_ai_usage_created (created_at), INDEX idx_ai_usage_task (task, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE amending_act (id INT AUTO_INCREMENT NOT NULL, citation VARCHAR(128) NOT NULL COLLATE `utf8mb4_bin`, jurisdiction_code VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, gazette VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, year INT NOT NULL, number VARCHAR(32) NOT NULL, date DATE NOT NULL, title VARCHAR(1024) NOT NULL, url LONGTEXT NOT NULL, extracted_json JSON DEFAULT NULL, created_at DATETIME NOT NULL, raw_document_id INT DEFAULT NULL, INDEX idx_amending_act_date (jurisdiction_code, date), UNIQUE INDEX uniq_amending_act_citation (citation), INDEX IDX_60ADF997490B70FF (raw_document_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) DEFAULT NULL, roles JSON NOT NULL, lang VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, status VARCHAR(16) NOT NULL, email_verified_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, INDEX idx_user_status (status), UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, actor_label VARCHAR(128) NOT NULL, action VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, subject_type VARCHAR(32) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, subject_id VARCHAR(96) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, data JSON NOT NULL, created_at DATETIME NOT NULL, actor_id INT DEFAULT NULL, INDEX idx_audit_created (created_at), INDEX idx_audit_subject (subject_type, subject_id), INDEX IDX_F6E1C0F510DAF24A (actor_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE bill (id INT AUTO_INCREMENT NOT NULL, dip_id VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, title VARCHAR(1024) NOT NULL, initiator VARCHAR(255) DEFAULT NULL, stage VARCHAR(16) NOT NULL, stage_label VARCHAR(255) DEFAULT NULL, stage_history JSON NOT NULL, documents JSON NOT NULL, facts JSON NOT NULL, audience JSON NOT NULL, topics JSON NOT NULL, lands JSON NOT NULL, impact INT DEFAULT 0 NOT NULL, review_state VARCHAR(24) NOT NULL, pipeline_state VARCHAR(40) NOT NULL, source_updated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, amending_act_id INT DEFAULT NULL, INDEX idx_bill_stage (stage), INDEX idx_bill_updated (source_updated_at), UNIQUE INDEX uniq_bill_dip_id (dip_id), INDEX IDX_7A2119E3F304EE84 (amending_act_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE calendar_token (id INT AUTO_INCREMENT NOT NULL, token VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, created_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_calendar_token (token), INDEX IDX_3363A255A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE card (id INT AUTO_INCREMENT NOT NULL, subject_type VARCHAR(16) NOT NULL, subject_id VARCHAR(96) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, lang VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, sections JSON NOT NULL, rendered_html LONGTEXT DEFAULT NULL, master_hash VARCHAR(64) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, translation_kind VARCHAR(16) NOT NULL, stale TINYINT DEFAULT 0 NOT NULL, translated_by VARCHAR(128) DEFAULT NULL, reviewed_by VARCHAR(128) DEFAULT NULL, prompt_versions JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_card_stale (stale), UNIQUE INDEX uniq_card_subject_lang (subject_type, subject_id, lang), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE change_norm (id INT AUTO_INCREMENT NOT NULL, change_id VARCHAR(96) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, norm_id INT NOT NULL, before_version_id INT DEFAULT NULL, after_version_id INT DEFAULT NULL, UNIQUE INDEX uniq_change_norm (change_id, norm_id), INDEX IDX_F4EB9C1B213C8BF4 (change_id), INDEX IDX_F4EB9C1B6C19D51A (norm_id), INDEX IDX_F4EB9C1BBE8A786D (before_version_id), INDEX IDX_F4EB9C1B7048EABB (after_version_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE change_request (id INT AUTO_INCREMENT NOT NULL, repository VARCHAR(16) NOT NULL, forge_id VARCHAR(64) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, url LONGTEXT DEFAULT NULL, branch VARCHAR(255) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, kind VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, labels JSON NOT NULL, checks JSON NOT NULL, preview_accuracy DOUBLE PRECISION DEFAULT NULL, opened_at DATETIME NOT NULL, merged_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, merge_commit VARCHAR(40) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, change_id VARCHAR(96) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, bill_id INT DEFAULT NULL, INDEX idx_change_request_status (status), INDEX idx_change_request_branch (repository, branch), UNIQUE INDEX uniq_change_request_forge (repository, forge_id), INDEX IDX_CB902D36213C8BF4 (change_id), INDEX IDX_CB902D361A8C12F5 (bill_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE consent_record (id INT AUTO_INCREMENT NOT NULL, consent_type VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, text_version VARCHAR(32) NOT NULL, granted_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX idx_consent_user_type (user_id, consent_type), INDEX IDX_DA39FABEA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE delivery_index (id INT AUTO_INCREMENT NOT NULL, recipient_key VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, subject_type VARCHAR(16) NOT NULL, subject_id VARCHAR(96) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, kind VARCHAR(32) NOT NULL, first_sent_at DATETIME NOT NULL, INDEX idx_delivery_index_subject (subject_type, subject_id), UNIQUE INDEX uniq_delivery_index (recipient_key, subject_id, kind), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE digest (id INT AUTO_INCREMENT NOT NULL, iso_week VARCHAR(16) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, change_ids JSON NOT NULL, bill_ids JSON NOT NULL, generated_at DATETIME DEFAULT NULL, sent_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_digest_week (iso_week), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE feature_flag (flag_key VARCHAR(96) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, enabled TINYINT NOT NULL, note VARCHAR(255) DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (flag_key)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE glossary_term (id INT AUTO_INCREMENT NOT NULL, lang VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, term_de VARCHAR(128) NOT NULL COLLATE `utf8mb4_bin`, render VARCHAR(255) NOT NULL, explanation LONGTEXT DEFAULT NULL, keep_german TINYINT DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_glossary_lang_term (lang, term_de), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE jurisdiction (code VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, type VARCHAR(16) NOT NULL, name_de VARCHAR(128) NOT NULL, names JSON NOT NULL, enabled TINYINT NOT NULL, PRIMARY KEY (code)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE law (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(128) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, type VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, abbreviation VARCHAR(64) DEFAULT NULL COLLATE `utf8mb4_bin`, title VARCHAR(512) NOT NULL, short_title VARCHAR(512) DEFAULT NULL, date_of_issue DATE DEFAULT NULL, promulgation VARCHAR(255) DEFAULT NULL, status_note LONGTEXT DEFAULT NULL, last_amending_act_citation VARCHAR(128) DEFAULT NULL, structure JSON NOT NULL, topics JSON NOT NULL, source_name VARCHAR(64) NOT NULL, source_url LONGTEXT NOT NULL, source_document_id VARCHAR(64) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, last_synced_commit VARCHAR(40) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, missing_runs INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, jurisdiction_code VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, INDEX idx_law_status (status), INDEX idx_law_abbreviation (abbreviation), UNIQUE INDEX uniq_law_jurisdiction_slug (jurisdiction_code, slug), INDEX IDX_C0B552F5D9290B3 (jurisdiction_code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE law_change (id VARCHAR(96) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, kind VARCHAR(16) NOT NULL, jurisdiction_code VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, lands JSON NOT NULL, title_de VARCHAR(512) DEFAULT NULL, stage VARCHAR(16) NOT NULL, effective_state VARCHAR(24) NOT NULL, first_effective_date DATE DEFAULT NULL, facts JSON NOT NULL, audience JSON NOT NULL, topics JSON NOT NULL, impact INT DEFAULT 0 NOT NULL, impact_rationale LONGTEXT DEFAULT NULL, confidence DOUBLE PRECISION DEFAULT NULL, verify_score DOUBLE PRECISION DEFAULT NULL, review_state VARCHAR(24) NOT NULL, pipeline_state VARCHAR(40) NOT NULL, mixed_attribution TINYINT DEFAULT 0 NOT NULL, detected_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, settling_started_at DATETIME DEFAULT NULL, published_at DATETIME DEFAULT NULL, correlation_id VARCHAR(32) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, amending_act_id INT DEFAULT NULL, bill_id INT DEFAULT NULL, INDEX idx_law_change_published (published_at), INDEX idx_law_change_review (review_state), INDEX idx_law_change_pipeline (pipeline_state), INDEX idx_law_change_jurisdiction (jurisdiction_code, published_at), INDEX IDX_E1D4473BF304EE84 (amending_act_id), INDEX IDX_E1D4473B1A8C12F5 (bill_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE norm (id INT AUTO_INCREMENT NOT NULL, norm_key VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, designation VARCHAR(64) DEFAULT NULL COLLATE `utf8mb4_bin`, title VARCHAR(512) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, status VARCHAR(16) NOT NULL, source_doknr VARCHAR(64) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, created_at DATETIME NOT NULL, law_id INT NOT NULL, current_version_id INT DEFAULT NULL, INDEX idx_norm_status (status), UNIQUE INDEX uniq_norm_law_key (law_id, norm_key), INDEX IDX_973CD5A054EB478 (law_id), INDEX IDX_973CD5A09407EE77 (current_version_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE norm_translation (id INT AUTO_INCREMENT NOT NULL, lang VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, content LONGTEXT NOT NULL, model VARCHAR(128) DEFAULT NULL, prompt_version INT DEFAULT 1 NOT NULL, kind VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, norm_version_id INT NOT NULL, UNIQUE INDEX uniq_norm_translation (norm_version_id, lang, prompt_version), INDEX IDX_30114A318DF2A716 (norm_version_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE norm_version (id INT AUTO_INCREMENT NOT NULL, content_hash VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, content_de LONGTEXT NOT NULL, git_commit VARCHAR(40) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, committed_at DATETIME NOT NULL, norm_id INT NOT NULL, change_id VARCHAR(96) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, INDEX idx_norm_version_hash (norm_id, content_hash), UNIQUE INDEX uniq_norm_version_commit (norm_id, git_commit), INDEX IDX_887CC5066C19D51A (norm_id), INDEX IDX_887CC506213C8BF4 (change_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE notification (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(32) NOT NULL, channel VARCHAR(24) NOT NULL, subject_type VARCHAR(16) NOT NULL, subject_id VARCHAR(96) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, variant VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, idempotency_key VARCHAR(128) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, status VARCHAR(16) NOT NULL, lang VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, scheduled_at DATETIME NOT NULL, sent_at DATETIME DEFAULT NULL, error VARCHAR(512) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT DEFAULT NULL, telegram_link_id INT DEFAULT NULL, INDEX idx_notification_scheduled (status, scheduled_at), INDEX idx_notification_subject (subject_type, subject_id), UNIQUE INDEX uniq_notification_idempotency (idempotency_key), INDEX IDX_BF5476CAA76ED395 (user_id), INDEX IDX_BF5476CAF6E26387 (telegram_link_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE notification_preference (id INT AUTO_INCREMENT NOT NULL, channels JSON NOT NULL, kinds JSON NOT NULL, quiet_hours_start VARCHAR(5) DEFAULT NULL, quiet_hours_end VARCHAR(5) DEFAULT NULL, max_instant_per_day INT DEFAULT NULL, updated_at DATETIME NOT NULL, user_id INT DEFAULT NULL, telegram_link_id INT DEFAULT NULL, UNIQUE INDEX uniq_notification_preference_user (user_id), UNIQUE INDEX uniq_notification_preference_telegram (telegram_link_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE plenary_summary (id INT AUTO_INCREMENT NOT NULL, iso_week VARCHAR(16) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, protocols JSON NOT NULL, generated_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_plenary_week (iso_week), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE push_subscription (id INT AUTO_INCREMENT NOT NULL, endpoint LONGTEXT NOT NULL, endpoint_hash VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, public_key VARCHAR(255) NOT NULL, auth_token VARCHAR(255) NOT NULL, lang VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_push_endpoint (endpoint_hash), INDEX IDX_562830F3A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE setting (setting_key VARCHAR(96) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, value JSON DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (setting_key)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE source (source_key VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, jurisdiction_code VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, title VARCHAR(255) NOT NULL, capabilities JSON NOT NULL, enabled TINYINT NOT NULL, health_state VARCHAR(16) NOT NULL, health_note LONGTEXT DEFAULT NULL, last_run_at DATETIME DEFAULT NULL, last_success_at DATETIME DEFAULT NULL, last_change_at DATETIME DEFAULT NULL, PRIMARY KEY (source_key)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE source_document (id INT AUTO_INCREMENT NOT NULL, url LONGTEXT NOT NULL, url_hash VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, content_hash VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, storage_path VARCHAR(512) NOT NULL, fetched_at DATETIME NOT NULL, http_status INT NOT NULL, content_type VARCHAR(128) DEFAULT NULL, size_bytes INT DEFAULT 0 NOT NULL, etag VARCHAR(255) DEFAULT NULL, last_modified VARCHAR(255) DEFAULT NULL, source_key VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, INDEX idx_source_document_url (source_key, url_hash), INDEX idx_source_document_fetched (fetched_at), UNIQUE INDEX uniq_source_document_hash (source_key, content_hash), INDEX IDX_9B49BCA4DB64AEE8 (source_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE source_run (id INT AUTO_INCREMENT NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, status VARCHAR(16) NOT NULL, documents_seen INT DEFAULT 0 NOT NULL, documents_changed INT DEFAULT 0 NOT NULL, error_count INT DEFAULT 0 NOT NULL, `log` LONGTEXT DEFAULT NULL, correlation_id VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, source_key VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, INDEX idx_source_run_started (source_key, started_at), INDEX IDX_182A181DB64AEE8 (source_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE taxonomy_tag (id INT AUTO_INCREMENT NOT NULL, tag_key VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, tag_group VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, names JSON NOT NULL, descriptions JSON NOT NULL, exclusive_group TINYINT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, INDEX idx_taxonomy_group (tag_group), UNIQUE INDEX uniq_taxonomy_tag_key (tag_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE telegram_link (id INT AUTO_INCREMENT NOT NULL, chat_id BIGINT NOT NULL, lang VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, land VARCHAR(8) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, tags LONGBLOB DEFAULT NULL, topics JSON NOT NULL, answered_groups JSON NOT NULL, paused TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, last_seen_at DATETIME DEFAULT NULL, user_id INT DEFAULT NULL, INDEX idx_telegram_land (land), UNIQUE INDEX uniq_telegram_chat (chat_id), INDEX IDX_4ABFBFE1A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE user_profile (id INT AUTO_INCREMENT NOT NULL, land VARCHAR(8) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, tags LONGBLOB DEFAULT NULL, topics JSON NOT NULL, answered_groups JSON NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_user_profile_land (land), UNIQUE INDEX uniq_user_profile_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE user_translator_language (id INT AUTO_INCREMENT NOT NULL, lang VARCHAR(8) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, user_id INT NOT NULL, UNIQUE INDEX uniq_user_translator_language (user_id, lang), INDEX IDX_28F312C2A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('CREATE TABLE worker_token (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(64) NOT NULL, token_hash VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, created_at DATETIME NOT NULL, last_seen_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_worker_token_hash (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci`');
        $this->addSql('ALTER TABLE ai_job ADD CONSTRAINT FK_71BF4E7EF6EAE6D0 FOREIGN KEY (leased_by_id) REFERENCES worker_token (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE amending_act ADD CONSTRAINT FK_60ADF997490B70FF FOREIGN KEY (raw_document_id) REFERENCES source_document (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F510DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bill ADD CONSTRAINT FK_7A2119E3F304EE84 FOREIGN KEY (amending_act_id) REFERENCES amending_act (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE calendar_token ADD CONSTRAINT FK_3363A255A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE change_norm ADD CONSTRAINT FK_F4EB9C1B213C8BF4 FOREIGN KEY (change_id) REFERENCES law_change (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE change_norm ADD CONSTRAINT FK_F4EB9C1B6C19D51A FOREIGN KEY (norm_id) REFERENCES norm (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE change_norm ADD CONSTRAINT FK_F4EB9C1BBE8A786D FOREIGN KEY (before_version_id) REFERENCES norm_version (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE change_norm ADD CONSTRAINT FK_F4EB9C1B7048EABB FOREIGN KEY (after_version_id) REFERENCES norm_version (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE change_request ADD CONSTRAINT FK_CB902D36213C8BF4 FOREIGN KEY (change_id) REFERENCES law_change (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE change_request ADD CONSTRAINT FK_CB902D361A8C12F5 FOREIGN KEY (bill_id) REFERENCES bill (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE consent_record ADD CONSTRAINT FK_DA39FABEA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE law ADD CONSTRAINT FK_C0B552F5D9290B3 FOREIGN KEY (jurisdiction_code) REFERENCES jurisdiction (code)');
        $this->addSql('ALTER TABLE law_change ADD CONSTRAINT FK_E1D4473BF304EE84 FOREIGN KEY (amending_act_id) REFERENCES amending_act (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE law_change ADD CONSTRAINT FK_E1D4473B1A8C12F5 FOREIGN KEY (bill_id) REFERENCES bill (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE norm ADD CONSTRAINT FK_973CD5A054EB478 FOREIGN KEY (law_id) REFERENCES law (id)');
        $this->addSql('ALTER TABLE norm ADD CONSTRAINT FK_973CD5A09407EE77 FOREIGN KEY (current_version_id) REFERENCES norm_version (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE norm_translation ADD CONSTRAINT FK_30114A318DF2A716 FOREIGN KEY (norm_version_id) REFERENCES norm_version (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE norm_version ADD CONSTRAINT FK_887CC5066C19D51A FOREIGN KEY (norm_id) REFERENCES norm (id)');
        $this->addSql('ALTER TABLE norm_version ADD CONSTRAINT FK_887CC506213C8BF4 FOREIGN KEY (change_id) REFERENCES law_change (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAF6E26387 FOREIGN KEY (telegram_link_id) REFERENCES telegram_link (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE notification_preference ADD CONSTRAINT FK_A61B1571A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification_preference ADD CONSTRAINT FK_A61B1571F6E26387 FOREIGN KEY (telegram_link_id) REFERENCES telegram_link (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_562830F3A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE source_document ADD CONSTRAINT FK_9B49BCA4DB64AEE8 FOREIGN KEY (source_key) REFERENCES source (source_key)');
        $this->addSql('ALTER TABLE source_run ADD CONSTRAINT FK_182A181DB64AEE8 FOREIGN KEY (source_key) REFERENCES source (source_key)');
        $this->addSql('ALTER TABLE telegram_link ADD CONSTRAINT FK_4ABFBFE1A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_profile ADD CONSTRAINT FK_D95AB405A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_translator_language ADD CONSTRAINT FK_28F312C2A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ai_job DROP FOREIGN KEY FK_71BF4E7EF6EAE6D0');
        $this->addSql('ALTER TABLE amending_act DROP FOREIGN KEY FK_60ADF997490B70FF');
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F510DAF24A');
        $this->addSql('ALTER TABLE bill DROP FOREIGN KEY FK_7A2119E3F304EE84');
        $this->addSql('ALTER TABLE calendar_token DROP FOREIGN KEY FK_3363A255A76ED395');
        $this->addSql('ALTER TABLE change_norm DROP FOREIGN KEY FK_F4EB9C1B213C8BF4');
        $this->addSql('ALTER TABLE change_norm DROP FOREIGN KEY FK_F4EB9C1B6C19D51A');
        $this->addSql('ALTER TABLE change_norm DROP FOREIGN KEY FK_F4EB9C1BBE8A786D');
        $this->addSql('ALTER TABLE change_norm DROP FOREIGN KEY FK_F4EB9C1B7048EABB');
        $this->addSql('ALTER TABLE change_request DROP FOREIGN KEY FK_CB902D36213C8BF4');
        $this->addSql('ALTER TABLE change_request DROP FOREIGN KEY FK_CB902D361A8C12F5');
        $this->addSql('ALTER TABLE consent_record DROP FOREIGN KEY FK_DA39FABEA76ED395');
        $this->addSql('ALTER TABLE law DROP FOREIGN KEY FK_C0B552F5D9290B3');
        $this->addSql('ALTER TABLE law_change DROP FOREIGN KEY FK_E1D4473BF304EE84');
        $this->addSql('ALTER TABLE law_change DROP FOREIGN KEY FK_E1D4473B1A8C12F5');
        $this->addSql('ALTER TABLE norm DROP FOREIGN KEY FK_973CD5A054EB478');
        $this->addSql('ALTER TABLE norm DROP FOREIGN KEY FK_973CD5A09407EE77');
        $this->addSql('ALTER TABLE norm_translation DROP FOREIGN KEY FK_30114A318DF2A716');
        $this->addSql('ALTER TABLE norm_version DROP FOREIGN KEY FK_887CC5066C19D51A');
        $this->addSql('ALTER TABLE norm_version DROP FOREIGN KEY FK_887CC506213C8BF4');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAA76ED395');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CAF6E26387');
        $this->addSql('ALTER TABLE notification_preference DROP FOREIGN KEY FK_A61B1571A76ED395');
        $this->addSql('ALTER TABLE notification_preference DROP FOREIGN KEY FK_A61B1571F6E26387');
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY FK_562830F3A76ED395');
        $this->addSql('ALTER TABLE source_document DROP FOREIGN KEY FK_9B49BCA4DB64AEE8');
        $this->addSql('ALTER TABLE source_run DROP FOREIGN KEY FK_182A181DB64AEE8');
        $this->addSql('ALTER TABLE telegram_link DROP FOREIGN KEY FK_4ABFBFE1A76ED395');
        $this->addSql('ALTER TABLE user_profile DROP FOREIGN KEY FK_D95AB405A76ED395');
        $this->addSql('ALTER TABLE user_translator_language DROP FOREIGN KEY FK_28F312C2A76ED395');
        $this->addSql('DROP TABLE ai_job');
        $this->addSql('DROP TABLE ai_usage');
        $this->addSql('DROP TABLE amending_act');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE bill');
        $this->addSql('DROP TABLE calendar_token');
        $this->addSql('DROP TABLE card');
        $this->addSql('DROP TABLE change_norm');
        $this->addSql('DROP TABLE change_request');
        $this->addSql('DROP TABLE consent_record');
        $this->addSql('DROP TABLE delivery_index');
        $this->addSql('DROP TABLE digest');
        $this->addSql('DROP TABLE feature_flag');
        $this->addSql('DROP TABLE glossary_term');
        $this->addSql('DROP TABLE jurisdiction');
        $this->addSql('DROP TABLE law');
        $this->addSql('DROP TABLE law_change');
        $this->addSql('DROP TABLE norm');
        $this->addSql('DROP TABLE norm_translation');
        $this->addSql('DROP TABLE norm_version');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE notification_preference');
        $this->addSql('DROP TABLE plenary_summary');
        $this->addSql('DROP TABLE push_subscription');
        $this->addSql('DROP TABLE setting');
        $this->addSql('DROP TABLE source');
        $this->addSql('DROP TABLE source_document');
        $this->addSql('DROP TABLE source_run');
        $this->addSql('DROP TABLE taxonomy_tag');
        $this->addSql('DROP TABLE telegram_link');
        $this->addSql('DROP TABLE user_profile');
        $this->addSql('DROP TABLE user_translator_language');
        $this->addSql('DROP TABLE worker_token');
    }
}
