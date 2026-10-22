<?php

	/**
	 * RuntimePreflight.class.php
	 *
	 * Checks the PHP runtime and extensions before the installer touches a database.
	 */

	class Kloudspeaker_RuntimePreflight {
		const MINIMUM_PHP_VERSION_ID = 80200;
		const MINIMUM_PHP_VERSION = "8.2";

		private static $databaseExtensions = array(
			"mysql" => array("mysqli", "MySQL Improved (mysqli)"),
			"pdo" => array("pdo", "PDO"),
			"sqlite3" => array("sqlite3", "SQLite3"),
			"postgresql" => array("pgsql", "PostgreSQL")
		);

		/**
		 * Returns a list of problems (each array("title" => ..., "details" => ...)), empty when the runtime is usable.
		 * The loaded-extension lookup and PHP version are injectable for tests.
		 */
		public static function check($databaseType, $versionId = NULL, $extensionLoaded = NULL) {
			$versionId = ($versionId === NULL) ? PHP_VERSION_ID : $versionId;
			$loaded = ($extensionLoaded === NULL) ? 'extension_loaded' : $extensionLoaded;
			$problems = array();

			if ($versionId < self::MINIMUM_PHP_VERSION_ID) {
				$problems[] = array("title" => "Unsupported PHP version", "details" => "Kloudspeaker requires PHP " . self::MINIMUM_PHP_VERSION . " or newer.");
			}

			// default charset conversion needs mbstring; json is required for all service responses
			foreach (array("mbstring" => "Multibyte String (mbstring)", "json" => "JSON") as $ext => $name) {
				if (!call_user_func($loaded, $ext)) {
					$problems[] = array("title" => $name . " extension not detected", "details" => "The PHP " . $ext . " extension is required. Note that the web server and command line PHP can use different configurations.");
				}
			}

			$type = strtolower((string) $databaseType);
			if (isset(self::$databaseExtensions[$type])) {
				$ext = self::$databaseExtensions[$type];
				if (!call_user_func($loaded, $ext[0])) {
					$problems[] = array("title" => $ext[1] . " extension not detected", "details" => "The configured database type (" . $type . ") requires the PHP " . $ext[0] . " extension.");
				}
			}

			return $problems;
		}

		/** Shows the installer error page for the first problem, if any. */
		public static function assertUsable($processor, $databaseType, $problems = NULL) {
			if ($problems === NULL) {
				$problems = self::check($databaseType);
			}
			if (count($problems) == 0) {
				return;
			}

			$processor->setError($problems[0]["title"], $problems[0]["details"]);
			$processor->showPage("install_error");
		}
	}
?>
