-- Table storing machine system info reports sent by the Windows agent.
-- One row per machine (upsert on guid). Each key metric is a column so it
-- can be filtered/queried directly in Dolibarr (lists, SQL, API).
CREATE TABLE llx_systeminfo_reports (
    rowid             integer AUTO_INCREMENT PRIMARY KEY,
    guid              varchar(128) NOT NULL UNIQUE,
    fk_soc            integer DEFAULT NULL,

    -- Identity / OS
    hostname          varchar(255) DEFAULT NULL,
    fqdn              varchar(255) DEFAULT NULL,
    os                varchar(255) DEFAULT NULL,
    os_version        varchar(64) DEFAULT NULL,
    os_build          varchar(64) DEFAULT NULL,
    architecture      varchar(32) DEFAULT NULL,
    processor         varchar(255) DEFAULT NULL,

    -- CPU
    cpu_logical       integer DEFAULT NULL,
    cpu_physical      integer DEFAULT NULL,
    cpu_percent        double DEFAULT NULL,

    -- Memory
    memory_total       bigint DEFAULT NULL,
    memory_available   bigint DEFAULT NULL,
    memory_percent     double DEFAULT NULL,

    -- Main disk (system partition)
    disk_total         bigint DEFAULT NULL,
    disk_used          bigint DEFAULT NULL,
    disk_free          bigint DEFAULT NULL,
    disk_percent       double DEFAULT NULL,

    -- Network
    ip_address        varchar(64) DEFAULT NULL,
    public_ip         varchar(64) DEFAULT NULL,
    isp               varchar(128) DEFAULT NULL,
    mac_address       varchar(32) DEFAULT NULL,

    -- Firewall
    firewall_enabled  tinyint DEFAULT NULL,

    -- Windows Update
    pending_updates   integer DEFAULT NULL,
    last_update_date  datetime DEFAULT NULL,

    -- Software check (dictionary match summary)
    softwares_missing integer DEFAULT NULL,

    -- Remote access
    teamviewer_installed tinyint DEFAULT NULL,
    teamviewer_id       varchar(32) DEFAULT NULL,

    -- Raw JSON payload and timestamps
    report            text,
    date_creation     datetime DEFAULT NULL,
    tms               timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_systeminfo_reports_fk_soc (fk_soc),
    INDEX idx_systeminfo_reports_hostname (hostname),
    INDEX idx_systeminfo_reports_public_ip (public_ip),
    INDEX idx_systeminfo_reports_isp (isp),
    INDEX idx_systeminfo_reports_pending_updates (pending_updates),
    INDEX idx_systeminfo_reports_firewall (firewall_enabled)
) ENGINE=innodb;
