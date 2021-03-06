<?php
namespace de\RaumZeitLabor\PartKeepr;

use Doctrine\Common\ClassLoader,
	de\RaumZeitLabor\PartKeepr\SystemNotice\SystemNoticeManager,
    Doctrine\ORM\Configuration,
    Doctrine\ORM\EntityManager,
    de\RaumZeitLabor\PartKeepr\Util\Configuration as PartKeeprConfiguration;



class PartKeepr {
	/**
	 * 
	 * Contains the doctrine entity manager.
	 * @var Doctrine\ORM\EntityManager
	 */
	private static $entityManager = null;
	
	/**
	 * Initializes the PartKeepr system
	 * 
	 * You *need* to call this method before doing anything else.
	 * 
	 * An environment is used to load a different configuration file.
	 * Usually, you don't need to pass anything here.
	 * 
	 * @param $environment	string	The environment to use, null otherwise.
	 * @return nothing
	 */
	public static function initialize ($environment = null) {
		self::initializeClassLoaders();
		self::initializeConfig($environment);
		self::initializeDoctrine();
	}
	
	/**
	 * Initializes the doctrine class loader and sets up the
	 * directories.
	 * 
	 * @param none
	 * @return nothing
	 */
	public static function initializeClassLoaders() {
		require_once 'Doctrine/Common/ClassLoader.php';
		
		
		$classLoader = new ClassLoader('de\RaumZeitLabor\PartKeepr', dirname(dirname(dirname(__DIR__))));
		$classLoader->register();
		
		$classLoader = new ClassLoader('Doctrine\ORM');
		$classLoader->register();
		
		$classLoader = new ClassLoader("Doctrine\DBAL\Migrations", dirname(dirname(dirname(dirname(dirname(__DIR__))))) ."/3rdparty/doctrine-migrations/lib");
		$classLoader->register();
		
		$classLoader = new ClassLoader('Doctrine\DBAL');
		$classLoader->register();
		
		$classLoader = new ClassLoader('Doctrine\Common');
		$classLoader->register();
		
		$classLoader = new ClassLoader('Symfony', 'Doctrine');
		$classLoader->register();
		
		$classLoader = new ClassLoader("DoctrineExtensions\NestedSet", dirname(dirname(dirname(dirname(dirname(__DIR__))))) ."/3rdparty/doctrine2-nestedset/lib");
		$classLoader->register();
		
		
	}
	
	/**
	 * Returns an array of all cronjobs which are required for proper execution of PartKeepr.
	 * 
	 * @return Array The filenames of each cronjob which is required
	 */
	public static function getRequiredCronjobs () {
		return array(
				"CreateStatisticSnapshot.php",
				"UpdatePartCacheData.php",
				"UpdateTipsOfTheDay.php",
				"CheckForUpdates.php"
				);
	}

	/**
	 * Initializes the configuration for a given environment.
	 * 
	 * An environment is used to load a different configuration file.
	 * 
	 * Usually, you don't need to pass anything here.
	 * 
	 * 
	 * @param $environment	string	The environment to use, null otherwise.
	 * @return nothing
	 */
	public static function initializeConfig ($environment = null) {
		if ($environment != null) {
			include(dirname(dirname(dirname(dirname(dirname(__DIR__)))))."/config-$environment.php");
		} else {
			include(dirname(dirname(dirname(dirname(dirname(__DIR__)))))."/config.php");
		}
		
	}

	/**
	 * Checks against the versions at partkeepr.org.
	 * 
	 * If a newer version was found, create a system notice entry.
	 */
	public static function doVersionCheck () {
		
		$data = file_get_contents("http://www.partkeepr.org/versions.json");
		$versions = json_decode($data, true);
		
		if (PartKeeprVersion::PARTKEEPR_VERSION == "{V_GIT}") { return; }
		
		if (version_compare(PartKeepr::getVersion(), $versions[0]["version"], '<')) {
			
			SystemNoticeManager::getInstance()->createUniqueSystemNotice(
					"PARTKEEPR_VERSION_".$versions[0]["version"],
					sprintf(PartKeepr::i18n("New PartKeepr Version %s available"), $versions[0]["version"]),
					sprintf(PartKeepr::i18n("PartKeepr Version %s changelog:"), $versions[0]["version"]) . "\n\n".
					$versions[0]["changelog"]
					);
			
		}
	}
	
