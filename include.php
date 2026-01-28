<?
use Bitrix\Main\Localization\Loc;
Loc::loadMessages(__FILE__);

\Bitrix\Main\Loader::registerAutoLoadClasses(
    "imarket.catalog_app",
    array(
        "CatalogAppAgents" => "classes/CatalogAppAgents.php",
        "DeliveryTimeService" => "services/DeliveryTimeService.php",
        "DeliveryEventHandler" => "classes/DeliveryEventHandler.php",
    ));

// Регистрация обработчиков событий доставки
DeliveryEventHandler::register();
?>
