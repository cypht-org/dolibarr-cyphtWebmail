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
-- Not llx_const: dolibarr_set_const() encrypts anything ending in _KEY,
-- _PASS or _SECRET and decrypts it for $conf->global. The webmail reads its
-- config over PDO before Dolibarr loads, so it would get ciphertext and every
-- signed bridge request would fail on signature.
--
-- Names are Cypht's own, so rows map straight into the environment.


CREATE TABLE llx_cyphtwebmail_config(
	rowid              integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity             integer DEFAULT 1 NOT NULL,
	name               varchar(64) NOT NULL,
	value              text,
	date_creation      datetime NOT NULL,
	tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
