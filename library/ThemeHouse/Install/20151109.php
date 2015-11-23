<?php

class ThemeHouse_Install
{

	/**
	 *
	 * @var Zend_Db_Adapter_Abstract
	 */
	protected $_db;

	protected static $_tablesList;

	protected $_minVersionId = 1000000;

	protected $_minVersionString = '1.0.0';

	protected $_resourceManagerUrl = '';

	protected $_disableListenersBeforeUninstall = false;

	protected $_data = array();

	protected $_xml = null;

	protected $_targetRunTime = 0;

	/**
	 * Standard approach to caching other model objects for the lifetime of the
	 * model.
	 *
	 * @var array
	 */
	protected $_modelCache = array();

	protected static $_noUninstall = false;

	/**
	 * Gets the specified model object from the cache.
	 * If it does not exist,
	 * it will be instantiated.
	 *
	 * @param string $class Name of the class to load
	 *
	 * @return XenForo_Model
	 */
	public function getModelFromCache($class)
	{
		if (!isset($this->_modelCache[$class])) {
			$this->_modelCache[$class] = XenForo_Model::create($class);
		}

		return $this->_modelCache[$class];
	}

	/**
	 * Gets the autoloader's root directory.
	 *
	 * @return string
	 */
	public function getRootDir()
	{
		return XenForo_Autoloader::getInstance()->getRootDir();
	}

	/**
	 * At some point this will be made final, but old add-ons still extend it at
	 * present.
	 */
	public static function install()
	{
		$args = func_get_args();
		if (isset($args[1])) {
			$data = $args[1];
		} elseif (isset($args[0])) {
			$data = $args[0];
		} else {
			return;
		}
		$xml = null;
		if (isset($args[2])) {
			$xml = $args[2];
		}

		$xenAddons = XenForo_Application::get('addOns');
		$wainAddon = str_replace('ThemeHouse_', 'Waindigo_', $data['addon_id']);

		$addOnKey = strtolower(str_replace('_','',$data['addon_id']));

		if ($data['addon_id'] != 'ThemeHouse_InstallUpgrade' && isset($xenAddons[$wainAddon]) && !isset($GLOBALS['th_upgrade'][$addOnKey])) {
			$addOn = XenForo_Model::create('XenForo_Model_AddOn')->getAddOnById($wainAddon);
			throw new XenForo_Exception('This add-on cannot be installed as you have '.$addOn['title'].' installed. Please either uninstall it first, or use [⌂] Install and Upgrade to upgrade to the new ThemeHouse version.', true);
		}

		$installer = self::_getInstaller($data['addon_id']);
		if ($installer) {
			$installer->_data = $data;
			$installer->_xml = $xml;
			$installer->_install($data, $xml);
		}
	}

	/**
	 *	Upgrade from ThemeHouse Add-on
	 */
	public static function upgradeFromThemeHouse()
	{
		$args = func_get_args();
		if (isset($args[1])) {
			$data = $args[1];
		} elseif (isset($args[0])) {
			$data = $args[0];
		} else {
			return;
		}
		$xml = null;
		if (isset($args[2])) {
			$xml = $args[2];
		}

		$GLOBALS['th_upgrade'][$data['addon_id']] = true;;

		$installer = self::_getInstaller($data['addon_id']);
		if ($installer) {
			$installer->_data = $data;
			$installer->_xml = $xml;
			$installer->_install($data, $xml);
		}
	}

	/**
	 *
	 * @param array $data
	 * @param number $targetRunTime
	 * @param string $status
	 */
	public static function postInstall(array $data, $targetRunTime = 0, &$status = '')
	{
		$status = new XenForo_Phrase('install_add_on') . '...';

		$installer = self::_getInstaller($data['addon_id']);
		if ($installer) {
			$installer->_data = $data;
			$installer->_targetRunTime = $targetRunTime;
			$installer->_postInstallAfterTransaction();
		}

		return true;
	}

	/**
	 * At some point this will be made final, but old add-ons still extend it at
	 * present.
	 */
	public static function uninstall()
	{
		$args = func_get_args();
		if (isset($args[0])) {
			$data = $args[0];
		} else {
			return;
		}
		$installer = self::_getInstaller($data['addon_id']);
		if ($installer) {
			$installer->_data = $data;
			$installer->_uninstall($data);
		}
	}

	/**
	 *
	 * @param array $data
	 * @param number $targetRunTime
	 * @param string $status
	 */
	public static function postUninstall(array $data, $targetRunTime = 0, &$status = '')
	{
		$status = new XenForo_Phrase('uninstall_add_on') . '...';

		$installer = self::_getInstaller($data['addon_id']);
		if ($installer) {
			$installer->_data = $data;
			$installer->_targetRunTime = $targetRunTime;
			$installer->_postUninstallAfterTransaction();
		}

		return true;
	}

	/**
	 *
	 * @param $addOnId
	 * @return ThemeHouse_Install
	 */
	protected static function _getInstaller($addOnId)
	{
		if (class_exists($addOnId . "_Install_Controller")) {
			return self::create($addOnId . "_Install_Controller");
		} else
			if (class_exists($addOnId . "_Install")) {
				return self::create($addOnId . "_Install");
			}
	}

	/**
	 * Factory method to get the named installer.
	 * The class must exist or be autoloadable
	 * or an exception will be thrown.
	 *
	 * @param string Class to load
	 *
	 * @return ThemeHouse_Install
	 */
	public static function create($class)
	{
		self::_fetchDisabledInstallerThemeHouseListeners($class);

		$createClass = XenForo_Application::resolveDynamicClass($class, 'installer_th');

		if (!$createClass) {
			throw new XenForo_Exception("Invalid installer '$class' specified");
		}

		return new $createClass();
	}

