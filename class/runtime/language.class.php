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
 * \file        class/runtime/language.class.php
 * \ingroup     cyphtWebmail
 * \brief       Translates a Dolibarr language code into the one Cypht names
 *              its translation files with.
 */
class CyphtLanguage
{
	/**
	 * Codes where dropping the region gives the wrong answer. Dolibarr writes pt_BR, ....
	 *
	 * @var array<string,string>
	 */
	private static $exceptions = array(
		'pt_BR' => 'pt-BR',
		'zh_CN' => 'zh-Hans',
		'zh_TW' => 'zh-TW',
	);

	/**
	 * Dolibarr writes fr_FR, Cypht names its file fr.php. 
	 *
	 * Whether Cypht actually has the translation is checked on its own side,
	 * where the language directory is.
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
