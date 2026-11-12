-- =============================================================================
-- TradingAgents PHP 8.4 — Multi-Agent AI Financial Trading Analysis Platform
-- Complete Production MySQL 8.x / MariaDB 10.6+ Schema (InnoDB, utf8mb4)
-- Compatible with Namecheap Shared Hosting / cPanel / phpMyAdmin
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- -----------------------------------------------------------------------------
-- 1. users: Multi-user authentication & RBAC (Admin, Analyst, Viewer)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(64) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name` VARCHAR(120) NOT NULL DEFAULT '',
    `role` ENUM('admin', 'analyst', 'viewer') NOT NULL DEFAULT 'analyst',
    `theme_preference` ENUM('dark', 'light', 'system') NOT NULL DEFAULT 'light',
    `risk_profile` ENUM('aggressive', 'neutral', 'conservative') NOT NULL DEFAULT 'neutral',
    `telegram_chat_id` VARCHAR(64) DEFAULT NULL,
    `email_alerts` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `api_access_token_hash` VARCHAR(128) DEFAULT NULL,
    `last_login_at` DATETIME DEFAULT NULL,
    `last_login_ip` VARCHAR(45) DEFAULT NULL,
    `failed_login_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_username` (`username`),
    UNIQUE KEY `uk_users_email` (`email`),
    KEY `idx_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. system_settings: Global configuration & TradingAgents workflow parameters
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` MEDIUMTEXT DEFAULT NULL,
    `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `is_public` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`),
    KEY `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. api_keys: Encrypted AI Provider & Market Data Vendor API Keys Vault