	protected final function _install()
	{
		$this->_checkXenForoVersion();

		$prerequisites = $this->_getPrerequisites();
		if (!empty($prerequisites)) {
			$this->_checkPrerequisites($prerequisites);
		}

		$this->_preInstall();

		$fieldChanges = array_merge($this->_getFieldNameChanges(), $this->_getFieldChangesOnInstall());
		if (!empty($fieldChanges)) {
			$this->_makeFieldChanges($fieldChanges);
		}

		$tables = $this->_getTables();
		if (!empty($tables)) {
			$this->_createTables($tables);
		}

		$tables = $this->_getTablesOnInstall();
		if (!empty($tables)) {
			$this->_createTables($tables);
		}

		$tableChanges = $this->_getAllTableChangesOnInstall();
		if (!empty($tableChanges)) {
			$this->_makeTableChanges($tableChanges);
		}

		$contentTypeFields = $this->_getContentTypeFields();
		$contentTypes = $this->_getContentTypes();
		if (!empty($contentTypeFields)) {
			$this->_insertContentTypeFields($contentTypeFields, $contentTypes);
		}
		if (!empty($contentTypes)) {
			$this->_insertContentTypes($contentTypes);
		}

		$nodeTypes = $this->_getNodeTypes();
		if (!empty($nodeTypes)) {
			$this->_insertNodeTypes($nodeTypes);
		}

		$userFields = $this->_getUserFields();
		if (!empty($userFields)) {
			$this->_createUserFields($userFields);
		}

		$primaryKeys = $this->_getPrimaryKeys();
		if (!empty($primaryKeys)) {
			$this->_addPrimaryKeys($primaryKeys);
		}

		$uniqueKeys = $this->_getUniqueKeys();
		if (!empty($uniqueKeys)) {
			$this->_addUniqueKeys($uniqueKeys);
		}

		$keys = $this->_getKeys();
		if (!empty($keys)) {
			$this->_addKeys($keys);
		}

		$fullTextKeys = $this->_getFullTextKeys();
		if (!empty($fullTextKeys)) {
			$this->_addKeys($fullTextKeys, null, true);
		}

		$uniqueKeyChanges = $this->_getUniqueKeyChanges();
		if (!empty($uniqueKeyChanges)) {
			$this->_addUniqueKeyChanges($uniqueKeyChanges);
		}

		$keys = $this->_getKeys();
		if (!empty($keys)) {
			$this->_addKeys($keys);
		}

		$fullTextKeys = $this->_getFullTextKeys();
		if (!empty($fullTextKeys)) {
			$this->_addKeys($fullTextKeys, null, true);
		}

		$keyChanges = $this->_getKeyChanges();
		if (!empty($keyChanges)) {
			$this->_addKeyChanges($keyChanges);
		}

		$fields = $this->_getFields();
		if (!empty($fields)) {
			$this->_insertFields($fields);
		}

		$permissionEntries = $this->_getPermissionEntries();
		if (!empty($permissionEntries)) {
			$this->_insertPermissionEntries($permissionEntries);
		}

		$enumValues = $this->_getEnumValues();
		if (!empty($enumValues)) {
			$this->_alterEnumValues($enumValues);
		}

		if ($this->_resourceManagerUrl) {
			$this->_updateInstallUpgradeFilename($this->_resourceManagerUrl);
			$this->_updateAddOnInstaller($this->_resourceManagerUrl);
		}

		$this->_updateJustInstalled();
		$this->_makeCompatibilitySwitches();

		$this->_postInstall();
	}

	protected final function _uninstall()
	{
		if (self::$_noUninstall) {
			return;
		}

		$this->_preUninstall();

		if ($this->_disableListenersBeforeUninstall) {
			$this->_disableListeners();
		}

		$fieldChanges = $this->_getFieldChangesOnUninstall();
		if (!empty($fieldChanges)) {
			$this->_makeFieldChanges($fieldChanges);
		}

		$tables = $this->_getTables();
		if (!empty($tables)) {
			$this->_dropTables($tables);
		}

		$tables = $this->_getTablesOnUninstall();
		if (!empty($tables)) {
			$this->_dropTables($tables);
		}

		$tableChanges = $this->_getAllTableChangesOnUninstall();
		if (!empty($tableChanges)) {
			$this->_dropTableChanges($tableChanges);
		}

		$contentTypeFields = $this->_getContentTypeFields();
		if (!empty($contentTypeFields)) {
			$this->_deleteContentTypeFields($contentTypeFields);
		}

		$contentTypes = $this->_getContentTypes();
		if (!empty($contentTypes) || !empty($contentTypeFields)) {
			$this->_deleteContentTypes($contentTypes);
		}

		$nodeTypes = $this->_getNodeTypes();
		if (!empty($nodeTypes)) {
			$this->_deleteNodeTypes($nodeTypes);
		}

		$userFields = $this->_getUserFields();
		if (!empty($userFields)) {
			$this->_dropUserFields($userFields);
		}

		$uniqueKeys = $this->_getUniqueKeys();
		if (!empty($uniqueKeys)) {
			$this->_dropUniqueKeys($uniqueKeys);
		}

		$fields = $this->_getFields();
		if (!empty($fields)) {
			$this->_deleteFields($fields);
		}

		$enumValues = $this->_getEnumValues();
		if (!empty($enumValues)) {
			$this->_alterEnumValues($enumValues, true);
		}

		$this->_updateJustUninstalled();

		$this->_postUninstall();
	}

	public function __construct()
	{
		$this->_db = XenForo_Application::get('db');
		@set_time_limit(120);
		ignore_user_abort(true);
	}

	/**
	 *
	 * @return array [addon id] => version id
	 */
	protected function _getPrerequisites()
	{
		return array();
	}

	/**
	 *
	 * @param string|array|null $addOnIds
	 */
	public static function getPrerequisites($addOnIds = null)
	{
		$prerequisites = $this->_prerequisites;
		if (!$addOnIds) {
			return $prerequisites;
		} else
			if (is_array($addOnIds)) {
				return XenForo_Application::arrayFilterKeys($prerequisites, $addOnIds);
			} else {
				return (isset($prerequisites[$addOnIds]) ? $prerequisites[$addOnIds] : array());
			}
	}

	/**
	 *
	 * @deprecated Deprecated.
	 * @return array
	 */
	protected function _getFieldNameChanges()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getFieldChangesOnInstall()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getFieldChangesOnUninstall()
	{
		return array();
	}

	/**
	 * Gets the tables (with fields) to be created for this add-on.
	 *
	 * @return array Format: [table name] => fields
	 */
	protected function _getTables()
	{
		return array();
	}

	/**
	 * Gets the tables (with fields) to be created for this add-on.
	 * These tables will not be uninstalled.
	 *
	 * @return array Format: [table name] => fields
	 */
	protected function _getTablesOnInstall()
	{
		return array();
	}

