<?php
/* Copyright (C) 2026  Camile   <camilevahviraki@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        version.inc.php
 * \ingroup     cyphtwebmail
 * \brief       The module version. The only place it is written.
 *
 *              Same shape as Dolibarr's own htdocs/version.inc.php, which
 *              exists for exactly this and is what its packager reads.
 *
 *              The module descriptor, the trigger, build.json and the release
 *              archive name all resolve to this through
 *              CyphtPaths::readVersionFrom()
 *
 *              Releasing is: edit the number here, commit, tag to match.
 */

if (!defined('CYPHTWEBMAIL_VERSION')) {
	define('CYPHTWEBMAIL_VERSION', '1.1.0');
}