--    Supports multiple keys per provider, automatic rotation, rate-limit tracking,
--    health validation, and custom OpenAI-compatible endpoints.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_keys` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider_slug` VARCHAR(64) NOT NULL COMMENT 'e.g. openrouter, openai, google, anthropic, deepseek, groq, mistral, xai, cohere, together, fireworks, cerebras, nvidia, perplexity, alphavantage, fred, sec_edgar',
    `provider_category` ENUM('llm', 'market_data', 'notification') NOT NULL DEFAULT 'llm',
    `key_label` VARCHAR(120) NOT NULL,
    `encrypted_key` TEXT NOT NULL COMMENT 'AES-256-GCM encrypted key payload (base64 iv:tag:ciphertext)',
    `key_hint` VARCHAR(24) NOT NULL COMMENT 'Masked preview e.g. sk-or-...9a4f',
    `base_url` VARCHAR(255) DEFAULT NULL COMMENT 'Optional custom endpoint override',
    `tier_type` ENUM('free', 'paid', 'trial') NOT NULL DEFAULT 'free',
    `priority` SMALLINT NOT NULL DEFAULT 10 COMMENT 'Lower number = higher priority in rotation',
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `verification_status` ENUM('unverified', 'valid', 'invalid', 'rate_limited', 'quota_exceeded') NOT NULL DEFAULT 'unverified',
    `last_verified_at` DATETIME DEFAULT NULL,
    `last_used_at` DATETIME DEFAULT NULL,
    `cooldown_until` DATETIME DEFAULT NULL COMMENT 'Automatic backoff timestamp when HTTP 429 occurs',
    `total_requests` INT UNSIGNED NOT NULL DEFAULT 0,
    `failed_requests` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_tokens` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(500) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_apikeys_provider_enabled` (`provider_slug`, `is_enabled`, `priority`),
    KEY `idx_apikeys_cooldown` (`cooldown_until`),
    KEY `idx_apikeys_category` (`provider_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. analyses: Master record for each multi-agent trading analysis run
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `analyses` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_uuid` CHAR(36) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `ticker` VARCHAR(32) NOT NULL,
    `company_name` VARCHAR(191) DEFAULT NULL,
    `asset_type` ENUM('stock', 'crypto', 'etf', 'forex', 'index') NOT NULL DEFAULT 'stock',
    `trade_date` DATE NOT NULL,
    `status` ENUM('queued', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'queued',
    `current_step` VARCHAR(64) NOT NULL DEFAULT 'init',
    `progress_pct` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `selected_analysts` VARCHAR(191) NOT NULL DEFAULT 'market,social,news,fundamentals',
    `max_debate_rounds` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `max_risk_rounds` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `quick_think_provider` VARCHAR(64) NOT NULL DEFAULT 'openrouter',
    `quick_think_model` VARCHAR(150) NOT NULL DEFAULT 'openrouter/free',
    `deep_think_provider` VARCHAR(64) NOT NULL DEFAULT 'openrouter',
    `deep_think_model` VARCHAR(150) NOT NULL DEFAULT 'openrouter/free',
    `output_language` VARCHAR(48) NOT NULL DEFAULT 'English',
    `instrument_context` TEXT DEFAULT NULL,
    `portfolio_context` TEXT DEFAULT NULL,
    `past_memory_context` TEXT DEFAULT NULL,
    -- Synthesized Decision Fields
    `research_recommendation` ENUM('Buy', 'Overweight', 'Hold', 'Underweight', 'Sell', 'REVIEW') DEFAULT NULL,
    `trader_action` ENUM('Buy', 'Hold', 'Sell') DEFAULT NULL,
    `entry_price` DECIMAL(18, 6) DEFAULT NULL,
    `stop_loss` DECIMAL(18, 6) DEFAULT NULL,
    `price_target` DECIMAL(18, 6) DEFAULT NULL,
    `position_sizing` VARCHAR(120) DEFAULT NULL,
    `time_horizon` VARCHAR(120) DEFAULT NULL,
    `final_rating` ENUM('Buy', 'Overweight', 'Hold', 'Underweight', 'Sell', 'REVIEW') DEFAULT NULL,
    `sentiment_band` VARCHAR(40) DEFAULT NULL,
    `sentiment_score` DECIMAL(4, 2) DEFAULT NULL,
    `sentiment_confidence` VARCHAR(24) DEFAULT NULL,
    `market_price_at_analysis` DECIMAL(18, 6) DEFAULT NULL,
    `currency` VARCHAR(16) NOT NULL DEFAULT 'USD',
    `executive_summary` TEXT DEFAULT NULL,
    `investment_thesis` MEDIUMTEXT DEFAULT NULL,
    `full_state_json` LONGTEXT DEFAULT NULL,
    `total_llm_calls` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `total_tokens_used` INT UNSIGNED NOT NULL DEFAULT 0,
    `execution_time_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `error_message` TEXT DEFAULT NULL,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_analyses_uuid` (`run_uuid`),
    KEY `idx_analyses_user_created` (`user_id`, `created_at` DESC),
    KEY `idx_analyses_ticker_date` (`ticker`, `trade_date` DESC),
    KEY `idx_analyses_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. agent_reports: Individual step outputs, reasoning, and debate transcripts
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agent_reports` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `analysis_id` INT UNSIGNED NOT NULL,
    `step_key` VARCHAR(64) NOT NULL COMMENT 'e.g. market_analyst, sentiment_analyst, news_analyst, fundamentals_analyst, bull_round_1, bear_round_1, research_manager, trader, risk_aggressive_1, risk_conservative_1, risk_neutral_1, portfolio_manager',
    `agent_role` VARCHAR(80) NOT NULL,
    `stage_group` ENUM('analyst_team', 'research_debate', 'trader_desk', 'risk_committee', 'portfolio_manager', 'memory_reflection') NOT NULL,
    `round_number` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `provider_used` VARCHAR(64) DEFAULT NULL,
    `model_used` VARCHAR(150) DEFAULT NULL,
    `report_markdown` LONGTEXT NOT NULL,
    `structured_json` JSON DEFAULT NULL,
    `tool_calls_log` TEXT DEFAULT NULL,
    `tokens_used` INT UNSIGNED NOT NULL DEFAULT 0,
    `latency_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('pending', 'running', 'completed', 'failed', 'skipped') NOT NULL DEFAULT 'completed',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_analysis_step` (`analysis_id`, `step_key`),
    KEY `idx_reports_analysis_stage` (`analysis_id`, `stage_group`, `id`),
    CONSTRAINT `fk_reports_analysis` FOREIGN KEY (`analysis_id`) REFERENCES `analyses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. analysis_queue: Background-friendly task queue for progressive AJAX & Cron
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `analysis_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `analysis_id` INT UNSIGNED NOT NULL,
    `step_order` SMALLINT UNSIGNED NOT NULL,
    `step_key` VARCHAR(64) NOT NULL,
    `step_label` VARCHAR(120) NOT NULL,
    `status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `error_text` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_queue_analysis_step` (`analysis_id`, `step_key`),
    KEY `idx_queue_status_order` (`status`, `analysis_id`, `step_order`),
    CONSTRAINT `fk_queue_analysis` FOREIGN KEY (`analysis_id`) REFERENCES `analyses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. trading_memory: Point-in-time decision memory log & post-trade reflections
--    Mirrors TradingAgents TradingMemoryLog & settlement.py in MySQL
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `trading_memory` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `analysis_id` INT UNSIGNED DEFAULT NULL,
    `ticker` VARCHAR(32) NOT NULL,
    `trade_date` DATE NOT NULL,
    `rating` ENUM('Buy', 'Overweight', 'Hold', 'Underweight', 'Sell', 'REVIEW') NOT NULL,
    `price_at_decision` DECIMAL(18, 6) DEFAULT NULL,
    `price_after_horizon` DECIMAL(18, 6) DEFAULT NULL,
    `benchmark_ticker` VARCHAR(24) NOT NULL DEFAULT 'SPY',
    `holding_period_days` SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    `raw_return_pct` DECIMAL(10, 4) DEFAULT NULL,
    `benchmark_return_pct` DECIMAL(10, 4) DEFAULT NULL,
    `alpha_return_pct` DECIMAL(10, 4) DEFAULT NULL,
    `outcome_status` ENUM('pending', 'settled', 'failed') NOT NULL DEFAULT 'pending',
    `settled_date` DATE DEFAULT NULL,
    `decision_summary` TEXT NOT NULL,
    `reflection_lesson` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_memory_ticker_date` (`ticker`, `trade_date` DESC),
    KEY `idx_memory_outcome` (`outcome_status`, `trade_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. portfolios: User paper/manual portfolios & cash tracking
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `portfolios` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `portfolio_name` VARCHAR(120) NOT NULL DEFAULT 'Primary Paper Portfolio',
    `base_currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `cash_balance` DECIMAL(18, 2) NOT NULL DEFAULT 100000.00,
    `initial_capital` DECIMAL(18, 2) NOT NULL DEFAULT 100000.00,
    `is_default` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_portfolios_user` (`user_id`, `is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. portfolio_positions: Open & closed holdings linked to AI analyses
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `portfolio_positions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portfolio_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `ticker` VARCHAR(32) NOT NULL,
    `asset_type` ENUM('stock', 'crypto', 'etf', 'forex', 'index') NOT NULL DEFAULT 'stock',
    `side` ENUM('LONG', 'SHORT') NOT NULL DEFAULT 'LONG',
    `quantity` DECIMAL(18, 6) NOT NULL DEFAULT 0.000000,
    `avg_entry_price` DECIMAL(18, 6) NOT NULL DEFAULT 0.000000,
    `current_price` DECIMAL(18, 6) DEFAULT NULL,
    `stop_loss` DECIMAL(18, 6) DEFAULT NULL,
    `take_profit` DECIMAL(18, 6) DEFAULT NULL,
    `source_analysis_id` INT UNSIGNED DEFAULT NULL,
    `notes` VARCHAR(500) DEFAULT NULL,
    `status` ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    `realized_pnl` DECIMAL(18, 2) NOT NULL DEFAULT 0.00,
    `opened_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `closed_at` DATETIME DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_positions_portfolio_status` (`portfolio_id`, `status`),
    KEY `idx_positions_user_ticker` (`user_id`, `ticker`),
    CONSTRAINT `fk_positions_portfolio` FOREIGN KEY (`portfolio_id`) REFERENCES `portfolios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. watchlists: User ticker watchlists with optional target alert thresholds
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `watchlists` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `ticker` VARCHAR(32) NOT NULL,
    `company_name` VARCHAR(191) DEFAULT NULL,
    `asset_type` ENUM('stock', 'crypto', 'etf', 'forex', 'index') NOT NULL DEFAULT 'stock',
    `alert_above` DECIMAL(18, 6) DEFAULT NULL,
    `alert_below` DECIMAL(18, 6) DEFAULT NULL,
    `auto_analyze_daily` TINYINT(1) NOT NULL DEFAULT 0,
    `last_price` DECIMAL(18, 6) DEFAULT NULL,
    `last_change_pct` DECIMAL(8, 3) DEFAULT NULL,
    `last_rating` VARCHAR(24) DEFAULT NULL,
    `last_analysis_id` INT UNSIGNED DEFAULT NULL,
    `notes` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_watchlist_user_ticker` (`user_id`, `ticker`),
    KEY `idx_watchlist_auto` (`auto_analyze_daily`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. market_cache: Aggressive shared-hosting cache for Yahoo, EDGAR, News, FRED
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `market_cache` (
    `cache_key` VARCHAR(191) NOT NULL,
    `ticker` VARCHAR(32) DEFAULT NULL,
    `data_category` VARCHAR(48) NOT NULL COMMENT 'ohlcv, snapshot, fundamentals, sec_edgar, news, stocktwits, reddit, polymarket, fred, indicators',
    `payload` LONGTEXT NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`cache_key`),
    KEY `idx_cache_expires` (`expires_at`),
    KEY `idx_cache_ticker_cat` (`ticker`, `data_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 12. cron_logs: Execution history & metrics for scheduled maintenance & queue tasks
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cron_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_name` VARCHAR(64) NOT NULL COMMENT 'queue_worker, cache_cleanup, log_pruning, memory_settlement, db_optimize, watchlist_alerts',
    `trigger_source` ENUM('cli', 'web_cron', 'admin_manual', 'ajax_fallback') NOT NULL DEFAULT 'cli',
    `status` ENUM('success', 'warning', 'error') NOT NULL DEFAULT 'success',
    `items_processed` INT UNSIGNED NOT NULL DEFAULT 0,
    `execution_time_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `summary_message` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cron_job_created` (`job_name`, `created_at` DESC),
    KEY `idx_cron_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 13. system_logs: Structured security, API, and application audit logs
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `level` ENUM('DEBUG', 'INFO', 'WARNING', 'ERROR', 'SECURITY') NOT NULL DEFAULT 'INFO',
    `channel` VARCHAR(48) NOT NULL DEFAULT 'app' COMMENT 'auth, llm, market_data, agent, cron, api_key, system',
    `user_id` INT UNSIGNED DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `message` VARCHAR(1000) NOT NULL,
    `context_json` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_syslog_level_created` (`level`, `created_at` DESC),
    KEY `idx_syslog_channel_created` (`channel`, `created_at` DESC),
    KEY `idx_syslog_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Default System Settings Seed
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `setting_group`, `is_public`) VALUES
('site_name', 'TradingAgents AI Platform', 'general', 1),
('site_tagline', 'Multi-Agent LLM Financial Trading Framework (PHP 8.4 Edition)', 'general', 1),
('default_theme', 'light', 'general', 1),
('allow_registration', '0', 'security', 0),
('QuickThinkProvider', 'openrouter', 'llm', 0),
('QuickThinkModel', 'openrouter/free', 'llm', 0),
('DeepThinkProvider', 'openrouter', 'llm', 0),
('DeepThinkModel', 'openrouter/free', 'llm', 0),
('openrouter_auto_fallback', '1', 'llm', 0),
('openrouter_free_fallback_chain', 'openrouter/free,google/gemma-4-31b:free,google/gemma-4-26b-a4b:free,nvidia/nemotron-3-ultra:free,nvidia/nemotron-3.5-super-vl:free,nvidia/nemotron-3.5-lightning:free,cohere/north-mini-code:free,liquid/lfm2.5-2.6b:free,thinkingmachines/inkling-2-small:free,recursive/flint:free', 'llm', 0),
('llm_temperature', '0.2', 'llm', 0),
('llm_max_tokens', '4096', 'llm', 0),
('llm_max_retries', '3', 'llm', 0),
('max_debate_rounds', '1', 'workflow', 1),
('max_risk_discuss_rounds', '1', 'workflow', 1),
('default_analysts', 'market,social,news,fundamentals', 'workflow', 1),
('output_language', 'English', 'workflow', 1),
('holding_period_days', '5', 'workflow', 1),
('benchmark_ticker', 'SPY', 'workflow', 1),
('cache_ttl_ohlcv', '900', 'performance', 0),
('cache_ttl_fundamentals', '43200', 'performance', 0),
('cache_ttl_news', '1800', 'performance', 0),
('cache_ttl_sec_edgar', '86400', 'performance', 0),
('log_retention_days', '30', 'maintenance', 0),
('cache_retention_hours', '48', 'maintenance', 0),
('cron_secret_token', 'ta_cron_change_me_on_install', 'maintenance', 0),
('sec_edgar_user_agent', 'TradingAgentsPHP/2.0 (admin@yourdomain.com)', 'vendors', 0),
('smtp_enabled', '0', 'notifications', 0),
('telegram_bot_token', '', 'notifications', 0);

SET FOREIGN_KEY_CHECKS = 1;