	/**
	 * Gets the tables (with fields) to be uninstalled for this add-on.
	 *
	 * @return array Format: [table name] => fields
	 */
	protected function _getTablesOnUninstall()
	{
		return array();
	}

	/**
	 * Gets the field changes (grouped by table) to be made for this add-on.
	 *
	 * @return array Format: [table name] => field changes
	 */
	protected function _getTableChanges()
	{
		return array();
	}

	/**
	 * Gets the field changes (grouped by table) no longer needed for this
	 * add-on.
	 *
	 * @return array Format: [table name] => field changes
	 */
	protected function _getDeprecatedTableChanges()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getAddOnTableChanges()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getAddOnTableChangesOnInstall()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getAddOnTableChangesOnUninstall()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getContentTypes()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getContentTypeFields()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getNodeTypes()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getUserFields()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getPrimaryKeys()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getUniqueKeys()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getUniqueKeyChanges()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getKeys()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getFullTextKeys()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getKeyChanges()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getFields()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getPermissionEntries()
	{
		return array();
	}

	/**
	 *
	 * @return array
	 */
	protected function _getEnumValues()
	{
		return array();
	}

	protected function _checkXenForoVersion()
	{
		if (XenForo_Application::$versionId < $this->_minVersionId) {
			throw new XenForo_Exception('Minimum XenForo version of ' . $this->_minVersionString . ' required.');
		}
	}

	/**
	 *
	 * @param array $prerequisites
	 */
	protected function _checkPrerequisites(array $prerequisites)
	{
		/* @var $addOnModel XenForo_Model_AddOn */
		$addOnModel = $this->getModelFromCache('XenForo_Model_AddOn');
		$notInstalled = array();
		$outOfDate = array();
		foreach ($prerequisites as $addOnId => $versionId) {
			$addOn = $addOnModel->getAddOnById($addOnId);
			if (!$addOn) {
				$notInstalled[] = $addOnId;
			}
			if ($addOn['version_id'] < $versionId) {
				$outOfDate[] = $addOnId;
			}
		}
		if ($notInstalled) {
			throw new XenForo_Exception(
				'The following required add-ons need to be installed: ' . implode(',', $notInstalled), true);
		}
		if ($outOfDate) {
			throw new XenForo_Exception('The following required add-ons need to be updated: ' . implode(',', $outOfDate),
				true);
		}
	}

	/**
	 *
	 * @param array $fieldChanges
	 */
	protected function _makeFieldChanges(array $fieldChanges)
	{
		foreach ($fieldChanges as $tableName => $rows) {
			if ($this->_isTableExists($tableName)) {
				$describeTable = $this->_db->describeTable($tableName);
				$keys = array_keys($describeTable);
				$sql = "ALTER IGNORE TABLE `" . $tableName . "` ";
				$sqlAdd = array();
				foreach ($rows as $oldFieldName => $newField) {
					if (in_array($oldFieldName, $keys)) {
						$sqlAdd[] = "CHANGE `" . $oldFieldName . "` " . $newField;
					}
				}
				$sql .= implode(", ", $sqlAdd);
				$this->_db->query($sql);
			}
		}
	}

	/**
	 *
	 * @param array $tables
	 */
	protected function _createTables(array $tables)
	{
		foreach ($tables as $tableName => $rows) {
			if (!$this->_isTableExists($tableName)) {
				$sql = "CREATE TABLE IF NOT EXISTS `" . $tableName . "` (";
				$sqlRows = array();
				foreach ($rows as $rowName => $rowParams) {
					$sqlRows[] = "`" . $rowName . "` " . $rowParams;
				}
				$sql .= implode(",", $sqlRows);
				$sql .= ") ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci";
				$this->_db->query($sql);
				if (self::$_tablesList) {
					self::$_tablesList[] = strtolower($tableName);
				}
			} else {
				$tableChanges = array(
					$tableName => $rows
				);
				$this->_makeTableChanges($tableChanges);
			}
		}
	}

	/**
	 *
	 * @param array $tableChanges
	 */
	protected function _makeTableChanges(array $tableChanges)
	{
		$droppedTableChanges = array();
		foreach ($tableChanges as $tableName => $rows) {
			if ($this->_isTableExists($tableName)) {
				$describeTable = $this->_db->describeTable($tableName);
				$keys = array_keys($describeTable);
				$sql = "ALTER IGNORE TABLE `" . $tableName . "` ";
				$sqlAdd = array();
				foreach ($rows as $rowName => $rowParams) {
					if (!$rowParams) {
						$droppedTableChanges[$tableName][$rowName] = '';
						continue;
					}
					if (!empty($describeTable[$rowName])) {
						$rowPattern = $this->_getRowPatternFromTableDescription($describeTable[$rowName]);
						if (preg_match($rowPattern, $rowParams)) {
							continue;
						}
					}
					if (strpos($rowParams, 'PRIMARY KEY') !== false) {
						if ($this->_getExistingPrimaryKeys($tableName)) {
							$sqlAdd[] = "DROP PRIMARY KEY ";
						}
					}
					if (in_array($rowName, $keys)) {
						$sqlAdd[] = "CHANGE `" . $rowName . "` `" . $rowName . "` " . $rowParams;
					} else {
						$sqlAdd[] = "ADD `" . $rowName . "` " . $rowParams;
					}
				}
				if ($sqlAdd) {
					$sqlAdd[] = 'ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci';
					$sql .= implode(", ", $sqlAdd);
					$this->_db->query($sql);
				}
			}
		}
		$this->_dropTableChanges($droppedTableChanges);
	}

	/**
	 *
	 * @param string $tableName
	 * @return array $existingPrimaryKeys
	 */
	protected function _getExistingPrimaryKeys($tableName)
	{
		$primaryKeys = array();
		if ($this->_isTableExists($tableName)) {
			$columns = $this->_db->describeTable($tableName);
			foreach ($columns as $columnName => $column) {
				if ($column['PRIMARY']) {
					$primaryKeys[] = $columnName;
				}
			}
		}
		return $primaryKeys;
	}

