-- One-time, 5-minute enrollment codes linking a machine to a thirdparty.
CREATE TABLE llx_systeminfo_enroll (
    rowid       integer AUTO_INCREMENT PRIMARY KEY,
    code        varchar(32) NOT NULL UNIQUE,
    fk_soc      integer NOT NULL,
    date_valid  datetime NOT NULL,
    used        tinyint DEFAULT 0,
    INDEX idx_systeminfo_enroll_code (code),
    INDEX idx_systeminfo_enroll_fk_soc (fk_soc)
) ENGINE=innodb;
