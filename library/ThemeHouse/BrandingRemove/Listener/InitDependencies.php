<?php
class ThemeHouse_BrandingRemove_Listener_InitDependencies
{
	/**
	 *
	 * @param array $matches
	 * @return string
	 */
	public static function removeCopyrightNotice(array $matches)
	{

		$copyrightModification = XenForo_Application::getSimpleCacheData(parent::COPYRIGHT_MODIFICATION_SIMPLE_CACHE_KEY);

		if ($copyrightModification < XenForo_Application::$time) {
			XenForo_Application::setSimpleCacheData(parent::COPYRIGHT_MODIFICATION_SIMPLE_CACHE_KEY,
				XenForo_Application::$time);
		}

		return $matches[0];
	}
}