	/**
	 * Initializes the doctrine framework and
	 * sets all required configuration options.
	 * 
	 * @param none
	 * @return nothing
	 */
	public static function initializeDoctrine () {
		$config = new Configuration;
		
		$driverImpl = $config->newDefaultAnnotationDriver(
			array(__DIR__)
			);
		$config->setMetadataDriverImpl($driverImpl);
		
		$connectionOptions = PartKeepr::createConnectionOptionsFromConfig();
		
		if (extension_loaded("apc")) {
			$cache = new \Doctrine\Common\Cache\ApcCache();
		} else {
			$cache = new \Doctrine\Common\Cache\ArrayCache();
		}
		
		$config->setMetadataCacheImpl($cache);

		$config->setQueryCacheImpl($cache);
		
		$config->setProxyDir(dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/data/proxies');
		$config->setProxyNamespace('Proxies');
		$config->setEntityNamespaces(self::getEntityClasses());
		$config->setAutoGenerateProxyClasses(false);
		
		if (PartKeeprConfiguration::getOption("partkeepr.database.echo_sql_log", false) === true) {
			$logger = new \Doctrine\DBAL\Logging\EchoSQLLogger();
			$config->setSQLLogger($logger);
		}
		
		self::$entityManager = EntityManager::create($connectionOptions, $config);
	}
	
	public static function createConnectionOptionsFromConfig () {
		$connectionOptions = array();
		
		$driver = PartKeeprConfiguration::getOption("partkeepr.database.driver");
		
		switch ($driver) {
			case "pdo_mysql":
			case "pdo_pgsql":
			case "pdo_oci":
			case "oci8":
			case "pdo_sqlsrv":
				$connectionOptions["driver"] 	= $driver;
				$connectionOptions["dbname"] 	= PartKeeprConfiguration::getOption("partkeepr.database.dbname", "partkeepr");
				$connectionOptions["user"]   	= PartKeeprConfiguration::getOption("partkeepr.database.username", "partkeepr");
				$connectionOptions["password"] 	= PartKeeprConfiguration::getOption("partkeepr.database.password", "partkeepr");
				
				/**
				 * Compatibility with older configuration files. We check for the key "hostname" as well as "host".
				 */
				if (PartKeeprConfiguration::getOption("partkeepr.database.hostname", null) !== null) {
					$connectionOptions["host"] 	= PartKeeprConfiguration::getOption("partkeepr.database.hostname");
				} else {
					$connectionOptions["host"] 	= PartKeeprConfiguration::getOption("partkeepr.database.host", "localhost");
				}
				
				
				if (PartKeeprConfiguration::getOption("partkeepr.database.port") !== null) {
					$connectionOptions["port"] = PartKeeprConfiguration::getOption("partkeepr.database.port");
				}
				
				if (PartKeeprConfiguration::getOption("partkeepr.database.mysql_socket", null) !== null) {
					$connectionOptions["unix_socket"] = PartKeeprConfiguration::getOption("partkeepr.database.mysql_socket");
				}
				break;
			case "pdo_sqlite":
				$connectionOptions["user"]   	= PartKeeprConfiguration::getOption("partkeepr.database.username", "partkeepr");
				$connectionOptions["password"] 	= PartKeeprConfiguration::getOption("partkeepr.database.password", "partkeepr");
				$connectionOptions["path"] 		= PartKeeprConfiguration::getOption("partkeepr.database.sqlite_path", PartKeepr::getRootDirectory() . "/data/partkeepr.sqlite");
				break;
			default:
				throw new \Exception(sprintf("Unknown driver %s", $driver));
		}
		
		return $connectionOptions;
	}
	
	/**
	 * Returns the EntityManager. Shortcut for getEntityManager().
	 * @return Doctrine\ORM\EntityManager The EntityManager
	 */
	public static function getEM () {
		return self::getEntityManager();
	}

	public static function getRootDirectory () {
		return dirname(dirname(dirname(dirname(dirname(__DIR__)))));
	}
	
	/**
	 * Returns the EntityManager.
	 * @return Doctrine\ORM\EntityManager The EntityManager
	 */
	public static function getEntityManager () {
		if (!self::$entityManager instanceof EntityManager) {
			throw new Exception("No EntityManager found. Make sure you called initializeDoctrine() or initialize().");
		}
		return self::$entityManager;
	}
	
	/**
	 * Returns the class metadata for all entity classes
	 * @return array an array of class metadata objects
	 */
	public static function getClassMetaData () {
		$classes = self::getEntityClasses();

		$aClasses = array();
		
		foreach ($classes as $class) {
			$aClasses[] = PartKeepr::getEM()->getClassMetadata($class);
		}
		
		return $aClasses;
	}
	
	/**
	 * Returns a list of all classes we use for entities.
	 * @return array An array of strings with all class names
	 */
	public static function getEntityClasses () {
		return array(
			'de\RaumZeitLabor\PartKeepr\User\User',
			'de\RaumZeitLabor\PartKeepr\Session\Session',
		
			'de\RaumZeitLabor\PartKeepr\Footprint\Footprint',
			'de\RaumZeitLabor\PartKeepr\Footprint\FootprintImage',
			'de\RaumZeitLabor\PartKeepr\Footprint\FootprintAttachment',
			'de\RaumZeitLabor\PartKeepr\FootprintCategory\FootprintCategory',
		
			'de\RaumZeitLabor\PartKeepr\Part\Part',
			'de\RaumZeitLabor\PartKeepr\Part\PartUnit',
			'de\RaumZeitLabor\PartKeepr\Part\PartManufacturer',
			'de\RaumZeitLabor\PartKeepr\Part\PartDistributor',
			'de\RaumZeitLabor\PartKeepr\Part\PartImage',
			'de\RaumZeitLabor\PartKeepr\Part\PartAttachment',
			'de\RaumZeitLabor\PartKeepr\PartCategory\PartCategory',
		
			'de\RaumZeitLabor\PartKeepr\Project\Project',
			'de\RaumZeitLabor\PartKeepr\Project\ProjectPart',
			'de\RaumZeitLabor\PartKeepr\Project\ProjectAttachment',
				
			'de\RaumZeitLabor\PartKeepr\StorageLocation\StorageLocation',
			'de\RaumZeitLabor\PartKeepr\StorageLocation\StorageLocationImage',
		
			'de\RaumZeitLabor\PartKeepr\Stock\StockEntry',
		
			'de\RaumZeitLabor\PartKeepr\Manufacturer\Manufacturer',
			'de\RaumZeitLabor\PartKeepr\Manufacturer\ManufacturerICLogo',
			
			'de\RaumZeitLabor\PartKeepr\Distributor\Distributor',
			
			'de\RaumZeitLabor\PartKeepr\Image\Image',
			'de\RaumZeitLabor\PartKeepr\Image\CachedImage',
			'de\RaumZeitLabor\PartKeepr\TempImage\TempImage',
			
			'de\RaumZeitLabor\PartKeepr\UploadedFile\TempUploadedFile',
			
			'de\RaumZeitLabor\PartKeepr\Statistic\StatisticSnapshot',
			'de\RaumZeitLabor\PartKeepr\Statistic\StatisticSnapshotUnit',
			'de\RaumZeitLabor\PartKeepr\SiPrefix\SiPrefix',
			'de\RaumZeitLabor\PartKeepr\Unit\Unit',
			'de\RaumZeitLabor\PartKeepr\PartParameter\PartParameter',
			
			'de\RaumZeitLabor\PartKeepr\TipOfTheDay\TipOfTheDay',
			'de\RaumZeitLabor\PartKeepr\TipOfTheDay\TipOfTheDayHistory',
			'de\RaumZeitLabor\PartKeepr\UserPreference\UserPreference',
			'de\RaumZeitLabor\PartKeepr\SystemNotice\SystemNotice',
			'de\RaumZeitLabor\PartKeepr\CronLogger\CronLogger'
			
		);
	}
	
	/**
	 *@todo stub
	 */
	public static function i18n ($string) {
		return $string;
	}
	
	/**
	 * Returns a new GUID.
	 * @return string The new GUID
	 */
	public static function createGUIDv4() {
	    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
	
	      // 32 bits for "time_low"
	      mt_rand(0, 0xffff), mt_rand(0, 0xffff),
	
	      // 16 bits for "time_mid"
	      mt_rand(0, 0xffff),
	
	      // 16 bits for "time_hi_and_version",
	      // four most significant bits holds version number 4
	      mt_rand(0, 0x0fff) | 0x4000,
	
	      // 16 bits, 8 bits for "clk_seq_hi_res",
	      // 8 bits for "clk_seq_low",
	      // two most significant bits holds zero and one for variant DCE1.1
	      mt_rand(0, 0x3fff) | 0x8000,
	
	      // 48 bits for "node"
	      mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
	    );
	  }
	  
	/**
	 * Returns the current PartKeepr version.
	 * @return string The PartKeepr Version
	 */
	public static function getVersion () {
	 	if (PartKeeprVersion::PARTKEEPR_VERSION == "{V_GIT}") {
	  		return "GIT development version";
	  	}
	  	return PartKeeprVersion::PARTKEEPR_VERSION;
	}
	
	/**
	 * This is a re-implementation of gettype().
	 * 
	 * The PHP documentation states that the "gettype" return values will change in the future, so we need
	 * to make sure we don't get bitten by the change.
	 *  
	 * @param mixed $var
	 * @return string The type
	 */
	public static function getType($var)
	{
		if (is_array($var)) return "array";
		if (is_bool($var)) return "boolean";
		if (is_float($var)) return "float";
		if (is_int($var)) return "integer";
		if (is_null($var)) return "NULL";
		if (is_numeric($var)) return "numeric";
		if (is_object($var)) return "object";
		if (is_resource($var)) return "resource";
		if (is_string($var)) return "string";
		return "unknown type";
	}
}
