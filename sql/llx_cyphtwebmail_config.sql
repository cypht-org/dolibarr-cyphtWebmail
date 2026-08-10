-- Copyright (C) 2026  Camile   <camilevahviraki@gmail.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.
--
-- Installation scoped configuration for the webmail, one row per key.
--
-- Deliberately not llx_const. dolibarr_set_const() inspects the constant
-- name and silently encrypts the value of anything ending in _KEY, _PASS,
-- _SECRET and a few others (admin.lib.php, the "sensitive constant" branch).
-- Dolibarr decrypts them again when it builds $conf->global, so its own code
-- never notices. The webmail, however, reads this configuration before
-- Dolibarr is loaded, over plain PDO, and would get ciphertext it has no way
-- to unwrap: the shared secret would differ at each end and every signed
-- bridge request would fail with a bad signature.
--
-- Names are the ones Cypht itself uses, so the runtime bootstrap can map a
-- row straight into the environment with no translation table.


CREATE TABLE llx_cyphtwebmail_config(
	rowid              integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity             integer DEFAULT 1 NOT NULL,
	name               varchar(64) NOT NULL,
	value              text,
	date_creation      datetime NOT NULL,
	tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
