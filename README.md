# TradingAgents AI Platform (PHP 8.4 + MySQL 8.x) — Installation & Architecture Guide

Welcome to the **Production-Ready Web-Based Multi-Agent AI Financial Trading Analysis Platform (PHP 8.4 + MySQL Edition)**, a complete pure-PHP re-engineering and expansion of the open-source [TauricResearch/TradingAgents](https://github.com/TauricResearch/TradingAgents) framework designed specifically for **Namecheap Linux Shared Hosting (cPanel)**, CloudLinux, VPS, and dedicated servers.

---

## 1. Complete File Structure (`TradingAgents-PHP-8.4-Production.zip`)

```text
tradingagents-php/
├── .htaccess                      # Apache/LiteSpeed security rules protecting config.php, schema.sql, /src, /storage
├── README.md                      # Deployment, cPanel Cron, and API Key Vault documentation
├── config.sample.php              # Sample configuration template (auto-generated as config.php by install.php)
├── schema.sql                     # Full MySQL 8.x / MariaDB 10.6+ InnoDB database schema (13 tables + seed data)
├── install.php                    # One-time interactive web installer with server diagnostics & AES-256-GCM key setup
├── index.php                      # Main responsive Web Dashboard & Admin Panel (Tailwind CSS + Chart.js)
├── api.php                        # JSON AJAX controller for progressive step execution, live quotes, API keys & cron
├── cron.php                       # CLI & Token-authenticated Web Cron entry point for cPanel Cron Jobs
├── report.php                     # Self-contained HTML, Print-to-PDF, and State JSON report exporter
├── login.php                      # Hardened login & optional registration page (Argon2id/Bcrypt + brute-force lockout)
├── logout.php                     # Secure session termination endpoint
├── src/
│   ├── Bootstrap.php              # Zero-Composer PSR-4 autoloader, timezone, error handling & session security
│   ├── Database.php               # PDO connection singleton with automatic reconnect after long LLM HTTP calls
│   ├── Security.php               # AES-256-GCM API key encryption/decryption, CSRF tokens, RBAC & ticker sanitization
│   ├── Logger.php                 # Audit & application logger with automatic API key/token scrubbing
│   ├── Cache.php                  # Two-tier cache (MySQL `market_cache` + `/storage/cache` files)
│   ├── ModelCatalog.php           # 44 Global AI & Data Providers + 469 OpenRouter Models (15 FREE + 454 PAID badges)
│   ├── ApiKeyManager.php          # Encrypted Multi-Key Vault (add, verify, test, enable/disable, reset, rotate, generate)
│   ├── LLMClient.php              # Multi-provider cURL client with OpenRouter Free-Model HTTP 429 auto-rotation
│   ├── TechnicalIndicators.php    # Pure PHP 8.4 math engine (10 EMA, 50/200 SMA, Wilder RSI, MACD, Bollinger, ATR, VWMA)
│   ├── MarketDataService.php      # Real Yahoo Finance v8/v10, SEC EDGAR XBRL, StockTwits, Reddit RSS, Polymarket & FRED
│   ├── AgentOrchestrator.php      # Multi-Agent State Graph & Progressive Step-by-Step Queue Engine (11 Agent Roles)
│   ├── CronManager.php            # Automated background queue worker, memory settlement, cache purge & DB optimizer
│   └── ReportExporter.php         # Faithful port of `report_html.py` with inline SVG logo, TOC rail & print-to-PDF CSS
└── storage/                       # Created automatically by install.php (protected by .htaccess)
    ├── cache/                     # Fast filesystem market cache
    ├── logs/                      # Application error logs
    └── installed.lock             # Installer lock file
```

---

## 2. Step-by-Step Installation on Namecheap Shared Hosting (cPanel)

### Step 1: Select PHP 8.4 in cPanel
1. Log in to your **Namecheap cPanel** and open **Select PHP Version** (under the *Software* section).
2. Set the **Current PHP version** to **8.4** (also fully compatible with PHP 8.2 and 8.3).
3. Ensure these standard extensions are checked: `pdo`, `pdo_mysql`, `curl`, `openssl`, `mbstring`, `json`, and `simplexml`.

### Step 2: Create a MySQL Database & User
1. In cPanel, open **MySQL® Database Wizard** (or **MySQL® Databases**).
2. Create a new database (e.g., `cpaneluser_tradingagents`).
3. Create a database user (e.g., `cpaneluser_tauser`) with a strong password.
4. Assign the user to the database and check **ALL PRIVILEGES**.

### Step 3: Upload & Extract the Package
1. Open **cPanel &rarr; File Manager** and navigate to `public_html/` (or a subdomain folder such as `trading.yourdomain.com/`).
2. Click **Upload** and select `TradingAgents-PHP-8.4-Production.zip`.
3. Right-click `TradingAgents-PHP-8.4-Production.zip` &rarr; **Extract**, and move the files from `tradingagents-php/` into your target web root if needed.

### Step 4: Run the Web Installer (`install.php`)
1. Open your browser and visit `https://yourdomain.com/install.php`.
2. Verify that all **8 Server Compatibility Checks** show green **PASS** badges.
3. Enter your **MySQL Database** credentials, choose your **Administrator Username, Email, and Password**, and optionally paste your free [OpenRouter API Key](https://openrouter.ai/keys).
4. Click **Install TradingAgents Platform & Initialize Database**.
   - The installer automatically executes `schema.sql`, generates a unique 256-bit **AES-256-GCM Master Key**, writes `config.php`, creates your default **$100,000 Paper Portfolio**, and locks itself via `storage/installed.lock`.
5. For maximum security after installation, delete or rename `install.php` in cPanel File Manager.

---

## 3. Configuring cPanel Cron Jobs (Background Queue & Maintenance)

To keep the database fast, automatically settle matured trading decisions, purge expired cache files, and advance background multi-agent runs:

1. In cPanel, navigate to **Advanced &rarr; Cron Jobs**.
2. Under **Common Settings**, choose **Once Per Five Minutes (`*/5 * * * *`)**.
3. Copy the exact **Master Cron Suite CLI Command** displayed in your dashboard's **Cron Jobs** tab:
   ```bash
   /usr/local/bin/php /home/YOUR_CPANEL_USER/public_html/cron.php --job=all >/dev/null 2>&1
   ```
4. Click **Add New Cron Job**.

---

## 4. Configuring OpenRouter Free Models & Multi-Provider API Keys

1. Sign in to the dashboard (`login.php`) and click the **AI API Keys & Models** tab.
2. Click **Get API Key** next to **OpenRouter** ([openrouter.ai/keys](https://openrouter.ai/keys)) or any of the **44 supported global AI & Market Data providers** ([OpenAI](https://platform.openai.com/api-keys), [Google AI Studio](https://aistudio.google.com/app/apikey), [Anthropic](https://console.anthropic.com/settings/keys), [DeepSeek](https://platform.deepseek.com/api_keys), [Groq](https://console.groq.com/keys), [Mistral](https://console.mistral.ai/api-keys/), [xAI](https://console.x.ai), [NVIDIA NIM](https://build.nvidia.com), [Alpha Vantage](https://www.alphavantage.co/support/#api-key), [FRED](https://fred.stlouisfed.org/docs/api/api_key.html)).
3. Add one or more keys to the **Encrypted API Key Vault**. Every key is encrypted at rest using **AES-256-GCM** and verified live against the provider's endpoint.
4. When using OpenRouter free models (`openrouter/free`, `google/gemma-4-31b:free`, `nvidia/nemotron-3-ultra:free`, etc.), if any model or key hits an HTTP 429 rate limit, `LLMClient` automatically rotates across your keys and the 15 free models without interrupting the multi-agent workflow.

5. All software, scripts, source code, and PHP plugins available for free download on this website are provided as concepts, mockups, or development resources to help developers build and improve their own solutions. Where licensed under the GNU General Public License (GPL), these resources may be used, modified, and redistributed in accordance with the applicable license terms. They are provided “as is” and “as available,” without any warranty of functionality, accuracy, security, or suitability for a particular purpose, and may require further development and testing before use. You download and use these resources at your own risk and are responsible for testing, securing, and adapting them to your requirements. We do not encourage or endorse unlawful use. To the fullest extent permitted by applicable law, we disclaim liability for errors, vulnerabilities, data loss, damages, or other consequences arising from their download, modification, or use.We are not responsible for any illegal activity carried out using our software.
