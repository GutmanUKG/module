<?
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
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.custom_admin/classes/propertiesHelper.php');

ini_set('memory_limit', '25600M'); // много кушает оперативки!!!!
set_time_limit(0);


class UpdateSectionFilters {
    private $Logger; // класс для логирования
    private $connection; // подключение к БД
    private $restAPI; // класс api rest-а
    public $debugData = []; // данные для дебага
    private $workersChecker; // класс для работы с обработчиками
    private $workerData = []; // данные об обработчиках
    private $workerId = 'update_properties'; // id обработчика в табице worker_busy
    private $arModels = []; // товары из catalogApp
    private $arModelsByXML_ID = []; // товары из catalogApp
    private $arCatalogDataByXML_ID = []; // весь каталог сайта, где ключ xml_id элемента
    private $arCASiteProperties = []; // массив с созданными свойствами только для catalog.app
    private $catalogIblockId = 0;
    private $arCASectionProperties = []; // свойства из catalog.app по разделам
    private $sectionConnections = []; // связь разделов catalog.app и сайта
    private $arCASettings = [];
    private $arCAUnits = []; // еденицы измерения из catalog.app
    private $propPrefix = "";
//    private $useDescriptionForPropData = true;


    public function __construct () {
        $this->Logger = new ImarketLogger("/upload/log/UpdateSectionFilters/");
        $this->connection = Application::getConnection();

        $this->restAPI = new RestAPI();
        $this->workersChecker = new WorkersChecker();
        CModule::IncludeModule("iblock");

        $this->arCASettings["CATALOG_APP_CATALOG_ID"] = Option::get("imarket.catalog_app", "CATALOG_APP_CATALOG_ID");
        $this->arCASettings["CATALOG_IBLOCK_ID"] = Option::get("imarket.catalog_app", "CATALOG_IBLOCK_ID");
        $this->arCASettings["AUTO_UPDATE_RULES"] = Option::get("imarket.catalog_app", "AUTO_UPDATE_RULES");
        $this->arCASettings["AUTO_UPDATE_RULES"] = unserialize($this->arCASettings["AUTO_UPDATE_RULES"]);

        if (!empty($this->arCASettings["CATALOG_IBLOCK_ID"])) {
            $this->catalogIblockId = $this->arCASettings["CATALOG_IBLOCK_ID"];
        }
    }

    public function StartWorker() {
        $this->Logger->log("LOG", "Начало обработки");
        $this->StartDebugTime(__FUNCTION__);

        // проверить статус обработчика
        if (!$this->CheckStatus()) {
            $this->Logger->log("LOG", "Не нужно обрабатывать");
//            return false;
        }

        // проставить статус
//        $this->UpdateStatus(1);

        // Проверяем сопоставления свойств к разделам в catalog.app и каталоге сайта
        $this->setSiteCategoryProperties();

        // обновить статус
        $this->UpdateStatus(0);

        $this->Logger->log("LOG", "Обработка закончена");

        $this->EndDebugTime(__FUNCTION__);
    }

