<?
use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);
$MODULE_ID = 'imarket.catalog_app';

if ($USER->IsAdmin())
{
	return array(
			array(
				"parent_menu" => "global_menu_settings",
				"section" => "settings",
				"sort" => 10,
				"text" => Loc::getMessage($MODULE_ID."_MENU_TITLE"),
				"url" => $MODULE_ID."_app.php?lang=".LANGUAGE_ID,
				"icon" => $MODULE_ID."_menu_icon",
				"page_icon" => $MODULE_ID."_page_icon",
				"more_url" => array(),
				"items_id" => "menu_".$MODULE_ID,
				"items" => array()
			)
		);
} else {
	return false;
}