	/**
	 *
	 * @param array $primaryKeys
	 */
	protected function _addPrimaryKeys(array $primaryKeys)
	{
		foreach ($primaryKeys as $tableName => $primaryKey) {
			$oldKey = $this->_getExistingPrimaryKeys($tableName);
			$keyDiff = array_diff($primaryKey, $oldKey);
			if (!empty($keyDiff)) {
				$sql = "ALTER TABLE `" . $tableName . "`
					" . (empty($oldKey) ? "" : "DROP PRIMARY KEY, ") . "
					ADD PRIMARY KEY(" . implode(",", $primaryKey) . ")";
				$this->_db->query($sql);
			}
		}
	}

	/**
	 *
	 * @param string $tableName
	 * @return array $existingKeys
	 */
	protected function _getExistingKeys($tableName)
	{
		$keys = array();
		if ($this->_isTableExists($tableName)) {
			$columns = $this->_db->describeTable($tableName);
			$indexes = $this->_db->fetchAll('SHOW INDEXES FROM  `' . $tableName . '`');
			foreach ($indexes as $index) {
				if (!isset($keys[$index['Key_name']])) {
					$keys[$index['Key_name']] = $index;
				}
				$keys[$index['Key_name']]['Column_names'][] = $index['Column_name'];
			}
		}
		return $keys;
	}

	/**
	 *
	 * @param array $uniqueKeys
	 */
	protected function _addUniqueKeys(array $uniqueKeys)
	{
		$removedUniqueKeys = array();
		foreach ($uniqueKeys as $tableName => $uniqueKey) {
			$oldKeys = $this->_getExistingKeys($tableName);
			foreach ($uniqueKey as $keyName => $keyColumns) {
				if (!$keyColumns) {
					$removedUniqueKeys[$tableName][$keyName] = '';
					continue;
				}
				if (isset($oldKeys[$keyName])) {
					$keyDiff = array_diff($oldKeys[$keyName]['Column_names'], $keyColumns);
					if ($keyDiff) {
						$sql = "ALTER TABLE `" . $tableName . "`
							DROP INDEX `" . $keyName . "`,
							ADD UNIQUE `" . $keyName . "` (" . implode(",", $keyColumns) . ")";
						$this->_db->query($sql);
					}
				} else {
					$sql = "ALTER TABLE `" . $tableName . "`
						ADD UNIQUE `" . $keyName . "` (" . implode(",", $keyColumns) . ")";
					$this->_db->query($sql);
				}
			}
		}

		if ($removedUniqueKeys) {
			$this->_dropUniqueKeys($removedUniqueKeys);
		}
	}

	/**
	 *
	 * @param array $uniqueKeys
	 */
	protected function _dropUniqueKeys(array $uniqueKeys)
	{
		foreach ($uniqueKeys as $tableName => $uniqueKey) {
			$oldKeys = $this->_getExistingKeys($tableName);
			foreach ($uniqueKey as $keyName => $keyColumns) {
				if (isset($oldKeys[$keyName])) {
					$sql = "ALTER TABLE `" . $tableName . "`
						DROP INDEX `" . $keyName . "`";
					$this->_db->query($sql);
				}
			}
		}
	}

	/**
	 *
	 * @param array $uniqueKeyChanges
	 */
	protected function _addUniqueKeyChanges(array $uniqueKeyChanges)
	{
		return $this->_addKeyChanges($uniqueKeyChanges, true);
	}

	/**
	 *
	 * @param array $keys
	 */
	protected function _addKeys(array $keys, $oldKeys = null, $fullText = false)
	{
		foreach ($keys as $tableName => $key) {
			$oldKeys = $this->_getExistingKeys($tableName);
			foreach ($key as $keyName => $keyColumns) {
				if (isset($oldKeys[$keyName])) {
					$keyDiff = array_diff($oldKeys[$keyName]['Column_names'], $keyColumns);
					if ($keyDiff) {
						$sql = "ALTER TABLE `" . $tableName . "`
							DROP INDEX `" . $keyName . "`,
							ADD " .
							 ($fullText ? 'FULLTEXT ' : '') . "INDEX `" . $keyName . "` (" . implode(",", $keyColumns) .
							 ")";
						$this->_db->query($sql);
					}
				} else {
					$sql = "ALTER TABLE `" . $tableName . "`
						" . (!isset($oldKeys[$keyName]) ? "" : "DROP INDEX `" . $keyName . "`, ") . "
						ADD " .
						 ($fullText ? 'FULLTEXT ' : '') . "INDEX `" . $keyName . "` (" . implode(",", $keyColumns) . ")";
					$this->_db->query($sql);
				}
			}
		}
	}

	/**
	 *
	 * @param array $keyChanges
	 */
	protected function _addKeyChanges(array $keyChanges, $isUnique = false)
	{
		foreach ($keyChanges as $tableName => &$key) {
			$oldKeys = $this->_getExistingKeys($tableName);
			foreach ($key as $keyName => &$keyColumns) {
				if (!isset($oldKeys[$keyName])) {
					unset($keyChanges[$tableName][$keyName]);
					continue;
				}
				foreach ($oldKeys[$keyName]['Column_names'] as $oldColumnName) {
					if (in_array($oldColumnName, $keyColumns)) {
						unset($keyColumns[array_search($oldColumnName, $keyColumns)]);
					}
				}
				if (empty($keyColumns)) {
					unset($keyChanges[$tableName][$keyName]);
					continue;
				}
				$keyColumns = array_merge($keyColumns, $oldKeys[$keyName]['Column_names']);
			}
		}
		if ($isUnique) {
			return $this->_addUniqueKeys($keyChanges);
		}
		return $this->_addKeys($keyChanges);
	}

	/**
	 *
	 * @param array $tables
	 */
	protected function _dropTables(array $tables)
	{
		foreach ($tables as $tableName => $rows) {
			$sql = "DROP TABLE IF EXISTS `" . $tableName . "` ";
			$this->_db->query($sql);
			if (self::$_tablesList && in_array($tableName, self::$_tablesList)) {
				unset(self::$_tablesList[array_search($tableName, self::$_tablesList)]);
			}
		}
	}