    private function setSiteCategoryProperties(): void
    {
        $this->Logger->log("LOG", "Проверяем сопоставления свойств к разделам в catalog.app и каталоге сайта");
        $this->StartDebugTime(__FUNCTION__);

        $catalogAppSections = $this->restAPI->GetCategories();
        foreach ($catalogAppSections as $catalogAppSection) {
            $catalogAppSection["ID"] = $catalogAppSection["id"];
            $catalogAppSection["NAME"] = $catalogAppSection["name"];

            $CASectionId = $catalogAppSection["ID"];

            $this->Logger->log("LOG", "Обрабатываем раздел {$catalogAppSection["NAME"]}");

            if (empty($this->sectionConnections)) {
                $this->GetSectionsConnections();
            }

            $siteSectionId = $this->sectionConnections[$CASectionId];

            if ($siteSectionId != 2433) {
//                continue;
            }

            $catalogAppSectionInfo = $this->restAPI->getCategoryById($CASectionId, false);

            foreach ($catalogAppSectionInfo["properties"] as $arProp) {
                $catalogAppSection["PROPERTIES"][$arProp["id"]] = $arProp;
            }

            if (empty($siteSectionId)) {
                $this->Logger->log("ERROR", "Не нашли связь разделов для раздела каталог апп {$CASectionId}");
                continue;
            }

            $sql = "DELETE FROM b_iblock_section_property WHERE SECTION_ID = {$siteSectionId} AND IBLOCK_ID = {$this->catalogIblockId}";
            $this->connection->query($sql);

            $sql = "INSERT INTO b_iblock_section_property (`IBLOCK_ID`, `SECTION_ID`, `PROPERTY_ID`, `SMART_FILTER`, `DISPLAY_EXPANDED`) VALUES 
            ('".$this->catalogIblockId."', '".$siteSectionId."', '12444', 'Y', 'Y')";
            $this->connection->query($sql);

            if (!empty($catalogAppSection["PROPERTIES"])) {
                usort($catalogAppSection["PROPERTIES"], function ($a, $b) {
                    return ($a['order'] - $b['order']);
                });

                $this->Logger->log("LOG", "Привязанных свойств к разделу в catalog.app ".count($catalogAppSection["PROPERTIES"]));

                $iteration = 0;
                $siteProperty = [];
                foreach ($catalogAppSection["PROPERTIES"] as $CAProperty) {
                    $propCode = $this->getProperyCode($CAProperty);
                    $this->Logger->log("LOG", "Проверяем свойство '{$CAProperty["name"]} [{$propCode}]'");

                    if (empty($propCode)) {
                        $this->Logger->log("LOG", "Нет кода свойства!");
                        continue;
                    }

                    $sql = "SELECT ID, NAME, CODE FROM b_iblock_property WHERE CODE = '{$propCode}'";
                    $res = $this->connection->query($sql);
                    while($arItem = $res->fetch()) {
                        $siteProperty[$arItem["CODE"]] = $arItem;
                    }

                    if (!empty($siteProperty[$propCode])) {
                        $this->Logger->log("LOG", "Обновляем сортировку свойства {$CAProperty['name']} на {$CAProperty['order']}");
                        $sql = "UPDATE b_iblock_property SET SORT='{$CAProperty['order']}' WHERE ID = {$siteProperty[$propCode]["ID"]}";
                        $this->connection->query($sql);

                        if ($iteration < 9) {
                            $sql = "INSERT INTO b_iblock_section_property (`IBLOCK_ID`, `SECTION_ID`, `PROPERTY_ID`, `SMART_FILTER`, `DISPLAY_EXPANDED`) VALUES 
                            ('".$this->catalogIblockId."', '".$siteSectionId."', {$siteProperty[$propCode]["ID"]}, 'Y', 'Y')";
                        } else if ($iteration >= 9) {
                            $sql = "INSERT INTO b_iblock_section_property (`IBLOCK_ID`, `SECTION_ID`, `PROPERTY_ID`, `SMART_FILTER`, `DISPLAY_EXPANDED`) VALUES 
                            ('".$this->catalogIblockId."', '".$siteSectionId."', {$siteProperty[$propCode]["ID"]}, 'Y', 'N')";
                        }

                        $this->Logger->log("LOG", $sql);
                        $this->connection->query($sql);

                        $iteration++;
                    } else {
                        $this->Logger->log("LOG", "Нет свойства {$CAProperty['name']} на сайте");
                    }
                }
            }

            $sql = "UPDATE b_iblock_property SET SORT = '-1' WHERE ID = 12444";
            $this->connection->query($sql);

            $sql = "UPDATE b_iblock_section_property SET 
                 `SMART_FILTER` = 'Y',
                 `DISPLAY_EXPANDED` = 'Y'
             WHERE 
                 SECTION_ID = {$siteSectionId} AND
                 PROPERTY_ID = 12444
                 AND IBLOCK_ID = {$this->catalogIblockId}";
            $this->connection->query($sql);

            $propertyHelper = new propertiesHelper();
            $filterExcludeParams = $propertyHelper->getFilterExcludeProperties();

            $excludeParamsId = array_values($filterExcludeParams);

            $sql = "UPDATE b_iblock_section_property SET 
                 `SMART_FILTER` = 'N'
             WHERE 
                 PROPERTY_ID IN (".implode(",", $excludeParamsId).")
                 AND IBLOCK_ID = {$this->catalogIblockId}";
            $this->connection->query($sql);
        }

        $this->Logger->log("LOG", "Проверка сопоставлений закончена");
        $this->EndDebugTime(__FUNCTION__);
    }

    private function GetSectionsConnections(): void
    {
        $this->Logger->log("LOG", "Получаем сопоставления разделов");
        $this->StartDebugTime(__FUNCTION__);

        $sConnect = new ImarketSectionsConnections();
        $this->sectionConnections = $sConnect->getAll();

        $this->Logger->log("LOG", "Данные получены, всего сопоставленных разделов ".count($this->sectionConnections));

        $this->EndDebugTime(__FUNCTION__);
    }

    private function getProperyCode($property): string
    {
        $code = $this->propPrefix.translitProperty($property["name"]);
        $code = strtoupper($code);
        $code .= "_".$property["id"];

        return $code;
    }

    /**
     * Получить статус обработчика
     *
     * @return bool
     */
    private function CheckStatus() {
        $this->StartDebugTime(__FUNCTION__);
        $this->workerData = $this->workersChecker->Check($this->workerId);
        $this->EndDebugTime(__FUNCTION__);

        if ($this->workerData[$this->workerId]["BUSY"] == 1/* || !$this->workerData[$this->workerId]["NEED_START"]*/) {
            return false;
        }

        return true;
    }

    /**
     * Обновить статус обработчика
     * TODO возможно перенести в класс WorkersChecker
     * @param int $status
     * @throws \Bitrix\Main\Db\SqlQueryException
     */
    private function UpdateStatus($status = 0) {
        $this->StartDebugTime(__FUNCTION__);
        $this->workersChecker->UpdateWorkerStatus($this->workerId, $status);

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

(new UpdateSectionFilters())->StartWorker();