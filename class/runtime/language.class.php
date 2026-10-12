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
 * \file        class/runtime/language.class.php
 * \ingroup     cyphtWebmail
 * \brief       Converts a Dolibarr language code to a Cypht one.
 */
class CyphtLanguage
{
	/**
	 * Codes that need the region kept.
	 *
	 * @var array<string,string>
	 */
	private static $exceptions = array(
		'pt_BR' => 'pt-BR',
		'zh_CN' => 'zh-Hans',
		'zh_TW' => 'zh-TW',
	);

	/**
	 * Convert e.g. fr_FR to fr. Cypht checks that the translation exists.
	 *
	 * @param string $dolibarrLang Code such as fr_FR, or 'auto'
	 * @return string Cypht language code, or '' when there is none
	 */
	public static function toCyphtCode($dolibarrLang)
	{
		$code = trim((string) $dolibarrLang);
		if ($code === '' || $code === 'auto') {
			return '';
		}

		if (array_key_exists($code, self::$exceptions)) {
			return self::$exceptions[$code];
		}

		$short = strtolower(substr($code, 0, 2));

		return preg_match('/^[a-z]{2}$/', $short) ? $short : '';
	}
}
