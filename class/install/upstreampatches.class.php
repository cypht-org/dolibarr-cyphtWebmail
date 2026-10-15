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

require_once __DIR__ . '/../install/paths.class.php';

/**
 * \file        class/install/upstreampatches.class.php
 * \ingroup     cyphtWebmail
 * \brief       Fixes applied to the Cypht sources at build time.
 */
class CyphtUpstreamPatches
{
	/**
	 * @var string  Last error message
	 */
	public $error = '';

	/**
	 * @var CyphtPaths
	 */
	private $paths;

	/**
	 * @param CyphtPaths $paths
	 */
	public function __construct(CyphtPaths $paths)
	{
		$this->paths = $paths;
	}

	/**
	 * Add the missing hm_exists() guards in modules/core/functions.php.
	 *
	 * @return bool
	 */
	public function patchCoreFunctionsGuard()
	{
		return $this->patchFile('/modules/core/functions.php', function ($content) {
			return $this->wrapUnguardedTopLevelFunctions($content);
		});
	}

	/**
	 * Fix HTML messages in modules/smtp/hm-mime-message.php: add the blank
	 * line after the headers and the closing boundary. SMTP servers reject
	 * the message without them.
	 *
	 * @return bool
	 */
	public function patchMimeMessage()
	{
		return $this->patchFile('/modules/smtp/hm-mime-message.php', function ($content) {
			$content = str_replace('$res .= $this->text_body;', '$res .= "\r\n".$this->text_body;', $content);

			$pattern = '/(' . preg_quote('format=flowed\r\n".', '/') . '\s*'
				. preg_quote('"Content-Transfer-Encoding: quoted-printable\r\n\r\n%s', '/') . ')",(\s*)'
				. preg_quote('$this->boundary, $this->format_message_text($body));', '/') . '/';

			return preg_replace_callback($pattern, function ($m) {
				return $m[1] . '\r\n--%s--\r\n",' . $m[2] . '$this->boundary, $this->format_message_text($body), $this->boundary);';
			}, $content);
		});
	}

	/**
	 * Rewrite a Cypht file with $patch, if it changes anything.
	 *
	 * @param string $file Path inside the Cypht package
	 * @param callable $patch Takes and returns the file content
	 * @return bool
	 */
	private function patchFile($file, $patch)
	{
		$path = $this->paths->getCyphtPath() . $file;
		if (!is_readable($path)) {
			return true;
		}

		$content = file_get_contents($path);
		if ($content === false) {
			$this->error = 'Could not read ' . $path;
			return false;
		}

		$patched = $patch($content);
		if ($patched === null) {
			$this->error = 'Could not patch ' . $path;
			return false;
		}
		if ($patched === $content) {
			return true;
		}

		if (file_put_contents($path, $patched) === false) {
			$this->error = 'Could not write ' . $path;
			return false;
		}

		return true;
	}

	/**
	 * Wrap each unguarded top-level function in
	 * "if (!hm_exists('name')) { ... }". Skips closures and class methods.
	 * Uses the tokenizer so braces in strings and comments are ignored.
	 *
	 * @param string $content
	 * @return string Patched content (identical to input if nothing to do)
	 */
	private function wrapUnguardedTopLevelFunctions($content)
	{
		$tokens = token_get_all($content);
		$count = count($tokens);
		$result = '';
		$classDepth = null; // brace depth where the current class starts, null outside a class
		$braceDepth = 0;
		$i = 0;

		while ($i < $count) {
			$token = $tokens[$i];
			$text = is_array($token) ? $token[1] : $token;

			if ($text === '{') {
				$braceDepth++;
			} elseif ($text === '}') {
				$braceDepth--;
				if ($classDepth !== null && $braceDepth === $classDepth) {
					$classDepth = null;
				}
			}

			if (is_array($token) && $token[0] === T_CLASS) {
				$classDepth = $braceDepth; // depth before the class's own "{"
			}

			if (is_array($token) && $token[0] === T_FUNCTION && $classDepth === null) {
				$j = $i + 1;
				while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
					$j++;
				}
				$name = (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) ? $tokens[$j][1] : null;

				if ($name !== null) {
					// Find the function's closing brace.
					$k = $j;
					$depth = 0;
					$started = false;
					while ($k < $count) {
						$t = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
						if ($t === '{') {
							$depth++;
							$started = true;
						} elseif ($t === '}') {
							$depth--;
						}
						$k++;
						if ($started && $depth === 0) {
							break;
						}
					}

					$funcSource = '';
					for ($m = $i; $m < $k; $m++) {
						$funcSource .= is_array($tokens[$m]) ? $tokens[$m][1] : $tokens[$m];
					}

					$alreadyGuarded = (strpos(substr($result, -200), "hm_exists('{$name}')") !== false);
					$result .= $alreadyGuarded
						? $funcSource
						: "if (!hm_exists('{$name}')) {\n" . $funcSource . "}\n";

					// Account for the braces inside the function.
					$braceDepth += (substr_count($funcSource, '{') - substr_count($funcSource, '}'));
					$i = $k;
					continue;
				}
			}

			$result .= $text;
			$i++;
		}

		return $result;
	}
}
