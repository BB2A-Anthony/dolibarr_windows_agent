-- Dictionary of software to track on agent machines.
CREATE TABLE llx_systeminfo_softwares (
    rowid    integer AUTO_INCREMENT PRIMARY KEY,
    ref      varchar(128) NOT NULL,
    label    varchar(255) NOT NULL,
    pattern  varchar(255) DEFAULT NULL,
    active   tinyint DEFAULT 1
) ENGINE=innodb;
