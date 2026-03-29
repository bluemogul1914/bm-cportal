<?php
/**
 * Leads DB Bootstrap — included at top of every leads page.
 * Creates all required tables if they don't exist.
 */
function leads_bootstrap(PDO $pdo): void {
    // Companies table — must exist before leads (FK reference)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS companies (
            id         SERIAL PRIMARY KEY,
            name       VARCHAR(200) NOT NULL,
            website    VARCHAR(300),
            phone      VARCHAR(50),
            email      VARCHAR(200),
            industry   VARCHAR(100),
            city       VARCHAR(100),
            state_prov VARCHAR(100),
            address    VARCHAR(300),
            notes      TEXT,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS leads (
            id            SERIAL PRIMARY KEY,
            lead_number   VARCHAR(20),
            full_name     VARCHAR(200) NOT NULL,
            pipeline_status VARCHAR(50)  DEFAULT 'new_enquiry',
            owner         VARCHAR(100) DEFAULT 'Me',
            phone         VARCHAR(30),
            email         VARCHAR(200),
            source        VARCHAR(100),
            partner       VARCHAR(100),
            location      VARCHAR(200),
            city          VARCHAR(100),
            street        VARCHAR(200),
            zip_code      VARCHAR(20),
            geo_data      VARCHAR(200),
            custom_status VARCHAR(50)  DEFAULT 'customer',
            company_id    INT,
            company_name  VARCHAR(200),
            deal_value    DECIMAL(12,2) DEFAULT 0,
            notes         TEXT,
            last_contacted TIMESTAMP,
            last_comments TEXT,
            created_at    TIMESTAMP DEFAULT NOW(),
            updated_at    TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS lead_todos (
            id           SERIAL PRIMARY KEY,
            lead_id      INT,
            todo         TEXT NOT NULL,
            scheduled_at TIMESTAMP,
            completed    BOOLEAN DEFAULT FALSE,
            created_at   TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS lead_activities (
            id         SERIAL PRIMARY KEY,
            lead_id    INT,
            action     VARCHAR(1000),
            actor      VARCHAR(100) DEFAULT 'System',
            created_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS lead_quotes (
            id               SERIAL PRIMARY KEY,
            lead_id          INT,
            quote_number     VARCHAR(30),
            status           VARCHAR(30)   DEFAULT 'new',
            document_date    DATE          DEFAULT CURRENT_DATE,
            valid_until      DATE,
            deal_value       DECIMAL(12,2) DEFAULT 0,
            note             TEXT,
            memo             TEXT,
            items            TEXT          DEFAULT '[]',
            total_without_tax DECIMAL(12,2) DEFAULT 0,
            tax_amount       DECIMAL(12,2) DEFAULT 0,
            total            DECIMAL(12,2) DEFAULT 0,
            created_at       TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS lead_documents (
            id         SERIAL PRIMARY KEY,
            lead_id    INT,
            status     VARCHAR(30)  DEFAULT 'active',
            source     VARCHAR(100),
            title      VARCHAR(300),
            doc_date   DATE         DEFAULT CURRENT_DATE,
            description TEXT,
            filename   VARCHAR(300),
            created_by VARCHAR(100),
            created_at TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS lead_messages (
            id          SERIAL PRIMARY KEY,
            lead_id     INT,
            direction   VARCHAR(10)  DEFAULT 'outbound',
            subject     VARCHAR(300),
            body        TEXT,
            is_internal BOOLEAN      DEFAULT FALSE,
            sender      VARCHAR(100),
            created_at  TIMESTAMP DEFAULT NOW()
        );
        CREATE TABLE IF NOT EXISTS provider_settings (
            id         SERIAL PRIMARY KEY,
            provider   VARCHAR(50)  NOT NULL,
            key_name   VARCHAR(100) NOT NULL,
            key_value  TEXT         DEFAULT '',
            updated_at TIMESTAMP    DEFAULT NOW(),
            UNIQUE(provider, key_name)
        );
    ");

    // Migrate existing leads table — add company columns if missing
    foreach ([
        "ALTER TABLE leads ADD COLUMN IF NOT EXISTS company_id   INT",
        "ALTER TABLE leads ADD COLUMN IF NOT EXISTS company_name VARCHAR(200)",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) {}
    }
}

// Pipeline status helpers
function lead_status_badge(string $status): string {
    $map = [
        'new_enquiry'   => ['New enquiry',   'bg-yellow-100 text-yellow-800'],
        'qualification' => ['Qualification', 'bg-blue-100 text-blue-800'],
        'activation'    => ['Activation',    'bg-green-100 text-green-800'],
        'won'           => ['Won',           'bg-emerald-600 text-white'],
        'lost'          => ['Lost',          'bg-red-500 text-white'],
    ];
    $s   = $map[$status] ?? [$status, 'bg-gray-100 text-gray-600'];
    return "<span class=\"px-2 py-0.5 rounded text-xs font-semibold {$s[1]}\">{$s[0]}</span>";
}
function lead_status_label(string $status): string {
    $map = [
        'new_enquiry'   => 'New enquiry',
        'qualification' => 'Qualification',
        'activation'    => 'Activation',
        'won'           => 'Won',
        'lost'          => 'Lost',
    ];
    return $map[$status] ?? ucwords(str_replace('_',' ',$status));
}
function quote_status_badge(string $status): string {
    $map = [
        'new'       => ['New',       'bg-blue-500 text-white'],
        'sent'      => ['Sent',      'bg-indigo-500 text-white'],
        'on_review' => ['On review', 'bg-yellow-500 text-white'],
        'accepted'  => ['Accepted',  'bg-green-500 text-white'],
        'denied'    => ['Denied',    'bg-red-500 text-white'],
    ];
    $s = $map[$status] ?? [$status, 'bg-gray-400 text-white'];
    return "<span class=\"px-2 py-0.5 rounded text-xs font-semibold {$s[0]} {$s[1]}\" style=\"background-color:{$s[1][0]};\">{$s[0]}</span>";
}
function leads_smtp_settings(PDO $pdo): array {
    $cfg = ['host'=>'','port'=>'587','username'=>'','password'=>'','encryption'=>'tls','from_name'=>'','from_email'=>''];
    try {
        $st = $pdo->prepare("SELECT key_name,key_value FROM provider_settings WHERE provider='smtp'");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cfg[$r['key_name']] = $r['key_value'];
    } catch (Exception $e) {}
    return $cfg;
}
