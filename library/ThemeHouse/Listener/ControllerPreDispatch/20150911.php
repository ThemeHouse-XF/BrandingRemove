<?php

class ThemeHouse_Listener_ControllerPreDispatch
{

	protected static $_checkForUpdates = null;

	protected static $_upgradeAddOns = array();

	/* Deprecated. */
	protected static $_showCopyright = false;

	/**
	 * Standard approach to caching other model objects for the lifetime of the
	 * model.
	 *
	 * @var array
	 */
	protected $_modelCache = array();

	/**
	 *
	 * @var XenForo_Controller
	 */
	protected static $_controller = null;

	protected static $_action = '';

	protected static $_controllerName = '';

	const LAST_XML_UPLOAD_DATE_SIMPLE_CACHE_KEY = 'th_lastXmlUploadDate';

	/**
	 *
	 * @param XenForo_Controller $controller
	 * @param array $action
	 * @param string $controllerName since XenForo 1.2
	 */
	public function __construct(XenForo_Controller $controller, $action, $controllerName = '')
	{
		if (is_null(self::$_controller)) {
			self::$_controller = $controller;
		}
		if (!self::$_action) {
			self::$_action = $action;
		}

		if (!self::$_controllerName) {
			if ($controllerName) {
				self::$_controllerName = $controllerName;
			} else {
				self::$_controllerName = get_class(self::$_controller);
			}
		}
	}

	/**
	 * Called before attempting to dispatch the request in a specific
	 * controller.
	 * The visitor object is available at this point.
	 *
	 * @param XenForo_Controller $controller - the controller instance. From
	 * this, you can inspect the
	 * request, response, etc.
	 * @param string $action - the specific action that will be executed in this
	 * controller.
	 */
	public static function controllerPreDispatch(XenForo_Controller $controller, $action)
	{
		$arguments = func_get_args();
		if (isset($arguments[2])) {
			$controllerName = func_get_arg(2);
		} else {
			$controllerName = '';
		}

		if (function_exists('get_called_class')) {
			$className = get_called_class();
		} else {
			$className = get_class();
		}

		self::createAndRun($className, $controller, $action, $controllerName);
	}

	public function run()
	{
		if (is_subclass_of(self::$_controller, 'XenForo_ControllerAdmin_Abstract')) {
			if (!isset(self::$_checkForUpdates)) {
				self::$_checkForUpdates = true;
				$lastXMLUploadDate = $this->_getLastXmlUploadDate();

				$addOns = array();

				try {
					$installDataDir = XenForo_Application::getInstance()->getRootDir() . '/install/data/';
					if (is_dir($installDataDir)) {
						/* @var $dir Directory */
						$dir = dir($installDataDir);

						if (XenForo_Application::$versionId >= 1020000) {
							$allAddOns = XenForo_Application::get('addOns');
						}

						while ($entry = $dir->read()) {
							if (strlen($entry) > strlen('addon-.xml') && substr($entry, 0, strlen('addon-')) == 'addon-') {
								$addOnId = substr($entry, strlen('addon-'), strlen($entry) - strlen('addon-.xml'));
								if (XenForo_Application::$versionId >= 1020000) {
									if (empty($allAddOns[$addOnId])) {
										continue;
									}
								}
								if (filemtime(
									XenForo_Application::getInstance()->getRootDir() . '/install/data/' . $entry) >
									 $lastXMLUploadDate) {
									$addOns[] = $addOnId;
								}
							}
						}
					}
				} catch (Exception $e) {
					// do nothing
				}

				$addOnsNeedUpgrading = $this->_checkAddOnsNeedUpgrading($addOns);
				$controllerName = self::$_controller->getRequest()->getParam('_controllerName');
				$action = self::$_controller->getRequest()->getParam('_action');
				if ($controllerName == 'XenForo_ControllerAdmin_AddOn' && $action == 'UpgradeAllFromXml') {
					$this->_upgradeAddOns();
				}
			}
		}

		$this->_run();
	}

	/**
	 * Method designed to be overridden by child classes to add run behaviours.
	 */
	protected function _run()
	{
	}