	/**
	 *
	 * @param array $tableChanges
	 */
	protected function _dropTableChanges(array $tableChanges)
	{
		foreach ($tableChanges as $tableName => $rows) {
			if ($this->_isTableExists($tableName)) {
				$keys = array_keys($this->_db->describeTable($tableName));
				foreach ($rows as $rowName => $rowParams) {
					if (in_array($rowName, $keys)) {
						$sql = "ALTER IGNORE TABLE `" . $tableName . "` DROP `" . $rowName . "`";
						$this->_db->query($sql);
					}
				}
			}
		}
	}

	/**
	 *
	 * @param array $inserts
	 */
	protected function _insertFields(array $inserts)
	{
		foreach ($inserts as $insert) {
			if (isset($insert['table_name'], $insert['data_writer'])) {
				$dw = XenForo_DataWriter::create($insert['data_writer']);
				if (isset($insert['primary_fields'])) {
					$sql = "SELECT count(*) FROM `" . $insert['table_name'] . "` ";
					$whereClauses = array();
					foreach ($insert['primary_fields'] as $fieldName => $fieldValue) {
						$whereClauses[] = "`" . $fieldName . "` = '" . $fieldValue . "'";
					}
					if (!empty($whereClauses)) {
						$sql .= "WHERE " . implode(" AND ", $whereClauses) . " ";
					}
					if ($this->_db->fetchOne($sql)) {
						$dw->setExistingData($insert['primary_fields']);
					}
				}
				$dw->bulkSet($insert['primary_fields']);
				if (isset($insert['fields'])) {
					$dw->bulkSet($insert['fields']);
				}
				$dw->save();
			}
		}
	}

	/**
	 *
	 * @param array $inserts
	 */
	protected function _deleteFields(array $inserts)
	{
		foreach ($inserts as $insert) {
			if (isset($insert['data_writer'])) {
				$dw = XenForo_DataWriter::create($insert['data_writer']);
				if (isset($insert['primary_fields'])) {
					$dw->setExistingData($insert['primary_fields']);
				}
				$dw->delete();
			}
		}
	}

	/**
	 *
	 * @param array $inserts
	 */
	protected function _insertPermissionEntries(array $inserts)
	{
		foreach ($inserts as $permissionGroupId => $permissionIds) {
			foreach ($permissionIds as $permissionId => $existingPermissionEntry) {
				if (isset($existingPermissionEntry['permission_group_id']) &&
					 isset($existingPermissionEntry['permission_id'])) {
					$this->_db->query(
						'
						INSERT IGNORE INTO xf_permission_entry
							(user_group_id, user_id, permission_group_id, permission_id, permission_value, permission_value_int)
						SELECT user_group_id, user_id, ?,  ?, permission_value, permission_value_int
						FROM xf_permission_entry
						WHERE permission_group_id =  ? AND permission_id =  ?
						',
						array(
							$permissionGroupId,
							$permissionId,
							$existingPermissionEntry['permission_group_id'],
							$existingPermissionEntry['permission_id']
						));
				}
			}
		}
	}

	/**
	 *
	 * @param array $userFields
	 */
	protected function _createUserFields(array $userFields)
	{
		foreach ($userFields as $fieldId => $fields) {
			$dw = XenForo_DataWriter::create('XenForo_DataWriter_UserField');
			if (!$dw->setExistingData($fieldId)) {
				$dw->set('field_id', $fieldId);
			}
			$dw->bulkSet($fields);
			$dw->save();
		}
	}

	/**
	 *
	 * @param array $userFields
	 */
	protected function _dropUserFields(array $userFields)
	{
		foreach ($userFields as $fieldId => $fields) {
			$dw = XenForo_DataWriter::create('XenForo_DataWriter_UserField');
			$dw->setExistingData($fieldId);
			$dw->delete();
		}
	}

	/**
	 *
	 * @param array $contentTypes
	 */
	protected function _insertContentTypes(array $contentTypes)
	{
		$contentTypeModel = $this->getModelFromCache('XenForo_Model_ContentType');
		$existingContentTypes = $contentTypeModel->getContentTypesForCache();

		foreach ($contentTypes as $contentType => $contentTypeParams) {
			$existingFields = array();

			if (isset($existingContentTypes[$contentType])) {
				$existingFields = $existingContentTypes[$contentType];
			}
			if (isset($contentTypeParams['addon_id'])) {
				if (isset($contentTypeParams['fields'])) {
					$contentTypeFields = array(
						$contentType => $contentTypeParams['fields']
					);
					$this->_insertContentTypeFields($contentTypeFields);
					$existingFields = array_merge($contentTypeFields, $existingFields);
				}
				$fields = serialize($existingFields);
				$addOnId = $contentTypeParams['addon_id'];
				$sql = "INSERT INTO xf_content_type (
							content_type,
							addon_id,
							fields
						) VALUES (
							'" . $contentType . "',
							'" . $addOnId . "',
							'" . $fields . "'
						) ON DUPLICATE KEY UPDATE
							addon_id = '" . $addOnId . "',
							fields = '" . $fields . "'";
				$this->_db->query($sql);
				$existingContentTypes[$contentType] = $existingFields;
			} else {
				$this->_db->update('xf_content_type',
					array(
						'fields' => serialize($existingFields)
					), 'content_type = ' . $this->_db->quote($contentType));
			}
		}

