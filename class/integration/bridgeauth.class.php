<?php
/* Copyright (C) 2026  Camile   <camilevahviraki@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation; either version 2.1 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        class/integration/bridgeauth.class.php
 * \ingroup     cyphtWebmail
 * \brief       Token check for server-to-server calls to bridge/ endpoints.
 */
class CyphtBridgeAuth
{
	/** Max token age in seconds, same as Custom_Auth::check_credentials(). */
	const WINDOW = 60;

	/**
	 * Verify a signed token and return the user it names.
	 *
	 * @param DoliDB $db
	 * @param string $login
	 * @param string $token   '{timestamp}.{hmac}'
	 * @param string $purpose Endpoint tag, e.g. 'context'
	 * @param int    $status  HTTP status to answer with on failure
	 * @param string $error   Reason, on failure
	 * @return User|null
	 */
	public static function userFromToken($db, $login, $token, $purpose, &$status = 403, &$error = '')
	{
		require_once __DIR__ . '/../install/config.class.php';

		if ($login === '') {
			$status = 400;
			$error = 'Missing login';
			return null;
		}

		$secret = CyphtConfig::get($db, 'SSO_SHARED_SECRET', '');
		if ($secret === '') {
			$status = 503;
			$error = 'SSO secret not initialised, run the module build first';
			return null;
		}

		if (strpos($token, '.') === false) {
			$error = 'Malformed token';
			return null;
		}

		list($timestamp, $signature) = explode('.', $token, 2);
		if (!ctype_digit($timestamp)) {
			$error = 'Malformed token';
			return null;
		}

		if (abs(time() - (int) $timestamp) > self::WINDOW) {
			$error = 'Token expired';
			return null;
		}

		$expected = hash_hmac('sha256', $login . '|' . $timestamp . '|' . $purpose, $secret);
		if (!hash_equals($expected, $signature)) {
			$error = 'Bad signature';
			return null;
		}

		$user = new User($db);
		if ($user->fetch(0, $login) <= 0) {
			$error = 'Unknown user';
			return null;
		}
		if (isset($user->statut) && $user->statut == 0) {
			$error = 'Disabled user';
			return null;
		}

		return $user;
	}
}