	/**
	 *
	 * @param string $addOnId
	 * @param string|null $codeEvent
	 */
	public static function isAddOnEnabled($addOnId, $codeEvent = '')
	{
		if (XenForo_Application::$versionId >= 1020000) {
			return array_key_exists($addOnId, XenForo_Application::get('addOns'));
		}

		if (!$codeEvent) {
			$codeEvents = array(
				'container_admin_params',
				'container_public_params',
				'controller_post_dispatch',
				'controller_pre_dispatch',
				'criteria_page',
				'criteria_user',
				'file_health_check',
				'front_controller_post_view',
				'front_controller_pre_dispatch',
				'front_controller_pre_route',
				'front_controller_pre_view',
				'init_dependencies',
				'init_router_public',
				'load_class',
				'load_class_bb_code',
				'load_class_controller',
				'load_class_datawriter',
				'load_class_importer',
				'load_class_mail',
				'load_class_model',
				'load_class_route_prefix',
				'load_class_search_data',
				'load_class_view',
				'navigation_tabs',
				'option_captcha_render',
				'search_source_create',
				'template_create',
				'template_file_change',
				'template_hook',
				'template_post_render',
				'visitor_setup'
			);
		} else {
			$codeEvents = array(
				$codeEvent
			);
		}

		foreach ($codeEvents as $codeEvent) {
			$allListeners = XenForo_CodeEvent::getEventListeners($codeEvent);
			if (!empty($allListeners)) {
				foreach ($allListeners as $listeners) {
					if (XenForo_Application::$versionId < 1020000) {
						$listeners = array(
							'_' => $listeners
						);
					}
					foreach ($listeners as $callback) {
						if (strlen($callback[0]) > strlen($addOnId) &&
							 substr($callback[0], 0, strlen($addOnId)) == $addOnId) {
							return true;
						}
					}
				}
			}
		}
		return false;
	}

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
	 *
	 * @param array $addOns
	 */
	protected function _checkAddOnsNeedUpgrading(array $addOns, $checkDisabled = false)
	{
		if (empty($addOns)) {
			return false;
		}

		if (XenForo_Application::$versionId < 1020000 || $checkDisabled) {
			/* @var $addOnModel XenForo_Model_AddOn */
			$addOnModel = XenForo_Model::create('XenForo_Model_AddOn');

			$allAddOns = $addOnModel->getAllAddOns();
		} else {
			$allAddOns = XenForo_Application::get('addOns');
		}

		$lastXmlUploadDate = $this->_getLastXmlUploadDate();
		foreach ($addOns as $addOnId) {
			if (isset($allAddOns[$addOnId])) {
				$addOn = $allAddOns[$addOnId];

				try {
					$addOnXML = new SimpleXMLElement(
						file_get_contents(
							XenForo_Application::getInstance()->getRootDir() . '/install/data/addon-' . $addOnId . '.xml'));
					$versionId = (string) $addOnXML->attributes()->version_id;
				} catch (Exception $e) {
					$versionId = '';
				}
				if ((is_array($addOn) && $versionId > $addOn['version_id']) || (!is_array($addOn) && $versionId > $addOn)) {
					self::$_upgradeAddOns[$addOnId] = $addOn;
				} else {
					$xmlUploadDate = filemtime(
						XenForo_Application::getInstance()->getRootDir() . '/install/data/addon-' . $addOnId . '.xml');
					if ($xmlUploadDate > $lastXmlUploadDate) {
						$lastXmlUploadDate = $xmlUploadDate;
					}
				}
			}
		}

		eval(
			'
			class ThemeHouse_Listener_ControllerPreDispatch_TemplatePostRender
			{
				public static function templatePostRender($templateName, &$content, array &$containerData, XenForo_Template_Abstract $template)
				{
					if ($templateName == "PAGE_CONTAINER") {
						$upgradeAddOns = ThemeHouse_Listener_ControllerPreDispatch::getUpgradeAddOns();
						if (!empty($upgradeAddOns)) {
							$params = $template->getParams();
							if (!$params[\'showUpgradePendingNotice\']) {
								$pattern = \'#<noscript><p class="importantMessage">.*</p></noscript>#U\';
								$replacement = \'<p class="importantMessage"><a href="\'.XenForo_Link::buildAdminLink(\'add-ons/upgrade-all-from-xml\').\'">\' . new XenForo_Phrase(\'upgrade_add_on\') . \'</a></p>\';
								$content = preg_replace($pattern, \'${1}\' . $replacement, $content);
							}
						}
					}
				}
			}');
		$tprListeners = XenForo_CodeEvent::getEventListeners('template_post_render');
		if (!$tprListeners || XenForo_Application::$versionId >= 1020052) {
			$tprListeners = array();
		}
		$newListener = array(
			'ThemeHouse_Listener_ControllerPreDispatch_TemplatePostRender',
			'templatePostRender'
		);
		if (XenForo_Application::$versionId < 1020000) {
			$tprListeners[] = $newListener;
		} else {
			$tprListeners['_'][] = $newListener;
		}
		XenForo_CodeEvent::setListeners(array(
			'template_post_render' => $tprListeners
		));

		if (empty(self::$_upgradeAddOns)) {
			$this->_setLastXmlUploadDate($lastXmlUploadDate);
			return false;
		}

		return true;
	}

	public static function getUpgradeAddOns($getDisabled = false)
	{
		return self::$_upgradeAddOns;
	}

	/**
	 *
	 * @param string $action
	 */
	protected function _upgradeAddOns()
	{
		$template = new XenForo_Template_Admin('PAGE_CONTAINER_SIMPLE',
			array(
				'jQuerySource' => XenForo_Dependencies_Abstract::getJquerySource(),
				'xenOptions' => XenForo_Application::get('options')->getOptions(),
				'_styleModifiedDate' => XenForo_Application::get('adminStyleModifiedDate')
			));
		$template->setLanguageId(1);

		$template->setParam('title', 'Upgrading Add-ons...');
		$addOns = array_keys(self::getUpgradeAddOns(true));

		$addOnModel = XenForo_Model::create('XenForo_Model_AddOn');
		$nextAddOnId = '';
		if (count($addOns)) {
			$next = self::$_controller->getInput()->filterSingle('next', XenForo_Input::STRING);
			if ($next) {
				$addOn = $next;
			} else {
				$addOn = reset($addOns);
			}
			for ($i = 0; $i < count($addOns); $i++) {
				if ($addOns[$i] != $addOn) {
					unset($addOns[$i]);
					continue;
				}
				break;
			}
			$fileName = XenForo_Application::getInstance()->getRootDir() . '/install/data/addon-' . $addOn . '.xml';
			try {
				$caches = $addOnModel->installAddOnXmlFromFile($fileName, $addOn);
				$template->setParam('contents',
					'<form action="' . XenForo_Link::buildAdminLink('add-ons/upgrade-all-from-xml') . '" class="xenForm formOverlay CacheRebuild" method="post">
					<p id="ProgressText">Upgrading... <span class="RebuildMessage"></span> <span class="DetailedMessage"></span></p>
					<p id="ErrorText" style="display: none">' .
						 new XenForo_Phrase('error_occurred_or_request_stopped') . '</p>
					<input type="submit" class="button" value="Continue Upgrading" />
					<input type="hidden" name="_xfToken" value="' .
						 XenForo_Visitor::getInstance()->get('csrf_token_page') . '" />
					</form>');
			} catch (Exception $e) {
				if (count($addOns) == 1) {
					$template->setParam('contents',
						'Upgrade error (' . $addOn . '). Please use the <a href="' . XenForo_Link::buildAdminLink(
							'add-ons/upgrade',
							array(
								'addon_id' => $addOn
							)) . '">standard upgrade tool</a> and report any error messages to the developer.');
				} else {
					unset($addOns[array_search($addOn, $addOns)]);
					$nextAddOnId = reset($addOns);
					$template->setParam('contents',
						'<form action="' . XenForo_Link::buildAdminLink('add-ons/upgrade-all-from-xml') . '" class="xenForm formOverlay CacheRebuild" method="post">
						<p id="ProgressText">Upgrading... <span class="RebuildMessage"></span> <span class="DetailedMessage"></span></p>
						<p id="ErrorText" style="display: none">' .
							 new XenForo_Phrase('error_occurred_or_request_stopped') . '</p>
						<input type="submit" class="button" value="Continue Upgrading" />
						<input type="hidden" name="next" value="' . $nextAddOnId . '" />
						<input type="hidden" name="_xfToken" value="' .
							 XenForo_Visitor::getInstance()->get('csrf_token_page') . '" />
						</form>');
				}
			}
		} else {
			$caches = $addOnModel->rebuildAddOnCaches();
		}

		if (!count($addOns) && (isset($caches) || XenForo_Application::$versionId > 1020000)) {
			if (self::$_controller->getRouteMatch()->getResponseType() == 'json') {
				header('Content-Type: application/json; charset=UTF-8');
				echo json_encode(
					array(
						'_redirectTarget' => XenForo_Link::buildAdminLink('index')
					));
			} else {
				header('Location: ' . XenForo_Link::buildAdminLink('index'));
			}
		} elseif (count($addOns) == 1 && (isset($caches) || XenForo_Application::$versionId > 1020000)) {
			if (XenForo_Application::$versionId > 1020000) {
				$url = XenForo_Link::buildAdminLink('tools/run-deferred');
			} else {
				$url = XenForo_Link::buildAdminLink('tools/cache-rebuild', null,
					array(
						'caches' => json_encode($caches)
					));
			}
			if (self::$_controller->getRouteMatch()->getResponseType() == 'json') {
				header('Content-Type: application/json; charset=UTF-8');
				echo json_encode(array(
					'_redirectTarget' => $url
				));
			} else {
				header('Location: ' . $url);
			}
		} else {
			if (self::$_controller->getRouteMatch()->getResponseType() == 'json') {
				echo json_encode(
					array(
						'_redirectTarget' => XenForo_Link::buildAdminLink('add-ons/upgrade-all-from-xml', array(),
							array(
								'next' => $nextAddOnId
							))
					));
			} else {
				$output = $template->render();
				$output = str_replace("<!--XenForo_Require:JS-->",
					'<script src="js/xenforo/cache_rebuild.js"></script>', $output);
				echo $output;
			}
		}
		exit();
	}

	protected function _getLastXmlUploadDate()
	{
		return XenForo_Application::getSimpleCacheData(self::LAST_XML_UPLOAD_DATE_SIMPLE_CACHE_KEY);
	}

	/**
	 *
	 * @param integer $lastXmlUploadDate
	 */
	protected function _setLastXmlUploadDate($lastXmlUploadDate)
	{
		$oldLastXmlUploadDate = $this->_getLastXmlUploadDate();
		if ($lastXmlUploadDate > $oldLastXmlUploadDate) {
			XenForo_Application::setSimpleCacheData(self::LAST_XML_UPLOAD_DATE_SIMPLE_CACHE_KEY, $lastXmlUploadDate);
		}
	}

	/**
	 * Factory method to get the named controller pre-dispatch listener.
	 * The class must exist or be autoloadable or an exception will be thrown.
	 *
	 * @param XenForo_Controller $controller
	 * @param array $action
	 * @param string $controllerName since XenForo 1.2
	 *
	 * @return ThemeHouse_Listener_ControllerPreDispatch
	 */
	public static function create($className, XenForo_Controller $controller, $action, $controllerName = '')
	{
		$createClass = XenForo_Application::resolveDynamicClass($className, 'listener_th');
		if (!$createClass) {
			throw new XenForo_Exception("Invalid listener '$className' specified");
		}

		return new $createClass($controller, $action, $controllerName);
	}

	/**
	 *
	 * @param XenForo_Controller $controller
	 * @param array $action
	 * @param string $controllerName since XenForo 1.2
	 *
	 * @return array
	 */
	public static function createAndRun($className, XenForo_Controller $controller, $action, $controllerName = '')
	{
		$createClass = self::create($className, $controller, $action, $controllerName);

		if (XenForo_Application::debugMode()) {
			$createClass->run();
		}
		try {
			$createClass->run();
		} catch (Exception $e) {
			return;
		}
	}

	/**
	 * Leaving this here just for backwards compatibility :)
	 *
	 * @param $addOnId
	 * @return bool
	 */
	public static function isAddOnPremium($addOnId)
	{
		return true;
	}
}