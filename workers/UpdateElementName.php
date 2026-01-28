<?
/**
 * обработчик для создания новых товаров, создает болванки товаров,
 * что бы парсера заполняли нужные поля, цены, доступность и прочее обновлвяется в обработчике UpdateWorker
 *
 * Запуск на кроне раз в час
 */

if (empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = realpath(dirname(__FILE__) . '/../../../..');
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use ImarketHeplers\ImarketLogger,
    Bitrix\Main\Application,
    Bitrix\Main\Config\Option;

if (!class_exists("ImarketHeplers\ImarketLogger")) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/ImarketLogger.php');
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/RestAPI.php');
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/ImarketSectionsConnections.php');
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/workers/WorkersChecker.php');
ini_set('memory_limit', '25600M'); // много кушает оперативки!!!!
set_time_limit(0);


class UpdateElementName {
    private $Logger; // класс для логирования
    private $connection; // подключение к БД
    private $restAPI; // класс api rest-а
    public $debugData = []; // данные для дебага
    private $workersChecker; // класс для работы с обработчиками
    private $workerData = []; // данные об обработчиках
    private $workerId = 'create'; // id обработчика в табице worker_busy
    private $arModels = []; // товары из catalogApp
    private $arCatalogDataByXML_ID = []; // весь каталог сайта, где ключ xml_id элемента
    private $arCatalogDataById = []; // весь каталог сайта, где ключ id элемента
    private $arCatalogsDiff = []; // массив с товарами, которые необходимо добавить в каталог сайта
    private $arVendorsIds = []; // массив id брендов в catalogApp, которые нужно получить, формируются в CompareCatalogs
    private $arCategoriesIds = []; // массив id разделов в catalogApp, которые нужно получить, формируются в CompareCatalogs
    private $arVendors = []; // массив брендов из catalogApp
    private $arSiteVendors = []; // массив брендов сайта
    private $arSiteVendorsByXmlId = []; // массив брендов сайта по ключу xml_id
    private $arCategories = []; // массив разделов из catalogApp
    private $arSiteCategories = []; // массив разделов сайта
    private $arSiteCategoriesByXmlId = []; // массив разделов сайта, ключ внешний код
    private $sectionConnections = []; // сопоставление разделов catalogApp и сайта, ключ - id catalogApp
    private $catalogIblockId = 0; // id инфоблока каталога из настроек модуля
    private $endSections = []; // id конечных разделов
    private $arNeedToAddSections = []; // id разделов catalog.app в которые нужно дбавлять товары
    private $bs = null;

    private $catalogAppProfiles = []; // профили ЦО каталог апп для обработки

    public function __construct () {
        $this->Logger = new ImarketLogger("/upload/log/UpdateElementName/");
        $this->connection = Application::getConnection();
        $this->restAPI = new RestAPI();
        $this->workersChecker = new WorkersChecker();
        CModule::IncludeModule("iblock");

        $arResult["SETTINGS"]["CATALOG_APP_CATALOG_ID"] = Option::get("imarket.catalog_app", "CATALOG_APP_CATALOG_ID");
        $arResult["SETTINGS"]["CATALOG_IBLOCK_ID"] = Option::get("imarket.catalog_app", "CATALOG_IBLOCK_ID");

        if (!empty($arResult["SETTINGS"]["CATALOG_IBLOCK_ID"])) {
            $this->catalogIblockId = $arResult["SETTINGS"]["CATALOG_IBLOCK_ID"];
        }

        $this->bs = new CIBlockSection;
    }

    /**
     * Старт работы обработчика
     *
     * @return bool
     * @throws \Bitrix\Main\Db\SqlQueryException
     * @throws \Bitrix\Main\SystemException
     */
    public function StartWorker() {
        $this->Logger->log("LOG", "Начало обработки");
        $this->StartDebugTime(__FUNCTION__);

        $catalog = [];
        $filter = ['IBLOCK_ID' => $this->catalogIblockId, "ID" => 274499];
        $select = ['ID', 'CODE', 'NAME', 'PROPERTY_CA_BRAND'];
        $dbl = CIBlockElement::GetList(["ID" => "ASC"], $filter, false, false, $select);
        while ($arItem = $dbl->Fetch()) {
            $catalog[] = $arItem;
        }

        $el = new CIBlockElement;

        if (!empty($catalog)) {
            foreach ($catalog as $item) {
                print_r($item);
                die();

                $newCode = str_replace('_', '-', $item['CODE']);

                if (!$el->Update($item["ID"], ['CODE' => $newCode])) {
                    $this->Logger->log("ERROR", "Ошибка при обновлении товара [{$item['ID']}] {$item['NAME']} \r\n".print_r($el->LAST_ERROR, true));
                } else {
                    $this->Logger->log("LOG", "Код товара [{$item['ID']}] {$item['NAME']} изменен");
                }
            }
        }

        $this->Logger->log("LOG", "Обработка закончена");

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * Дебаг, замер времени начало
     *
     * @param string $function
     */
    private function StartDebugTime($function = "") {
        if (empty($function)) {
            return;
        }

        $start = microtime(true);
        $this->debugData[$function] = ["Function" => $function, "Start" => $start];
    }

    /**
     * Дебаг, замер времени конец
     *
     * @param string $function
     */
    private function EndDebugTime($function = "") {
        if (empty($function)) {
            return;
        }

        $finish = microtime(true);
        $diff = $finish - $this->debugData[$function]["Start"];
        $diff = round($diff, 4);
        $this->debugData[$function]["Time"] = $diff;
    }
}

(new UpdateElementName())->StartWorker();