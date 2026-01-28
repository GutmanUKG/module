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


class UpdateCatalogPropertiesName {
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

    private $arCAUnits = [];

    private $catalogAppProfiles = []; // профили ЦО каталог апп для обработки
    private $propPrefix = '';

    public function __construct () {
        $this->Logger = new ImarketLogger("/upload/log/UpdateCatalogPropertiesName/");
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

        $this->GetUnits();
        $this->updateCategoryPropertiesName();

        $this->Logger->log("LOG", "Обработка закончена");

        $this->EndDebugTime(__FUNCTION__);
    }

    private function GetUnits() {
        $this->Logger->log("LOG", "Получение велечин измерения");
        $this->StartDebugTime(__FUNCTION__);

        $arCAUnits = $this->restAPI->GetPropertyUnit();

        $arExistUnits = [];
        $sql = "SELECT * FROM catalog_app_units";
        $res = $this->connection->query($sql);
        while ($arItem = $res->fetch()) {
            $arExistUnits[$arItem["UNIT"]] = $arItem;
        }

        foreach ($arCAUnits as $code => $item) {
            if (empty($arExistUnits[$code])) {
                $sql = "INSERT INTO catalog_app_units (`UNIT`, `UNIT_FULL_NAME`) VALUES 
                ('{$code}', '{$item}')";
                $this->connection->query($sql);

                $this->arCAUnits[$code] = [
                    "CODE" => $code,
                    "NAME" => $item,
                    "SHORT_NAME" => "",
                    "DECLENSIONS" => ""
                ];
            } else {
                $this->arCAUnits[$code] = [
                    "CODE" => $code,
                    "NAME" => $arExistUnits[$code]["UNIT_FULL_NAME"],
                    "SHORT_NAME" => $arExistUnits[$code]["UNIT_SHORT_NAME"],
                    "DECLENSIONS" => $arExistUnits[$code]["UNIT_DECLENSIONS"],
                ];
            }
        }

        $this->Logger->log("LOG", "Получено данных о велиничинах ".count($this->arCAUnits));
        $this->EndDebugTime(__FUNCTION__);
    }

    private function updateCategoryPropertiesName(): void
    {
        $this->Logger->log("LOG", "Обновление названий свойств");
        $this->StartDebugTime(__FUNCTION__);

        $sql = "SELECT ID, NAME, CODE FROM b_iblock_property WHERE IBLOCK_ID = {$this->catalogIblockId}";
        $res = $this->connection->query($sql);
        while($arItem = $res->fetch()) {
            $siteProperties[$arItem["CODE"]] = $arItem;
        }

        $catalogAppSections = $this->restAPI->GetCategories();
        foreach ($catalogAppSections as $catalogAppSection) {
            $catalogAppSection["ID"] = $catalogAppSection["id"];
            $catalogAppSection["NAME"] = $catalogAppSection["name"];

            $CASectionId = $catalogAppSection["ID"];

            $this->Logger->log("LOG", "Обрабатываем раздел {$catalogAppSection["NAME"]}");
            $catalogAppSectionInfo = $this->restAPI->getCategoryById($CASectionId);

            foreach ($catalogAppSectionInfo["properties"] as $arProp) {
                $catalogAppSection["PROPERTIES"][$arProp["id"]] = $arProp;
            }

            if (!empty($catalogAppSection["PROPERTIES"])) {
                foreach ($catalogAppSection["PROPERTIES"] as $CAProperty) {
                    $this->Logger->log("LOG", "Проверяем свойство '{$CAProperty["name"]}'");
                    $propCode = $this->getProperyCode($CAProperty);

                    if (empty($propCode)) {
                        $this->Logger->log("LOG", "Нет кода свойства!");
                        continue;
                    }

                    switch($CAProperty["type"]) {
                        case "Integer":
                        case "Decimal":
                            if (!empty($this->arCAUnits[$CAProperty["unit"]]["SHORT_NAME"])) {
                                $newName = $CAProperty["name"] . ", ".$this->arCAUnits[$CAProperty["unit"]]["SHORT_NAME"];

                                $sql = "UPDATE b_iblock_property SET `NAME` = '{$newName}' WHERE CODE = '{$siteProperties[$propCode]['CODE']}'";
                                $this->Logger->log("LOG", print_r([$siteProperties[$propCode], $sql], true));
                                $this->connection->query($sql);
                            }

                            break;
                        default:
                            break;
                    }
                }
            }
        }

        $this->Logger->log("LOG", "Проверка сопоставлений закончена");
        $this->EndDebugTime(__FUNCTION__);
    }

    private function getProperyCode($property) {
        $code = $this->propPrefix.translitProperty($property["name"]);
        $code = strtoupper($code);
        $code .= "_".$property["id"];

        return $code;
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

(new UpdateCatalogPropertiesName())->StartWorker();