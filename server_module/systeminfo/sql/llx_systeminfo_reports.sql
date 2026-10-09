-- Table storing machine system info reports sent by the Windows agent.
CREATE TABLE llx_systeminfo_reports (
    rowid         integer AUTO_INCREMENT PRIMARY KEY,
    unique_id     varchar(128) NOT NULL UNIQUE,
    fk_soc        integer DEFAULT NULL,
    hostname      varchar(255) DEFAULT NULL,
    os            varchar(255) DEFAULT NULL,
    report        text,
    date_creation datetime DEFAULT NULL,
    tms           timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_systeminfo_reports_unique_id (unique_id),
    INDEX idx_systeminfo_reports_fk_soc (fk_soc)
) ENGINE=innodb;
