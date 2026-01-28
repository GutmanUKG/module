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


class CreateWorker {
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
        $this->Logger = new ImarketLogger("/upload/log/CreateWorker_debug/");
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

        // проверить статус обработчика
        if (!$this->CheckStatus() || Option::get("imarket.catalog_app", "CREATE_WORKER") != 1) {
            $this->Logger->log("LOG", "Не нужно обрабатывать");
//            return false;
        }

        // проставить статус
//        $this->UpdateStatus(1);
        // получить разделы из catalogApp
        $this->GetCategories();
        // получить разницу разделов
        $this->CheckSectionsDiff();
        die();
        // получаем разделы, в которые нужно добавлять товары
        $this->GetSectionsToAdd();


        $this->GetSiteCategories();
        $this->GetSectionsConnections();
        $this->UpdateSectionsConnections();

        if (!empty($this->arNeedToAddSections)) {
            // получить ЦО по которым нужно получить товары
//            $this->getSiteCOProfiles();

            // получить каталог из catalogApp
            $this->GetCatalogModels();

            // получить модель по профилям ЦО
//            $this->getProfileGoods();

            // получить товары сайта
            $this->GetCatalogGoods();
            // получить товары, которые нужно создать
            $this->CompareCatalogs();

            if (!empty($this->arCatalogsDiff)) {
                // получить разделы сайта
                $this->GetSiteCategories();
                // получить сопоставление разделов
                $this->GetSectionsConnections();
                // создать новые товары
                $this->CreateDiffGoods();
            } else {
                $this->Logger->log("LOG", "Нет товаров для добавления");
            }
        } else {
            $this->Logger->log("LOG", "Нет активных разделов для добавления");
        }

        // обновить статус
//        $this->UpdateStatus(0);

        // установить флаг на обработку свойств
//        $sql = "UPDATE catalog_app_workers SET NEED_START = '1' WHERE WORKER_ID = 'update_properties'";
//        $this->connection->query($sql);

        $this->Logger->log("LOG", "Обработка закончена");