		$dataRegistryModel = $this->getModelFromCache('XenForo_Model_DataRegistry');
		$dataRegistryModel->set('contentTypes', $existingContentTypes);
	}

	/**
	 *
	 * @param array $contentTypeFields
	 * @param array $contentTypes
	 */
	protected function _insertContentTypeFields(array &$contentTypeFields, array &$contentTypes = array())
	{
		foreach ($contentTypeFields as $contentType => $fields) {
			foreach ($fields as $fieldName => $fieldValue) {
				if (!is_array($fieldValue)) {
					$contentTypeFields[$contentType][$fieldName] = array(
						'content_type' => $contentType,
						'field_name' => $fieldName,
						'field_value' => $fieldValue
					);
				} else {
					$fieldValue = $fieldValue['field_value'];
				}
				$sql = "INSERT INTO xf_content_type_field (
						content_type,
						field_name,
						field_value
					) VALUES (
						'" . $contentType . "',
						'" . $fieldName . "',
						'" . $fieldValue . "'
					) ON DUPLICATE KEY UPDATE
						field_value = '" . $fieldValue . "'";
				$this->_db->query($sql);
			}
			$contentTypes[$contentType] = array();
		}
	}

	/**
	 *
	 * @param array $contentTypes
	 */
	protected function _deleteContentTypes(array $contentTypes)
	{
		foreach ($contentTypes as $contentType => $contentTypeParams) {
			if (isset($contentTypeParams['addon_id']) && $contentTypeParams['addon_id'] == $this->_data['addon_id']) {
				$addOnId = $contentTypeParams['addon_id'];
				$sql = "DELETE FROM xf_content_type WHERE content_type = '" . $contentType . "' AND addon_id = '" .
					 $addOnId . "'";
				$this->_db->query($sql);
				$sql = "DELETE FROM xf_content_type_field WHERE content_type = '" . $contentType . "'";
				$this->_db->query($sql);
			} else {
				if (isset($contentTypeParams['fields'])) {
					$this->_deleteContentTypeFields(
						array(
							$contentType => $contentTypeParams['fields']
						));
				}
			}
		}
		XenForo_Model::create('XenForo_Model_ContentType')->rebuildContentTypeCache();
	}

	/**
	 *
	 * @param array $contentTypes
	 */
	protected function _deleteContentTypeFields(array $contentTypes)
	{
		foreach ($contentTypes as $contentType => $contentTypeFields) {
			foreach ($contentTypeFields as $fieldName => $fieldValue) {
				$sql = "DELETE FROM xf_content_type_field WHERE content_type = '" . $contentType . "'
						AND field_name = '" . $fieldName .
					 "' AND field_value = '" . $fieldValue . "'";
				$this->_db->query($sql);
			}
		}
	}

	/**
	 *
	 * @param array $nodeTypes
	 */
	protected function _insertNodeTypes(array $nodeTypes)
	{
		foreach ($nodeTypes as $nodeTypeId => $nodeTypeParams) {
			$sql = "INSERT INTO `xf_node_type` (
					`node_type_id`,
					`handler_class`,
					`controller_admin_class`,
					`datawriter_class`,
					`permission_group_id`,
					`public_route_prefix`
				) VALUES (
					'" . $nodeTypeId . "',
					'" . $nodeTypeParams['handler_class'] . "',
					'" . $nodeTypeParams['controller_admin_class'] . "',
					'" . $nodeTypeParams['datawriter_class'] . "',
					 '" . $nodeTypeParams['permission_group_id'] . "',
					 '" . $nodeTypeParams['public_route_prefix'] . "'
				 ) ON DUPLICATE KEY UPDATE
					handler_class = '" . $nodeTypeParams['handler_class'] . "',
					controller_admin_class = '" . $nodeTypeParams['controller_admin_class'] . "',
					datawriter_class = '" .
				 $nodeTypeParams['datawriter_class'] . "',
					permission_group_id = '" .
				 $nodeTypeParams['permission_group_id'] . "',
					public_route_prefix = '" .
				 $nodeTypeParams['public_route_prefix'] . "'";
			$this->_db->query($sql);
		}
		XenForo_Model::create('XenForo_Model_Node')->rebuildNodeTypeCache();
	}

	/**
	 *
	 * @param array $nodeTypes
	 */
	protected function _deleteNodeTypes(array $nodeTypes)
	{
		foreach ($nodeTypes as $nodeTypeId => $nodeTypeParams) {
			$sql = "DELETE FROM `xf_node_type` WHERE `node_type_id` = '" . $nodeTypeId . "' ";
			$this->_db->query($sql);
			$sql = "DELETE FROM `xf_node` WHERE `node_type_id` = '" . $nodeTypeId . "' ";
			$this->_db->query($sql);
		}
		XenForo_Model::create('XenForo_Model_Node')->rebuildNodeTypeCache();
	}

	/**
	 *
	 * @param array $enumValues
	 * @param boolean $reverse
	 */
	protected function _alterEnumValues(array $enumValues, $reverse = false)
	{
		foreach ($enumValues as $tableName => $fields) {
			if ($this->_isTableExists($tableName)) {
				$table = $this->_db->describeTable($tableName);
				foreach ($fields as $fieldName => $fieldEnums) {
					if (!isset($table[$fieldName])) {
						continue;
					}
					preg_match('/^enum\((.*)\)$/', $table[$fieldName]['DATA_TYPE'], $matches);
					foreach (explode(',', $matches[1]) as $value) {
						$enums[] = trim($value, "'");
					}
					$newEnums = $enums;
					if (isset($fieldEnums['add'])) {
						if (!$reverse) {
							foreach ($fieldEnums['add'] as $fieldEnum) {
								$newEnums[] = $fieldEnum;
							}
						} else {
							foreach ($fieldEnums['add'] as $fieldEnum) {
								$this->_db->delete($tableName, $fieldName . ' = \'' . $fieldEnum . '\'');
							}
							$newEnums = array_diff($newEnums, $fieldEnums['add']);
						}
						$newEnums = array_unique($newEnums);
					}
					if (isset($fieldEnums['remove'])) {
						if (!$reverse) {
							foreach ($fieldEnums['remove'] as $fieldEnum) {
								$this->_db->delete($tableName, $fieldName . ' = \'' . $fieldEnum . '\'');
							}
							$newEnums = array_diff($newEnums, $fieldEnums['remove']);
						} else {
							foreach ($fieldEnums['remove'] as $fieldEnum) {
								$newEnums[] = $fieldEnum;
							}
						}
						$newEnums = array_unique($newEnums);
					}
					sort($enums);
					sort($newEnums);
					if ($enums != $newEnums) {
						foreach ($newEnums as &$value) {
							$value = '\'' . $value . '\'';
						}
						$table[$fieldName]['DATA_TYPE'] = 'enum(' . implode(',', $newEnums) . ')';
						$this->_alterTable($table[$fieldName]);
					}
				}
			}
		}
	}

	/**
	 *
	 * @param array $description
	 * @param string $newColumnName
	 */
	protected function _alterTable(array $description, $newColumnName = null)
	{
		if (!$newColumnName) {
			$newColumnName = $description['COLUMN_NAME'];
		}
		$this->_db->query(
			'
			ALTER TABLE ' . $description['TABLE_NAME'] . '
			CHANGE ' . $description['COLUMN_NAME'] . ' ' . $newColumnName . '
			' .
				 $this->_getRowParamsFromTableDescription($description) . '
		');
	}

	protected function _updateInstallUpgradeFilename($resourceUrl)
	{
		if (self::_isAddOnInstalled('ThemeHouse_InstallUpgrade')) {
			if (method_exists('ThemeHouse_InstallUpgrade_ControllerHelper_InstallUpgrade', 'setFileName')) {
				ThemeHouse_InstallUpgrade_ControllerHelper_InstallUpgrade::setFileName($resourceUrl);
			}
		}
	}

	protected function _updateAddOnInstaller($resourceUrl)
	{
		if (isset($this->_data['addon_id'])) {
			$data = array(
				'addon_id' => $this->_data['addon_id'],
				'update_url' => $resourceUrl,
				'check_updates' => 1,
				'last_checked' => XenForo_Application::$time
			);

			try {
				$writer = XenForo_DataWriter::create('AddOnInstaller_DataWriter_Updater');
			} catch (Exception $e) {
				// do nothing
			}
			/* @var $addOnModel XenForo_Model_AddOn */
			$addOnModel = $this->getModelFromCache('XenForo_Model_AddOn');
			if (method_exists($addOnModel, 'isDwUpdate')) {
				if ($addOnModel->isDwUpdate($data['addon_id'])) {
					$writer->setExistingData($data['addon_id']);
				}
				$writer->bulkSet($data);
				$writer->set('latest_version', $writer->get('version_string'));
				$writer->save();
			}
		}
	}

	protected function _updateJustInstalled()
	{
		if (isset($this->_data['addon_id'])) {
			if (XenForo_Application::$versionId < 1020000 &&
				 defined('ThemeHouse_Listener_InitDependencies::JUST_INSTALLED_SIMPLE_CACHE_KEY')) {
				$justInstalled = XenForo_Application::getSimpleCacheData(
					ThemeHouse_Listener_InitDependencies::JUST_INSTALLED_SIMPLE_CACHE_KEY);

				if (!$justInstalled) {
					$justInstalled = array();
				}

				if (!in_array($this->_data['addon_id'], $justInstalled)) {
					$justInstalled[] = $this->_data['addon_id'];
				}
				XenForo_Application::setSimpleCacheData(
					ThemeHouse_Listener_InitDependencies::JUST_INSTALLED_SIMPLE_CACHE_KEY, $justInstalled);
			} elseif (XenForo_Application::$versionId >= 1020000) {
				XenForo_Application::defer('ThemeHouse_Deferred',
					array_merge($this->_data, array(
						'install' => true
					)));
			}
		}
	}

	protected function _makeCompatibilitySwitches()
	{
		if (XenForo_Application::$versionId >= 1020000 && $this->_xml) {
			foreach ($this->_xml->code_event_listeners->listener as $listener) {
				/* @var $listener SimpleXMLElement */
				if ($listener['compatibility_switch']) {
					$listener['active'] = ($listener['active'] == '1' ? '0' : '1');
					unset($listener['compatibility_switch']);
				}
			}
		}
	}

	protected function _updateJustUninstalled()
	{
		if (isset($this->_data['addon_id'])) {
			if (XenForo_Application::$versionId < 1020000 &&
				 defined('ThemeHouse_Listener_InitDependencies::JUST_UNINSTALLED_SIMPLE_CACHE_KEY')) {
				$justUninstalled = XenForo_Application::getSimpleCacheData(
					ThemeHouse_Listener_InitDependencies::JUST_UNINSTALLED_SIMPLE_CACHE_KEY);

				if (!$justUninstalled) {
					$justUninstalled = array();
				}

				if (!in_array($this->_data['addon_id'], $justUninstalled)) {
					$justUninstalled[] = $this->_data['addon_id'];
				}
				XenForo_Application::setSimpleCacheData(
					ThemeHouse_Listener_InitDependencies::JUST_UNINSTALLED_SIMPLE_CACHE_KEY, $justUninstalled);
			} elseif (XenForo_Application::$versionId >= 1020000) {
				XenForo_Application::defer('ThemeHouse_Deferred',
					array_merge($this->_data,
						array(
							'uninstall' => true
						)));
			}
		}
	}

	protected function _preInstall()
	{
	}

	protected function _preUninstall()
	{
	}

	protected function _postInstall()
	{
	}

	protected function _postUninstall()
	{
	}

	protected function _postInstallAfterTransaction()
	{
	}

	protected function _postUninstallAfterTransaction()
	{
	}

	/**
	 *
	 * @param string $tableName
	 * @return boolean
	 */
	protected final function _isTableExists($tableName)
	{
		if (!self::$_tablesList) {
			self::$_tablesList = array_map('strtolower', $this->_db->listTables());
		}
		return in_array(strtolower($tableName), self::$_tablesList);
	}

	protected final function _isAddOnInstalled($addOnId)
	{
		/* @var $addOnModel XenForo_Model_AddOn */
		$addOnModel = $this->getModelFromCache('XenForo_Model_AddOn');

		return ($addOnModel->getAddOnById($addOnId));
	}

	/**
	 *
	 * @param string $addOnId
	 * @return array $tableChanges
	 */
	public final function getTableChangesForAddOn($addOnId)
	{
		$addOnTableChanges = $this->_getAddOnTableChanges();
		return (isset($addOnTableChanges[$addOnId]) ? $addOnTableChanges[$addOnId] : array());
	}

	/**
	 *
	 * @param string $addOnId
	 * @return array $tableChanges
	 */
	public final function getTableChangesForAddOnOnInstall($addOnId)
	{
		$addOnTableChanges = $this->_getAddOnTableChanges();
		$addOnTableChangesOnInstall = $this->_getAddOnTableChangesOnInstall();
		foreach ($addOnTableChangesOnInstall as $addOnId => $addOnTableChangesForAddOn) {
			if (!empty($addOnTableChanges[$addOnId])) {
				$addOnTableChanges[$addOnId] = array_merge($addOnTableChanges[$addOnId], $addOnTableChangesForAddOn);
			} else {
				$addOnTableChanges[$addOnId] = $addOnTableChangesForAddOn;
			}
		}
		return (isset($addOnTableChanges[$addOnId]) ? $addOnTableChanges[$addOnId] : array());
	}

	/**
	 *
	 * @param string $addOnId
	 * @return array $tableChanges
	 */
	public final function getTableChangesForAddOnOnUninstall($addOnId)
	{
		$addOnTableChanges = $this->_getAddOnTableChanges();
		$addOnTableChangesOnUninstall = $this->_getAddOnTableChangesOnUninstall();
		foreach ($addOnTableChangesOnUninstall as $addOnId => $addOnTableChangesForAddOn) {
			if (!empty($addOnTableChanges[$addOnId])) {
				$addOnTableChanges[$addOnId] = array_merge($addOnTableChanges[$addOnId], $addOnTableChangesForAddOn);
			} else {
				$addOnTableChanges[$addOnId] = $addOnTableChangesForAddOn;
			}
		}
		return (isset($addOnTableChanges[$addOnId]) ? $addOnTableChanges[$addOnId] : array());
	}

	/**
	 * Gets the field changes to be made to the specified table for the
	 * specified add-on.
	 *
	 * @param string $addOnId
	 * @param string $tableName
	 *
	 * @return array Format: [field name] => database structure
	 */
	protected final function _getTableChangesForAddOn($addOnId, $tableName)
	{
		$installer = self::_getInstaller($addOnId);
		$tableChanges = $installer->getTableChangesForAddOn($this->_data['addon_id']);
		return (isset($tableChanges[$tableName]) ? $tableChanges[$tableName] : array());
	}

	/**
	 *
	 * @return array $tableChanges
	 */
	protected final function _getAllTableChangesOnInstall()
	{
		/* @var $addOnModel XenForo_Model_AddOn */
		$addOnModel = XenForo_Model::create('XenForo_Model_AddOn');
		$addOns = $addOnModel->getAllAddOns();

		$tableChanges = $this->_getTableChanges();
		foreach ($addOns as $addOnId => $addOn) {
			$tableChanges = array_merge($tableChanges, $this->getTableChangesForAddOnOnInstall($addOnId));
		}

		return $tableChanges;
	}

	/**
	 *
	 * @return array $tableChanges
	 */
	protected final function _getAllTableChangesOnUninstall()
	{
		/* @var $addOnModel XenForo_Model_AddOn */
		$addOnModel = XenForo_Model::create('XenForo_Model_AddOn');
		$addOns = $addOnModel->getAllAddOns();

		$tableChanges = $this->_getTableChanges();
		foreach ($addOns as $addOnId => $addOn) {
			$tableChanges = array_merge($tableChanges, $this->getTableChangesForAddOnOnUninstall($addOnId));
		}

		return $tableChanges;
	}

	/**
	 *
	 * @param string $hint
	 */
	protected static function _fetchDisabledInstallerThemeHouseListeners($hint = '')
	{
		/* @var $codeEventModel XenForo_Model_CodeEvent */
		$codeEventModel = XenForo_Model::create('XenForo_Model_CodeEvent');
		$listeners = $codeEventModel->getAllEventListeners();

		$installerThemeHouseListeners = XenForo_CodeEvent::getEventListeners('load_class_installer_th');
		if (XenForo_Application::$versionId > 1020000) {
			if (isset($installerThemeHouseListeners['_'])) {
				$installerThemeHouseListeners = $installerThemeHouseListeners['_'];
			} else {
				$installerThemeHouseListeners = array();
			}
			if ($hint) {
				if (isset($installerThemeHouseListeners[$hint])) {
					$installerThemeHouseListeners = array_merge($installerThemeHouseListeners,
						$installerThemeHouseListeners[$hint]);
				}
			}
		}
		foreach ($listeners as $listener) {
			if ($listener['event_id'] == 'load_class_installer_th') {
				if ($installerThemeHouseListeners) {
					foreach ($installerThemeHouseListeners as $installerThemeHouseListener) {
						list($callbackClass, $callbackMethod) = $installerThemeHouseListener;
						if ($listener['callback_class'] == $callbackClass &&
							 $listener['callback_method'] == $callbackMethod) {
							continue (2);
						}
					}
				}
				if ($listener['active']) {
					XenForo_CodeEvent::addListener('load_class_installer_th',
						array(
							$listener['callback_class'],
							$listener['callback_method']
						), $hint);
				}
			}
		}
		$installerThemeHouseListeners = XenForo_CodeEvent::getEventListeners('load_class_installer_th');
	}

	protected function _disableListeners()
	{
		if (isset($this->_data['addon_id'])) {
			$this->_db->query(
				'
				UPDATE xf_addon SET active = 0
				WHERE addon_id = ?
			', $this->_data['addon_id']);

			$cache = $this->getModelFromCache('XenForo_Model_CodeEvent')->rebuildEventListenerCache();

			XenForo_CodeEvent::setListeners($cache, false);
		}
	}

	/**
	 *
	 * @param array $description
	 * @return string
	 */
	protected function _getRowParamsFromTableDescription(array $description)
	{
		return $description['DATA_TYPE'] . ($description['LENGTH'] ? '(' . $description['LENGTH'] . ')' : '') .
			 ($description['UNSIGNED'] ? ' UNSIGNED' : '') . ($description['NULLABLE'] ? ' NULL' : ' NOT NULL') .
			 (!is_null($description['DEFAULT']) ? ' DEFAULT ' . $this->_db->quote($description['DEFAULT']) : '') .
			 ($description['IDENTITY'] ? ' AUTO_INCREMENT' : '') . ($description['PRIMARY'] ? ' PRIMARY KEY' : '');
	}

	/**
	 *
	 * @param array $description
	 * @return string
	 */
	protected function _getRowPatternFromTableDescription(array $description)
	{
		return '#' . preg_quote(
			$description['DATA_TYPE'] . ($description['LENGTH'] ? '(' . $description['LENGTH'] . ')' : '') .
				 ($description['UNSIGNED'] ? ' UNSIGNED' : '') . ($description['NULLABLE'] ? ' NULL' : ' NOT NULL') .
				 (!is_null($description['DEFAULT']) ? ' DEFAULT ' . $this->_db->quote($description['DEFAULT']) : '') .
				 ($description['IDENTITY'] ? ' AUTO_INCREMENT' : '')) . '#';
	}
}