        $this->EndDebugTime(__FUNCTION__);
    }

    private function getSiteCOProfiles()
    {
        $sql = "SELECT CATALOG_APP_ID FROM catalog_app_rules";
        $res = $this->connection->query($sql);
        while($arItem = $res->fetch()) {
            $this->catalogAppProfiles[] = $arItem['CATALOG_APP_ID'];
        }
    }

    private function getProfileGoods()
    {
        if (!empty($this->catalogAppProfiles)) {
            foreach ($this->catalogAppProfiles as $k => $profileId) {
                $profileModels = $this->restAPI->GetProfilePrices([$profileId => $k], true);

                foreach ($profileModels[$k] as $model) {
                    $this->arModels[] = $model['model'];
                }
            }
        }
    }

    private function GetSectionsToAdd() {
        $this->Logger->log("LOG", "Получаем разделы, в которые необходимо добавить товары");
        $this->StartDebugTime(__FUNCTION__);

        $sql = "SELECT CATALOG_APP_SECTION_ID FROM catalog_app_section_connections WHERE NEED_GET = 1";
        $res = $this->connection->query($sql);
        while ($arItem = $res->fetch()) {
            $this->arNeedToAddSections[] = $arItem["CATALOG_APP_SECTION_ID"];
        }

        $this->Logger->log("LOG", "Всего разделов для добавления ".count($this->arNeedToAddSections));
        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * получить все модели из catalogApp
     */
    private function GetCatalogModels() {
        $this->Logger->log("LOG", "Получение моделей каталога");
        $this->StartDebugTime(__FUNCTION__);

        $ModelFile = $_SERVER["DOCUMENT_ROOT"] . "/upload/CatalogAppModels.txt";

        /*if (file_exists($ModelFile) && (time() - filemtime($ModelFile)) > 86400) {
            $models = file_get_contents($ModelFile);
            $this->arModels = unserialize($models);
        } else {
            $this->arModels = $this->restAPI->GetModels();
            $this->arModels = array_reverse($this->arModels, true);

            file_put_contents($ModelFile, serialize($this->arModels));
        }*/

        $this->arModels = $this->restAPI->GetModels();
        $this->arModels = array_reverse($this->arModels, true);

        $this->Logger->log("LOG", "Всего получено моделей ".count($this->arModels));

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * Получаем все товары каталога, ключ xml_id
     *
     */
    private function GetCatalogGoods() {
        $this->Logger->log("LOG", "Получаем товары каталога");
        $this->StartDebugTime(__FUNCTION__);

        $filter = ['IBLOCK_ID' => $this->catalogIblockId];
        $select = ['ID', 'NAME', 'XML_ID'];
        $dbl = CIBlockElement::GetList(["ID" => "ASC"], $filter, false, false, $select);
        while ($arItem = $dbl->Fetch()) {
            if(!empty($arItem["XML_ID"])) {
                $this->arCatalogDataByXML_ID[$arItem["XML_ID"]] = $arItem;
            }

            $this->arCatalogDataById[$arItem["ID"]] = $arItem;
        }

        $this->Logger->log("LOG", "Получено, всего товаров: ".count($this->arCatalogDataByXML_ID));
        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * получить товары, которые нужно добавить
     */
    private function CompareCatalogs() {
        $this->StartDebugTime(__FUNCTION__);
        $this->Logger->log("LOG", "Сравниваем каталог catalogApp с каталогом сайта");

        foreach ($this->arModels as $k => $arItem) {
            if (empty($arItem["externalId"])) {
//                $this->Logger->log("ERROR", "Нет внешнего кода товара [{$arItem['id']}] {$arItem['name']}");
//                continue;

                $arItem["externalId"] = $arItem["id"];
            }

            // товара нет в каталоге сайта
            if (empty($this->arCatalogDataByXML_ID[$arItem["externalId"]]) && in_array($arItem["category"]["id"], $this->arNeedToAddSections)) {
                $this->arCatalogsDiff[$arItem["externalId"]] = $arItem;

                if (!in_array($arItem["vendor"]["id"], $this->arVendorsIds)) {
                    $this->arVendorsIds[] = $arItem["vendor"]["id"];
                }

                if (!in_array($arItem["category"]["id"], $this->arCategoriesIds)) {
                    $this->arCategoriesIds[] = $arItem["category"]["id"];
                }
            }
        }

        $this->Logger->log("LOG", "Нужно добавить ".count($this->arCatalogsDiff)." товаров");
        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * Получить бренды из catalogApp
     */
    private function GetVendors() {
        $this->Logger->log("LOG", "Получаем бредны товаров из catalogApp");
        $this->StartDebugTime(__FUNCTION__);

        $arVendors = $this->restAPI->GetVendors();

        foreach ($arVendors as $arVendor) {
            $this->arVendors[$arVendor["id"]] = $arVendor;
        }

        $this->Logger->log("LOG", "Всего брендов ".count($this->arVendors));

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * получить бренды сайта
     * @throws \Bitrix\Main\Db\SqlQueryException
     */
    private function GetSiteVendorList () {
        $this->Logger->log("LOG", "Получение всех брендов на сайте");
        $this->StartDebugTime(__FUNCTION__);
        $sql = 'SELECT ID, VALUE, XML_ID FROM b_iblock_property_enum WHERE PROPERTY_ID = 57893';
        $dbl = $this->connection->query($sql);
        while ($arItem = $dbl->fetch()) {
            $arItem["LOVER_NAME"] = strtolower($arItem["VALUE"]);
            $this->arSiteVendors[$arItem["LOVER_NAME"]] = $arItem;
            $this->arSiteVendorsByXmlId[$arItem["XML_ID"]] = $arItem;
        }

        $this->Logger->log("LOG", "Всего получено ".count($this->arSiteVendors));
        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * получить разделы из catalogApp
     */
    private function GetCategories() {
        $this->Logger->log("LOG", "Получаем разделы товаров из catalogApp");
        $this->StartDebugTime(__FUNCTION__);

        $arCategories = $this->restAPI->GetCategories();

        foreach ($arCategories as $arCategory) {
            $this->arCategories[$arCategory["id"]] = $arCategory;
        }

        $this->Logger->log("LOG", "Всего разделов ".count($this->arCategories));

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * получить разделы сайта
     */
    private function GetSiteCategories() {
        $this->Logger->log("LOG", "Получаем разделы сайта");
        $this->StartDebugTime(__FUNCTION__);

        $this->arSiteCategories = [];
        $this->arSiteCategoriesByXmlId = [];

        $arFilter = array('IBLOCK_ID' => $this->catalogIblockId);
        $rsSect = CIBlockSection::GetList(array('left_margin' => 'asc'), $arFilter, false, ["ID", "NAME", "XML_ID", "IBLOCK_SECTION_ID", "DEPTH_LEVEL"]);
        while ($arSect = $rsSect->GetNext()) {
            $this->arSiteCategories[$arSect["ID"]] = $arSect;
            $this->arSiteCategoriesByXmlId[$arSect["XML_ID"]] = $arSect;
        }

        $this->Logger->log("LOG", "Всего получено ".count($this->arSiteCategories));

        $this->EndDebugTime(__FUNCTION__);
    }

    private function CheckSectionsDiff () {
        $this->Logger->log("LOG", "Проверка разделов");
        $this->StartDebugTime(__FUNCTION__);

        $this->GetSiteCategories();
        $counter = 0;
        $arTree = [];

        if (!empty($this->arCategories)) {
            /*foreach ($this->arCategories as $id => $arItem) {
                $this->Logger->log("LOG", 'Проверка раздела '.$arItem['name']);
                $needToAdd = false;

                if (!empty($this->arSiteCategories[$arItem['externalId']]) && empty($this->arSiteCategoriesByXmlId[$arItem['externalId']])) {
                    $this->Logger->log("LOG", 'Обновляем внешний код для раздела '.$arItem['name']);
                    $this->UpdateSectionXmlId($this->arSiteCategories[$arItem['externalId']]['ID'], $arItem['externalId']);

                    $this->arSiteCategoriesByXmlId[$arItem['externalId']] = $this->arSiteCategories[$arItem['externalId']];
                }

                if (empty($this->arSiteCategoriesByXmlId[$arItem['externalId']])) {
                    $needToAdd = true;
                }

                if ($needToAdd) {
                    $this->CreateSection($id);
                    $counter++;
                } else {
                    $this->Logger->log("LOG", 'Раздел '.$arItem['name'].' есть на сайте');
                }
            }*/

            // обновление структуры каталога на сайте
            foreach ($this->arCategories as $id => $arItem) {
                if (!empty($this->arSiteCategoriesByXmlId[$arItem['externalId']])) {
                    if (!empty($arItem["parentId"])) {
                        $this->UpdateParensSection($id, $arItem["parentId"]);
                    } else {
                        $this->UpdateParensSection($id, 0);
                    }
                }
            }

            // удаление разделов на сайте
            /*if (count($this->arCategories) < count($this->arSiteCategoriesByXmlId)) {
                foreach ($this->arSiteCategoriesByXmlId as $id => $arItem) {
                    if (empty($this->arCategories[$id])) {
                        $this->Logger->log("LOG", "Нужно удалить раздел ".$arItem["NAME"]);
                        $this->DeleteSiteSection($id);
                    }
                }
            }*/

//            $this->UpdateSectionsConnections();
        } else {
            $this->Logger->log("LOG", "Нет разделов!");
        }

        $this->Logger->log("LOG", "Добавлено разделов ".$counter);
        $this->EndDebugTime(__FUNCTION__);
    }

    private function UpdateSectionXmlId(int $sectionId = 0, string $xmlId = ''): void
    {
        if (empty($sectionId)) {
            return;
        }

        $sql = "UPDATE b_iblock_section SET XML_ID = '".$xmlId."' WHERE ID = '".$sectionId."'";
        $this->connection->query($sql);
    }

    private function UpdateParensSection ($sectionId = 0, $parentSectionId = 0) {
        $this->Logger->log("LOG", "Обновляем привязку раздела ".$this->arCategories[$sectionId]["name"]. " к ".$this->arCategories[$parentSectionId]["name"]);
        $this->StartDebugTime(__FUNCTION__);

        if (empty($sectionId)) {
            $this->Logger->log("LOG", "Не указан id раздела!");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        $catalogAppXmlId = $this->arCategories[$sectionId]['externalId'] ?? $sectionId;
        $catalogAppParentXmlId = $this->arCategories[$parentSectionId]['externalId'] ?? $parentSectionId;

        $siteSectionId = $this->arSiteCategoriesByXmlId[$catalogAppXmlId]["ID"];
        $siteParentSectionId = $this->arSiteCategoriesByXmlId[$catalogAppParentXmlId]["ID"] ?? 0;
        $currentParentSection = $this->arSiteCategoriesByXmlId[$catalogAppXmlId]["IBLOCK_SECTION_ID"];

        if (empty($parentSectionId)) {
            $arFields = Array("IBLOCK_SECTION_ID" => 0);
            $this->bs->Update($siteSectionId, $arFields);
        } elseif ($currentParentSection != $siteParentSectionId) {
            $arFields = Array("IBLOCK_SECTION_ID" => $siteParentSectionId);
            $this->bs->Update($siteSectionId, $arFields);
        } else {
            $this->Logger->log("LOG", "Раздел уже привязан");
        }

        $this->Logger->log("LOG", "Привязка раздела обновлена");
        $this->EndDebugTime(__FUNCTION__);
    }

    private function GetSubSections ($sectionId = 0) {
        $this->Logger->log("LOG", "Получаем подразделы для раздела ".$this->arCategories[$sectionId]["name"]."[$sectionId]");
        $this->StartDebugTime(__FUNCTION__);

        if (empty($sectionId)) {
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        $arSubs = [];
        $arParentIds = [];
        foreach ($this->arCategories as $id => $arItem) {
            if (!in_array($arItem["parentId"], $arParentIds)) {
                $arParentIds[] = $arItem["parentId"];
            }

            if ($arItem["parentId"] == $sectionId) {
                $children = $this->GetSubSections($id);
                if ($children) {
                    $arItem['subsections'] = $children;
                } else {
                    $this->endSections[] = $arItem;
                }

                $arSubs[$id] = $arItem;
            }
        }

        /*foreach ($this->arCategories as $id => $arItem) {
            if (!in_array($arItem["id"], $arParentIds)) {
//                $this->endSections[] = $arItem;
            }
        }*/

        $this->EndDebugTime(__FUNCTION__);

        return $arSubs;
    }

    private function CreateSection ($sectionId = 0)
    {
        $this->Logger->log("LOG", "Создаем раздел на сайте ".$this->arCategories[$sectionId]["name"]);
        $this->StartDebugTime(__FUNCTION__);
        $result = false;

        if (empty($sectionId)) {
            $this->Logger->log("LOG", "Нет id раздела");
            $this->EndDebugTime(__FUNCTION__);
            return;
        }

        $arSection = $this->arCategories[$sectionId];
        $parentSectionId = 0;

        $image = CFile::MakeFileArray($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/imarket.catalog_app/img/no-photo.png");
        $code = translit($arSection["name"]);
        $code = strtolower($code);
        $arFields = Array(
            "ACTIVE"            => "Y",
            "IBLOCK_SECTION_ID" => $parentSectionId,
            "IBLOCK_ID"         => $this->catalogIblockId,
            "NAME"              => $arSection["name"],
            "SORT"              => $arSection["id"],
            "XML_ID"            => $arSection["id"],
            "PICTURE"           => $image,
            "CODE"              => $code
        );

        if (!$sectionID = $this->bs->Add($arFields)) {
            $this->Logger->log("ERROR", "Ошибка при добавлении раздела ".$arSection["name"]."\r\n".$this->bs->LAST_ERROR."\r\n".print_r($arFields, true));
        } else {
            $result = true;
            $this->Logger->log("LOG", "Добалвен раздел ".$arSection["name"]);

            $arFilter = array('IBLOCK_ID' => $this->catalogIblockId, "XML_ID" => $arSection["id"]);
            $rsSect = CIBlockSection::GetList(array('left_margin' => 'asc'), $arFilter, false, ["ID", "NAME", "XML_ID", "IBLOCK_SECTION_ID", "DEPTH_LEVEL"]);
            while ($arSect = $rsSect->GetNext()) {
                $this->arSiteCategories[$arSect["ID"]] = $arSect;
                $this->arSiteCategoriesByXmlId[$arSect["XML_ID"]] = $arSect;
            }
        }

        $this->EndDebugTime(__FUNCTION__);

    }

    private function DeleteSiteSection ($sectionId = 0) {
        $this->Logger->log("LOG", "Удаление раздела ".$this->arSiteCategoriesByXmlId[$sectionId]["NAME"]);
        $this->StartDebugTime(__FUNCTION__);

        global $DB;
        $siteSectionId = $this->arSiteCategoriesByXmlId[$sectionId]["ID"];
        $DB->StartTransaction();
        if (!CIBlockSection::Delete($siteSectionId)) {
            $this->Logger->log("ERROR", "Ошибка при удалении раздела");
            $DB->Rollback();
        } else
            $DB->Commit();

        $sConnect = new ImarketSectionsConnections();
        $sConnect->deleteRows([$siteSectionId]);

        $this->EndDebugTime(__FUNCTION__);
    }

    private function UpdateSectionsConnections () {
        $this->Logger->log("LOG", "Обновления сопоставления разделов");
        $this->StartDebugTime(__FUNCTION__);

        $this->GetSectionsConnections();

        if (!empty($this->sectionConnections)) {
            if (!empty($this->arCategories)) {
                foreach ($this->arCategories as $id => $arItem) {
                    if (!empty($this->sectionConnections[$id])) {
                        $this->Logger->log("LOG", "Обновляем связь для раздела {$arItem["name"]}");
                        $connectedSection = $this->sectionConnections[$id];

                        if ($arItem["name"] != $connectedSection["CATALOG_APP_SECTION_NAME"] ||
                            $arItem["id"] != $connectedSection["CATALOG_APP_SECTION_ID"] ||
                            $this->arSiteCategoriesByXmlId[$arItem['externalId']]["NAME"] != $connectedSection["SITE_SECTION_NAME"] ||
                            $this->arSiteCategoriesByXmlId[$arItem['externalId']]["ID"] != $connectedSection["SITE_SECTION_ID"]
                        ) {
                            $sql = "UPDATE catalog_app_section_connections SET 
                            CATALOG_APP_SECTION_NAME = '".$arItem["name"]."', 
                            CATALOG_APP_SECTION_ID = '".$arItem["id"]."', 
                            SITE_SECTION_NAME = '".$this->arSiteCategoriesByXmlId[$arItem['externalId']]["NAME"]."', 
                            SITE_SECTION_ID = '".$this->arSiteCategoriesByXmlId[$arItem['externalId']]["ID"]."', 
                            SECTION_LEVEL = '".$this->arSiteCategoriesByXmlId[$arItem['externalId']]["DEPTH_LEVEL"]."'
                            WHERE ID = ".$connectedSection["ID"];
                            $this->connection->query($sql);
                            $this->Logger->log("LOG", "Связь для раздела {$arItem["name"]} обновлена");
                        }
                    } else {
                        $this->Logger->log("LOG", "Добавление новой связи для раздела {$arItem["name"]}");

                        $sql = "INSERT INTO catalog_app_section_connections 
                        (`CATALOG_APP_SECTION_NAME`, `CATALOG_APP_SECTION_ID`, `SITE_SECTION_NAME`, `SITE_SECTION_ID`, `SECTION_LEVEL`, `NEED_GET`) VALUES 
                        ('".$arItem["name"]."', '".$id."', '".$this->arSiteCategoriesByXmlId[$arItem['externalId']]["NAME"]."', '".$this->arSiteCategoriesByXmlId[$arItem['externalId']]["ID"]."', '".$this->arSiteCategoriesByXmlId[$arItem['externalId']]["DEPTH_LEVEL"]."', '1')";
                        $this->connection->query($sql);
                        $this->Logger->log("LOG", "Связь для раздела {$arItem["name"]} добавлена");
                    }
                }
            } else {
                $this->Logger->log("LOG", "Нет разделов!");
            }


            /*foreach ($this->sectionConnections as $catalogAppId => $arItem) {
                continue; // TODO исправить и расскомментить
                if (!empty($this->arCategories[$catalogAppId])) {
                    $CASection = $this->arCategories[$catalogAppId];

                    if ($CASection["name"] != $arItem["CATALOG_APP_SECTION_NAME"]) {
                        $sql = "UPDATE catalog_app_section_connections SET
                            CATALOG_APP_SECTION_NAME = '".$CASection["name"]."'
                            WHERE ID = ".$arItem["ID"];
                        $this->connection->query($sql);
                    }
                } else {
                    $sql = "INSERT INTO catalog_app_section_connections
                        (`CATALOG_APP_SECTION_NAME`, `CATALOG_APP_SECTION_ID`, `SITE_SECTION_NAME`, `SITE_SECTION_ID`) VALUES
                        ('".$arItem["name"]."', '".$catalogAppId."', '".$this->arSiteCategoriesByXmlId[$catalogAppId]["NAME"]."', '".$this->arSiteCategoriesByXmlId[$catalogAppId]["ID"]."')";
                    $this->connection->query($sql);
                }
            }*/
        } else {
            if (empty($this->arCategories)) {
                $this->Logger->log("LOG", "Нет разделов для сопоставления");
                $this->EndDebugTime(__FUNCTION__);
                return false;
            }

            foreach ($this->arCategories as $id => $arItem) {
                $sql = "INSERT INTO catalog_app_section_connections 
                        (`CATALOG_APP_SECTION_NAME`, `CATALOG_APP_SECTION_ID`, `SITE_SECTION_NAME`, `SITE_SECTION_ID`, `SECTION_LEVEL`, `NEED_GET`) VALUES 
                        ('".$arItem["name"]."', '".$arItem["id"]."', '".$this->arSiteCategories[$arItem["externalId"]]["NAME"]."', '".$this->arSiteCategories[$arItem["externalId"]]["ID"]."', '".$this->arSiteCategories[$arItem["externalId"]]["DEPTH_LEVEL"]."', '1')";
                $this->connection->query($sql);
            }

            /*if (empty($this->endSections)) {
                $this->Logger->log("LOG", "Нет конечных разделов");
                $this->EndDebugTime(__FUNCTION__);
                return false;
            }

            foreach ($this->endSections as $id => $arItem) {
                $sql = "INSERT INTO catalog_app_section_connections
                        (`CATALOG_APP_SECTION_NAME`, `CATALOG_APP_SECTION_ID`, `SITE_SECTION_NAME`, `SITE_SECTION_ID`) VALUES
                        ('".$arItem["name"]."', '".$arItem["id"]."', '".$this->arSiteCategoriesByXmlId[$arItem["id"]]["NAME"]."', '".$this->arSiteCategoriesByXmlId[$arItem["id"]]["ID"]."')";
                $this->connection->query($sql);
            }*/
        }

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * Получить товары со скидкой, нужно для сравнения какие товары отключить, а какие нет
     *
     * @throws \Bitrix\Main\Db\SqlQueryException
     * @throws \Bitrix\Main\SystemException
     */
    public function GetSectionsConnections() {
        $this->Logger->log("LOG", "Получаем сопоставления разделов");
        $this->StartDebugTime(__FUNCTION__);

        $sConnect = new ImarketSectionsConnections();
        $this->sectionConnections = $sConnect->getCatalogAppId();

        $this->Logger->log("LOG", "Данные получены, всего сопоставленных разделов ".count($this->sectionConnections));

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * создать товары на сайте
     *
     * @return bool
     * @throws \Bitrix\Main\Db\SqlQueryException
     */
    public function CreateDiffGoods() {
        $this->Logger->log("LOG", "Создание товаров на сайте");
        $this->StartDebugTime(__FUNCTION__);

        if (empty($this->arCatalogsDiff)) {
            $this->Logger->log("LOG", "Нет товаров для создания");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        $el = new CIBlockElement;
        $addedCount = 0;
        $translitParams = array("replace_space" => "-", "replace_other" => "-");

        foreach ($this->arCatalogsDiff as $arItem) {
            $sectionId = $this->sectionConnections[$arItem["category"]["id"]]["SITE_SECTION_ID"];

            if (!empty($this->arCategories[$arItem["category"]["id"]]["singularName"])) {
                $sectionName = $this->arCategories[$arItem["category"]["id"]]["singularName"];
            } else {
                $sectionName = $arItem["category"]["name"];
            }

//            $name = $sectionName." ".$arItem["name"];
            $name = $arItem["name"];

//            $vName = strtolower($arItem["vendor"]["name"]);
//            $name = trim($sectionName). " ".trim($arItem["vendor"]["name"])." ".trim($arItem["name"]);


//            if (!empty($arItem["color"])) {
//                $name = trim($name);
//                $name .= " ".trim($arItem["color"]);
//            }
//
//            if (!empty($arItem["article"]) && ($arItem["article"] != $arItem["name"])) {
//                $name = trim($name);
//                $name .= " [".trim($arItem["article"])."]";
//            }

            $name = str_replace(["&quot;", "&amp;quot;"], '"', $name);

            $catalogAppId = $this->restAPI->PrepareCatalogAppId($arItem["id"]);

//            $code = translit($name);
            $code = Cutil::translit($name,"ru", $translitParams);
            $code = str_replace("quot-", "", $code);
//            $code .= "-".$catalogAppId;
            $code = strtolower($code);
            $code = $this->prepareCode($code);

            $image = CFile::MakeFileArray($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/imarket.catalog_app/img/no-photo.png");

            $arLoadProductArray = [
                "IBLOCK_SECTION_ID" => $sectionId,
                "IBLOCK_ID"      => $this->catalogIblockId,
                "NAME"           => $name,
                "CODE"           => $code,
                "ACTIVE"         => "N",
                "XML_ID"         => $arItem["externalId"],
                "DETAIL_PICTURE" => $image,
                "PREVIEW_PICTURE" => $image
            ];

//            $this->Logger->log("LOG", "Добавление товара [".$arItem["id"]."] ".$name);
            $this->Logger->log("LOG", print_r($arLoadProductArray, true));

            if(!$itmeId = $el->Add($arLoadProductArray)) {
                $this->Logger->log("ERROR", "Товар не добавлен \r\n".print_r($el->LAST_ERROR, true));
            } else {
                if (!CCatalogProduct::GetByID($itmeId)) {
                    CCatalogProduct::Add(["ID" => $itmeId]);
                }
                $this->Logger->log("LOG", "Товар успешно добавлен");
                $addedCount++;
            }
        }

        $this->Logger->log("LOG", "Всего успешно добавлено ".$addedCount);

        $this->EndDebugTime(__FUNCTION__);
    }

    public function prepareCode($code, $iteration = 0)
    {
        if ($iteration > 0) {
            $code .= "_".$iteration;
        }

        $newCode = $code;
        $filter = ['IBLOCK_ID' => $this->catalogIblockId, 'CODE' => $code];
        $select = ['ID'];
        $dbl = CIBlockElement::GetList(["ID" => "ASC"], $filter, false, false, $select);
        if ($res = $dbl->Fetch()) {
            $newCode = $this->prepareCode($code, ($iteration + 1));
        }

        return $newCode;
    }

    /**
     * @return bool
     * @throws \Bitrix\Main\Db\SqlQueryException
     */
    public function CreateNewVendors () {
        $this->StartDebugTime(__FUNCTION__);
        $this->Logger->log("LOG", "Создание новых брендов");
        $translitParams = ['replace_space' => '-', 'replace_other' => '-'];

        $arNeedAddVendors = [];
        $vendorXmlToValue = [];
        foreach ($this->arSiteVendors as $siteVendorName => $arSiteVendor) {
            $vendorXmlToValue[$arSiteVendor['XML_ID']] = $arSiteVendor['VALUE'];
        }

        $arDiffVendors = [];
        foreach ($this->arCatalogsDiff as $arItem) {
            if (!in_array($arItem['vendor']['id'], $arDiffVendors)) {
                $arDiffVendors[] = $arItem['vendor']['id'];
            }
        }

        foreach ($arDiffVendors as $diffVendorId) {
            $CAVendor = $this->arVendors[$diffVendorId]["name"];
            $CAVendorL = strtolower($this->arVendors[$diffVendorId]["name"]);

            if (empty($this->arSiteVendors[$CAVendorL])) {
                $CAXML_ID = CUtil::translit($CAVendor, 'ru', $translitParams);
                $CAXML_ID = strtolower($CAXML_ID);
                if (empty($this->arSiteVendorsByXmlId[$CAXML_ID])) {
                    $arNeedAddVendors[] = ["NAME" => $CAVendor, "XML_ID" => $CAXML_ID];
                } else {
                    $arNeedAddVendors[] = ["NAME" => $CAVendor, "XML_ID" => $CAXML_ID."_".$diffVendorId];
                }
            }
        }

        if (empty($arNeedAddVendors)) {
            $this->Logger->log("LOG", "Нет брендов для добавления");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        $this->Logger->log("LOG", "Нужно добавить ".count($arNeedAddVendors)." брендов");

        $arAddedXML = [];
        $chunks = array_chunk($arNeedAddVendors, 10000);
        $bc = 0;
        foreach ($chunks as $k => $chunk) {
            $sql = 'INSERT INTO b_iblock_property_enum (`PROPERTY_ID`, `VALUE`, `DEF`, `SORT`, `XML_ID`) VALUES ';

            foreach ($chunk as $k => $arItem) {
                if($k > 0) {
                    $sql .= ", ";
                }

                $sql .= "(57893, '" . addslashes($arItem["NAME"]) . "', 'N', 500, '" . $arItem["XML_ID"] . "')";
                $arAddedXML[] = $arItem["XML_ID"];
                $bc++;
            }

            $this->connection->query($sql);
            $this->Logger->log("LOG", "Добавлено ".$bc." записей");
        }

        // получить добавленные бренды
        $el = new CIBlockElement();
        $sql = "SELECT VALUE, ID FROM b_iblock_property_enum WHERE XML_ID IN ('".implode("','", $arAddedXML)."')";
        $res = $this->connection->query($sql);
        while($arItem = $res->fetch()) {
            $this->Logger->log("LOG", "Добавление нового бренда ".$arItem["VALUE"]." в инфоблок");
            $props = ['BRAND' => $arItem["ID"]];
            $fields = [
                'IBLOCK_ID' => BRANDS_IBLOCK_ID,
                'NAME' => $arItem["VALUE"],
                'ACTIVE' => 'Y',
                'PROPERTY_VALUES' => $props
            ];

            if (!$newBrandId = $el->Add($fields)) {
                $this->Logger->log("ERROR", "Ошибка при добавлении нового бренда ".$arItem["VALUE"].
                    " в инфоблок \r\n".print_r($el->LAST_ERROR, true));
            } else {
                $this->Logger->log("LOG", "Бренд ".$arItem["VALUE"]." успешно добавлен");
            }
        }

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * Получить статус обработчика
     *
     * @return bool
     */
    public function CheckStatus() {
        $this->StartDebugTime(__FUNCTION__);
        $this->workerData = $this->workersChecker->Check($this->workerId);
        $this->EndDebugTime(__FUNCTION__);

        if ($this->workerData[$this->workerId]["BUSY"] == 1) {
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
    public function UpdateStatus($status = 0) {
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

(new CreateWorker())->StartWorker();