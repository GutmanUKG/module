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

ini_set('memory_limit', '25600M'); // много кушает оперативки!!!!
set_time_limit(0);


class UpdatePropertiesWorker_new {
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
    private $arCatalogDataById = []; // весь каталог сайта, где ключ id элемента
    private $arCAProperties = [
        "CA_ID" => [
            "ID" => 12443,
            "NAME" => "ID в catalog.app",
            "ACTIVE" => "Y",
            "SORT" => "100",
            "CODE" => "CA_ID",
            "PROPERTY_TYPE" => "N",
            "IBLOCK_ID" => 0
        ],
        "CA_BRAND" => [
            "NAME" => "Производитель",
            "ACTIVE" => "Y",
            "SORT" => "110",
            "CODE" => "CA_BRAND",
            "PROPERTY_TYPE" => "L",
            "IBLOCK_ID" => 0
        ],
        "MORE_PHOTO" => [
            "NAME" => "Доп. Изображения",
            "ACTIVE" => "Y",
            "SORT" => "120",
            "CODE" => "MORE_PHOTO",
            "PROPERTY_TYPE" => "F",
            "IBLOCK_ID" => 0,
            "MULTIPLE" => "Y",
        ],
        "CA_AUTO_ACTIVATE" => [
            "NAME" => "Автоматическая активация",
            "ACTIVE" => "Y",
            "SORT" => "130",
            "CODE" => "CA_AUTO_ACTIVATE",
            "PROPERTY_TYPE" => "N",
            "IBLOCK_ID" => 0,
        ],
        "ARTICLE" => [
            "NAME" => "Артикул",
            "ACTIVE" => "Y",
            "SORT" => "140",
            "CODE" => "ARTICLE",
            "PROPERTY_TYPE" => "S",
            "IBLOCK_ID" => 0,
            "MULTIPLE" => "N",
        ],
        "IS_UPDATED" => [
            "NAME" => "Обновлен",
            "ACTIVE" => "Y",
            "SORT" => "140",
            "CODE" => "IS_UPDATED",
            "PROPERTY_TYPE" => "N",
            "IBLOCK_ID" => 0,
            "MULTIPLE" => "N",
        ],
        "SUPPLIERS" => [
            "NAME" => "Поставщики",
            "ACTIVE" => "Y",
            "SORT" => "140",
            "CODE" => "SUPPLIERS",
            "PROPERTY_TYPE" => "S",
            "IBLOCK_ID" => 0,
            "MULTIPLE" => "N",
        ],
        "CML2_ARTICLE" => [
            "NAME" => "Артикул",
            "ACTIVE" => "Y",
            "SORT" => "140",
            "CODE" => "CML2_ARTICLE",
            "PROPERTY_TYPE" => "S",
            "IBLOCK_ID" => 0,
            "MULTIPLE" => "N",
        ]
    ];
    private $arCASiteProperties = []; // массив с созданными свойствами только для catalog.app
    private $catalogIblockId = 0;
    private $arCASectionProperties = []; // свойства из catalog.app по разделам
    private $sectionConnections = []; // связь разделов catalog.app и сайта
    private $arCASettings = [];
    private $arCAUnits = []; // еденицы измерения из catalog.app
    private $propPrefix = "";
//    private $useDescriptionForPropData = true;


    public function __construct () {
        $this->Logger = new ImarketLogger("/upload/log/UpdatePropertiesWorker_new_debug/");
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

        // получить каталог из catalogApp
        $this->GetCatalogModels();
        // получить товары сайта из текущего ЦО
        $this->GetCatalogGoods();

        if (!empty($this->arCatalogDataByXML_ID)) {
            #region изменение размера поля кода в таблице свойств
            $sql = "ALTER TABLE b_iblock_property MODIFY CODE VARCHAR(100)";
            $this->connection->query($sql);
            #endregion

            // получить еденицы измерения из catalog.app
            $this->GetUnits();
            // проверить, есть ли основные свойства
            $this->PrepareMainProperties();
            // получить связи разделов сайта и catalog.app
            $this->GetSectionsConnections();
            // получить свойства каталога сайта
            $this->GetCatalogProperties();
            // обновить свойства товаров
            $this->UpdateProperties();
            // Проверяем сопоставления свойств к разделам в catalog.app и каталоге сайта
            $this->setSiteCategoryProperties();
        }

        // проверяет группы свойств
//        $this->CheckPropertiesGroups(); // обязательно должно быть после обновления свойств

        // обновить статус
//        $this->UpdateStatus(0);

        /* region WCrow start props after products only */
//        $sql = "UPDATE catalog_app_workers SET NEED_START = '0' WHERE WORKER_ID = 'update_properties'";
//        $this->connection->query($sql);
        /* endregion */

        $this->Logger->log("LOG", "Обработка закончена");

        $this->EndDebugTime(__FUNCTION__);
    }

    private function setSiteCategoryProperties()
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

            $catalogAppSectionInfo = $this->restAPI->getCategoryById($CASectionId);
            $siteSectionId = $this->sectionConnections[$CASectionId];
            $siteSectionPropertyIDs = [];
            $siteSectionPropertiesData = [];
            $siteSectionPropertyData = [];

            foreach ($catalogAppSectionInfo["properties"] as $arProp) {
                $catalogAppSection["PROPERTIES"][$arProp["id"]] = $arProp;
            }

            if (empty($siteSectionId)) {
                $this->Logger->log("ERROR", "Не нашли связь разделов для раздела каталог апп {$CASectionId}");
                continue;
            }

            $sql = "SELECT PROPERTY_ID FROM b_iblock_section_property WHERE SECTION_ID = {$siteSectionId} AND IBLOCK_ID = {$this->catalogIblockId}";
            $res = $this->connection->query($sql);
            while($arItem = $res->fetch()) {
                $siteSectionPropertyIDs[] = $arItem["PROPERTY_ID"];
            }

            if (!empty($siteSectionPropertyIDs)) {
                $sql = "SELECT ID, NAME, CODE FROM b_iblock_property WHERE ID IN (".implode(",", $siteSectionPropertyIDs).")";
                $res = $this->connection->query($sql);
                while($arItem = $res->fetch()) {
                    $siteSectionPropertiesData[$arItem["CODE"]] = $arItem;
                }
            }

            $this->Logger->log("LOG", "Привязанных свойств к разделу в каталоге сайта ".count($siteSectionPropertyIDs));

            /* region WhiteCrow props filtering check module */
//            $wcPropFilter = false;
//            if(\Bitrix\Main\Loader::includeModule('wcrow.settings')){
//                $wcPropFilter = true;
//            }
            /* endregion */
            if (!empty($catalogAppSection["PROPERTIES"])) {
                $this->Logger->log("LOG", "Привязанных свойств к разделу в catalog.app ".count($catalogAppSection["PROPERTIES"]));

                foreach ($catalogAppSection["PROPERTIES"] as $CAProperty) {
                    $this->Logger->log("LOG", "Проверяем свойство '{$CAProperty["name"]}'");
                    $propCode = $this->getProperyCode($CAProperty);

                    if (empty($propCode)) {
                        $this->Logger->log("LOG", "Нет кода свойства!");
                        continue;
                    }

                    /* region WhiteCrow props filtering */
//                    if($wcPropFilter && $fProp = \WCrow\Settings\Action::doubleControlByCodeW($CAProperty["name"])) {
//                        $propCode = $fProp['CODE'];
//                        if(!isset($siteSectionPropertiesData[$propCode]))  {
//                            $siteSectionPropertiesData[$propCode] = [
//                                'ID' => $fProp['ID'],
//                                'NAME' => $fProp['NAME'],
//                                'CODE' => $fProp['CODE'],
//                            ];
//                        }
//                    }
                    /* endregion */


                    if (!empty($siteSectionPropertiesData[$propCode])) {
                        $this->Logger->log("LOG", "Свойство есть в обоих каталогах");
                    } else {
                        $this->Logger->log("LOG", "Не нашли привязанное свойство '{$CAProperty["name"]}' к разделу '{$catalogAppSection["NAME"]}', добавляем привязку");

                        if (empty($siteSectionPropertyData[$propCode])) {
                            $sql = "SELECT ID, NAME, CODE FROM b_iblock_property WHERE CODE = '{$propCode}'";
                            $res = $this->connection->query($sql);
                            if($arItem = $res->fetch()) {
                                $siteSectionPropertyData[$arItem["CODE"]] = $arItem;
                            } else {
                                $arPropData = [
                                    "NAME" => $CAProperty["name"],
                                    "ACTIVE" => "Y",
                                    "SORT" => "500",
                                    "CODE" => $propCode,
                                    "PROPERTY_TYPE" => "S",
                                    "IBLOCK_ID" => $this->catalogIblockId,
                                    "SECTION_PROPERTY" => "N",
                                    "FEATURES" => [
                                        [
                                            'IS_ENABLED' => "N",
                                            'MODULE_ID' => "iblock",
                                            'FEATURE_ID' => "SECTION_PROPERTY"
                                        ]
                                    ]
                                ];

                                $arPropData = array_merge($arPropData, $this->getPropertyDataByType($CAProperty));
                                $this->CreateProperty($arPropData, $CASectionId);
                            }
                        }

                        try {
                            if (!empty($siteSectionPropertyData[$propCode]["ID"])) {
                                $sql = "INSERT INTO b_iblock_section_property (`IBLOCK_ID`, `SECTION_ID`, `PROPERTY_ID`, `SMART_FILTER`) VALUES 
                                ('".$this->catalogIblockId."', '".$siteSectionId."', '".$siteSectionPropertyData[$propCode]["ID"]."', 'Y')";
                                $this->connection->query($sql);
                            }
                        } Catch (\Exception $e) {
                            $this->Logger->log("ERROR", "Ошибка при обновлении привязки ".$e->getMessage());
                        }

                        $siteSectionPropertiesData[$propCode] = $siteSectionPropertyData[$propCode];
                    }
                }
            }

            if (empty($catalogAppSectionInfo['properties'])) {
                $this->Logger->log("LOG", "У раздела нет свойств в catalog.app, удаляем привязки свойств на сайте");

                foreach($siteSectionPropertyIDs as $sitePropId) {
                    $sql = "DELETE FROM b_iblock_section_property WHERE 
                    SECTION_ID = {$siteSectionId} AND 
                    IBLOCK_ID = {$this->catalogIblockId} AND
                    PROPERTY_ID = {$sitePropId}";
                    $this->connection->query($sql);
                }
            } else {
                $this->Logger->log("LOG", "Обновляем привязки свойств к разделам на сайте");

                foreach ($catalogAppSectionInfo['properties'] as $arCAProp) {
                    $this->Logger->log("LOG", "Проверяем свойство {$arCAProp['name']} из каталог апп");

                    $propCode = $this->getProperyCode($arCAProp);

                    if (empty($propCode)) {
                        $this->Logger->log("LOG", "Нет кода свойства!");
                        continue;
                    }

                    if (!empty($siteSectionPropertiesData[$propCode])) {
                        $this->Logger->log("LOG", "Связь свойства {$arCAProp['name']} с разделом {$catalogAppSection["NAME"]} есть");
                        unset($siteSectionPropertiesData[$propCode]);
                    } else {
                        $this->Logger->log("LOG", "Связи свойства {$arCAProp['name']} с разделом {$catalogAppSection["NAME"]} нет");
                    }
                }

                if (!empty($siteSectionPropertiesData)) {
                    $this->Logger->log("LOG", "Удаляем связи свойств для раздела {$catalogAppSection["NAME"]}");

                    foreach($siteSectionPropertiesData as $arProp) {
                        $this->Logger->log("LOG", "Удаляем привязку свойства {$arProp['NAME']}");

                        $sql = "DELETE FROM b_iblock_section_property WHERE 
                        SECTION_ID = {$siteSectionId} AND 
                        IBLOCK_ID = {$this->catalogIblockId} AND
                        PROPERTY_ID = {$arProp['ID']}";
                        //                        $this->connection->query($sql);
                    }
                } else {
                    $this->Logger->log("LOG", "Нет свойств для удаления связей");
                }
            }

        }

        $this->Logger->log("LOG", "Проверка сопоставлений закончена");
        $this->EndDebugTime(__FUNCTION__);
    }

    private function getPropertyDataByType($property)
    {
        $arPropData = [];

        switch($property["type"]) {
            case "Decimal":
                $arPropData["PROPERTY_TYPE"] = "N";
                $arPropData["WITH_DESCRIPTION"] = "Y";
                break;
            case "Integer":
                $arPropData["PROPERTY_TYPE"] = "N";
                $arPropData["WITH_DESCRIPTION"] = "Y";
                break;
            case "String":
                break;
            case "Enum":
                $arPropData["PROPERTY_TYPE"] = "L";
                break;
            case "Flag":
                $arPropData["PROPERTY_TYPE"] = "L";
                $arPropData["MULTIPLE"] = "Y";
                break;
            case "Boolean":
                $arPropData["PROPERTY_TYPE"] = "L";
                $arPropData["LIST_TYPE"] = "C";
                break;
            case "File":
                break;
            case "ModelsList":
                $arPropData["PROPERTY_TYPE"] = "E";
                $arPropData["LIST_TYPE"] = "L";
                $arPropData["MULTIPLE"] = "Y";
                $arPropData["LINK_IBLOCK_ID"] = $this->catalogIblockId;

                break;
            default:
                $this->Logger->log("LOG", "Не определили тип свойства\r\n".print_r($property, true));
                break;
        }

        return $arPropData;
    }

    private function CheckSectionsProperties() {
        $this->Logger->log("LOG", "Проверяем сопоставления свойств к разделам в catalog.app и каталоге сайта");
        $this->StartDebugTime(__FUNCTION__);

        foreach ($this->arCASectionProperties as $CASectionId => $arSection) {
            $this->Logger->log("LOG", "Обрабатываем раздел {$arSection["NAME"]}");

            file_put_contents($_SERVER["DOCUMENT_ROOT"]."/upload/log/UpdatePropertiesWorker/CASections_".$CASectionId.".txt", print_r($arSection, true));

            $sectionPropertiesWithoutParent = $this->restAPI->getCategoryById($CASectionId, false);

            $siteSectionId = $this->sectionConnections[$CASectionId];
            $arSiteSectionPropertyID = [];
            $arSiteSectionProperties = [];
            $arSiteSectionProperties2 = [];

            if (empty($siteSectionId)) {
                $this->Logger->log("ERROR", "Не нашли связь разделов для раздела каталог апп {$CASectionId}");
                return;
            }

            $sql = "SELECT PROPERTY_ID FROM b_iblock_section_property WHERE SECTION_ID = {$siteSectionId} AND IBLOCK_ID = {$this->catalogIblockId}";
            $res = $this->connection->query($sql);
            while($arItem = $res->fetch()) {
                $arSiteSectionPropertyID[$arItem["PROPERTY_ID"]] = $arItem["PROPERTY_ID"];
            }

            if (!empty($arSiteSectionPropertyID)) {
                $sql = "SELECT ID, NAME, CODE FROM b_iblock_property WHERE ID IN (".implode(",", $arSiteSectionPropertyID).")";
                $res = $this->connection->query($sql);
                while($arItem = $res->fetch()) {
                    $arSiteSectionProperties[$arItem["CODE"]] = $arItem;
                    $arSiteSectionProperties2[$arItem["CODE"]] = $arItem;
                }

                file_put_contents($_SERVER["DOCUMENT_ROOT"]."/upload/log/UpdatePropertiesWorker/SiteSectionProps_".$siteSectionId.".txt", print_r($arSiteSectionProperties, true));
            }

            $this->Logger->log("LOG", "Привязанных свойств к разделу в каталоге сайта ".count($arSiteSectionProperties));

            if (!empty($arSection["PROPERTIES"])) {
                $this->Logger->log("LOG", "Привязанных свойств к разделу в catalog.app ".count($arSection["PROPERTIES"]));

                foreach ($arSection["PROPERTIES"] as $CAPropId => $arCAProp) {
                    $this->Logger->log("LOG", "Проверяем свойство '{$arCAProp["name"]}'");

//                    if ($this->useDescriptionForPropData && !empty($arCAProp['description'])) {
//                        $propData = json_decode($arCAProp['description'], true);
//                        $propCode = $propData['CODE'];
//                    } else {
//                        $propCode = $this->getProperyCode($arCAProp);
//                    }

                    if (empty($propCode)) {
                        $propCode = $this->getProperyCode($arCAProp);

                        if (empty($propCode)) {
                            $this->Logger->log("LOG", "Нет кода свойства!");
                            continue;
                        }
                    }

                    if (!empty($arSiteSectionProperties[$propCode])) {
                        $this->Logger->log("LOG", "Свойство есть в обоих каталогах");
                        unset($arSiteSectionProperties[$propCode]);
                    } else {
                        $this->Logger->log("LOG", "Не нашли привязанное свойство '{$arCAProp["name"]}' к разделу '{$arSection["NAME"]}'");
                    }
                }

                /*if (!empty($arSiteSectionProperties)) {
                    $this->Logger->log("LOG", "Свойства для удаления ".print_r($arSiteSectionProperties, true));

                    foreach ($arSiteSectionProperties as $code => $arProp) {
                        $this->Logger->log("LOG", "Удаление свойства '{$arProp["NAME"]}'");

                        if (CIBlockProperty::Delete($arProp["ID"])) {
                            $this->Logger->log("LOG", "Свойство '{$arProp["NAME"]}' удалено");

                            $this->Logger->log("LOG", "Удаления свойства '{$arProp["NAME"]}' из групп свойств");
                            $sql = "DELETE FROM catalog_app_properties WHERE PROPERTY_ID = ".$arProp["ID"];
                            $this->connection->query($sql);
                            $this->Logger->log("LOG", "Свойство '{$arProp["NAME"]}' удалено из групп");
                        } else {
                            $this->Logger->log("LOG", "Возникли ошибки при удалени свойства '{$arProp["NAME"]}'");
                        }
                    }
                }*/
            } else {
                $this->Logger->log("LOG", "У раздела нет свойств в catalog.app");
            }

            if (empty($sectionPropertiesWithoutParent['properties'])) {
                foreach($arSiteSectionPropertyID as $sitePropId) {
                    $sql = "DELETE FROM b_iblock_section_property WHERE 
                        SECTION_ID = {$siteSectionId} AND 
                        IBLOCK_ID = {$this->catalogIblockId} AND
                        PROPERTY_ID = {$sitePropId}";
                    $this->connection->query($sql);
                }
            } else {
                foreach ($sectionPropertiesWithoutParent['properties'] as $CAPropId => $arCAProp) {
//                    if ($this->useDescriptionForPropData && !empty($arCAProp['description'])) {
//                        $propData = json_decode($arCAProp['description'], true);
//                        $propCode = $propData['CODE'];
//                    } else {
//                        $propCode = $this->getProperyCode($arCAProp);
//                    }

                    if (empty($propCode)) {
                        $propCode = $this->getProperyCode($arCAProp);

                        if (empty($propCode)) {
                            $this->Logger->log("LOG", "Нет кода свойства!");
                            continue;
                        }
                    }

                    if (!empty($arSiteSectionProperties2[$propCode])) {
                        unset($arSiteSectionProperties2[$propCode]);
                    }
                }

                if (!empty($arSiteSectionProperties2)) {
                    foreach($arSiteSectionProperties2 as $propCode => $arProp) {
                        $sql = "DELETE FROM b_iblock_section_property WHERE 
                        SECTION_ID = {$siteSectionId} AND 
                        IBLOCK_ID = {$this->catalogIblockId} AND
                        PROPERTY_ID = {$arProp['ID']}";
                        $this->connection->query($sql);
                    }
                }
            }
        }

        $this->Logger->log("LOG", "Проверка сопоставлений закончена");
        $this->EndDebugTime(__FUNCTION__);
    }

    private function CheckPropertiesGroups() {
        $this->Logger->log("LOG", "Проверяем группы свойств товаров");
        $this->StartDebugTime(__FUNCTION__);

        $defOrder = 100;

        if (empty($this->arCASectionProperties)) {
            $this->Logger->log("LOG", "Нет данных для обработки");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        foreach ($this->arCASectionProperties as $sid => $arSection) {
            $currentSiteSection = $this->sectionConnections[$arSection["ID"]];

            if (empty($currentSiteSection)) {
                continue;
            }

            $this->Logger->log("LOG", "Проверяем раздел ".$currentSiteSection);

            $arExistSectionProperties = [];

            $sql = "SELECT * FROM catalog_app_properties WHERE SECTION_ID = {$currentSiteSection} AND TYPE = 'G' ORDER BY SORT";
            $res = $this->connection->query($sql);
            $i = 0;
            while($arItem = $res->fetch()) {
                if (empty($arItem["PROPERTY_ID"])) {
                    $i++;
                    $arExistSectionProperties[$i]["NAME"] = $arItem["GROUP_NAME"];
                    $arExistSectionProperties[$i]["SORT"] = $arItem["SORT"];
                } else {
                    $arExistSectionProperties[$i]["PROPERTIES"][] = [
                        "ID" => $arItem["PROPERTY_ID"],
                        "NAME" => $arItem["GROUP_NAME"],
                        "SORT" => $arItem["SORT"],
                    ];
                }
            }

            $arCatalogAppPropertiesGroup = [];
            foreach ($arSection["PROPERTIES_GROUP"] as $id => $item) {
                $arCatalogAppPropertiesGroup[$id]["NAME"] = $item["name"];
                $arCatalogAppPropertiesGroup[$id]["SORT"] = $item["order"] + $defOrder;
            }

            foreach ($arSection["PROPERTIES"] as $id => $item) {
                $property["code"] = $this->propPrefix.translit($item["name"]);
                $property["code"] = strtoupper($property["code"]);
                $property["code"] .= "_".$item["id"];

                // если есть группа свойств
                if (!empty($arCatalogAppPropertiesGroup[$item["propertyGroupId"]])) {
                    // если не нашли свойство на сайте, пропускаем
                    if (empty($this->arCASiteProperties[$property["code"]]["ID"])) {
                        continue;
                    }

                    $arCatalogAppPropertiesGroup[$item["propertyGroupId"]]["PROPERTIES"][] = [
                        "ID" => $this->arCASiteProperties[$property["code"]]["ID"],
                        "NAME" => $item["name"],
                        "SORT" => $item["order"] + $defOrder,
                    ];
                }
            }

            if (empty($arCatalogAppPropertiesGroup)) {
                continue;
            }

            $this->Logger->log("LOG", "Собрали группы свойств");

            // сортировка групп
            usort($arCatalogAppPropertiesGroup, function ($a, $b) {
                if ($a["SORT"] > $b["SORT"]) {
                    return 1;
                } elseif ($a["SORT"] < $b["SORT"]) {
                    return -1;
                } else {
                    return 0;
                }
            });

            // сортировка свойств в группах
            foreach($arCatalogAppPropertiesGroup as &$arItem) {
                usort($arItem["PROPERTIES"], function ($a, $b) {
                    if ($a["SORT"] > $b["SORT"]) {
                        return 1;
                    } elseif ($a["SORT"] < $b["SORT"]) {
                        return -1;
                    } else {
                        return 0;
                    }
                });
            }

            if (!empty($arExistSectionProperties)) {
                $this->Logger->log("LOG", "Удаляем существующие группы");

                $sql = "DELETE FROM catalog_app_properties WHERE SECTION_ID = {$currentSiteSection} AND TYPE = 'G'";
//                $this->connection->query($sql); // TODO раскомментить когда исправят в каталог апп
            }

            $sql = "INSERT INTO catalog_app_properties (`SECTION_ID`, `PROPERTY_ID`, `SORT`, `TYPE`, `GROUP_NAME`) VALUES";

            $sort = 0;
            foreach ($arCatalogAppPropertiesGroup as $k => $arPG) {
                if (empty($arPG["PROPERTIES"])) {
                    continue;
                }

                if ($sort > 0) {
                    $sql .= ",";
                }

                $sql .= " ({$currentSiteSection}, 0, $defOrder + $sort, 'G', '{$arPG["NAME"]}')";
                $sort++;

                foreach ($arPG["PROPERTIES"] as $arProp) {
                    $sql .= ", ({$currentSiteSection}, {$arProp["ID"]}, $defOrder + $sort, 'G', '{$arProp["NAME"]}')";
                    $sort++;
                }
            }

//            $this->connection->query($sql);// TODO расскомментить когда исправят в каталог апп
            $this->Logger->log("LOG", "Группы свойств для раздела ".$currentSiteSection." обновлены");
        }

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

        $this->Logger->log("LOG", "Получено данных о веленичинах ".count($this->arCAUnits));
        $this->EndDebugTime(__FUNCTION__);
    }

    private function GetSectionsConnections() {
        $this->Logger->log("LOG", "Получаем сопоставления разделов");
        $this->StartDebugTime(__FUNCTION__);

        $sConnect = new ImarketSectionsConnections();
        $this->sectionConnections = $sConnect->getAll();

        $this->Logger->log("LOG", "Данные получены, всего сопоставленных разделов ".count($this->sectionConnections));

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * получить все модели из catalogApp
     */
    private function GetCatalogModels() {
        $this->Logger->log("LOG", "Получение моделей каталога");
        $this->StartDebugTime(__FUNCTION__);
        $ModelFile = $_SERVER["DOCUMENT_ROOT"] . "/upload/CatalogAppModels.txt";
        $cartModified = [];

//        $date = date("Y-m")."-01T00:00:00";

        $this->arModels = $this->restAPI->GetModels();
        $this->arModels = array_reverse($this->arModels, true);

//        $date = date("Y-m-d", strtotime("-5 day"))."T00:00:00";
//        $this->arModels = $this->restAPI->GetModelCartModified($date);

//        $cartModified = $this->restAPI->GetModelModified($date);

//        if (!empty($cartModified)) {
//            if (!empty($this->arModels)) {
//                $this->arModels = array_merge($this->arModels, $cartModified);
//            } else {
//                $this->arModels = $cartModified;
//            }
//        }

        foreach ($this->arModels as $k => $arModel) {
            if (empty($arModel["externalId"])) {
                $this->arModelsByXML_ID[$arModel["id"]] = $arModel;
            } else {
                $this->arModelsByXML_ID[$arModel["externalId"]] = $arModel;
            }
        }

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

        $xmlIds = ['9e772b39-f387-11ef-822f-a8a159c28aa2','9e772b04-f387-11ef-822f-a8a159c28aa2','9e772171-f387-11ef-822f-a8a159c28aa2','9e76ee62-f387-11ef-822f-a8a159c28aa2','9e76ea3c-f387-11ef-822f-a8a159c28aa2','00bbfcbc-f51c-11ef-822f-a8a159c28aa2','00bcb5cf-f51c-11ef-822f-a8a159c28aa2','00bc9e77-f51c-11ef-822f-a8a159c28aa2','00bcd3f4-f51c-11ef-822f-a8a159c28aa2','00bbf56b-f51c-11ef-822f-a8a159c28aa2','00bbe931-f51c-11ef-822f-a8a159c28aa2','00bc80d0-f51c-11ef-822f-a8a159c28aa2','00bc7b1c-f51c-11ef-822f-a8a159c28aa2','00bc7085-f51c-11ef-822f-a8a159c28aa2','00bc7d10-f51c-11ef-822f-a8a159c28aa2','00bc0d5e-f51c-11ef-822f-a8a159c28aa2','00bc5831-f51c-11ef-822f-a8a159c28aa2','00bcde41-f51c-11ef-822f-a8a159c28aa2','00bce4c9-f51c-11ef-822f-a8a159c28aa2','00bce551-f51c-11ef-822f-a8a159c28aa2','00bce127-f51c-11ef-822f-a8a159c28aa2','00bc5f68-f51c-11ef-822f-a8a159c28aa2','00bca81c-f51c-11ef-822f-a8a159c28aa2','00bc2d94-f51c-11ef-822f-a8a159c28aa2','00bccbce-f51c-11ef-822f-a8a159c28aa2','00bc3880-f51c-11ef-822f-a8a159c28aa2','00bcaabd-f51c-11ef-822f-a8a159c28aa2','00bc156a-f51c-11ef-822f-a8a159c28aa2','00bbf772-f51c-11ef-822f-a8a159c28aa2','00bc9009-f51c-11ef-822f-a8a159c28aa2','00bc83d7-f51c-11ef-822f-a8a159c28aa2','00bcd129-f51c-11ef-822f-a8a159c28aa2','00bc218f-f51c-11ef-822f-a8a159c28aa2','00bc9411-f51c-11ef-822f-a8a159c28aa2','00bcd5d8-f51c-11ef-822f-a8a159c28aa2','00bca1f3-f51c-11ef-822f-a8a159c28aa2','00bc7f04-f51c-11ef-822f-a8a159c28aa2','00bc9d65-f51c-11ef-822f-a8a159c28aa2','00bc7e09-f51c-11ef-822f-a8a159c28aa2','00bc08d9-f51c-11ef-822f-a8a159c28aa2','00bc6142-f51c-11ef-822f-a8a159c28aa2','00bc5039-f51c-11ef-822f-a8a159c28aa2','00bc3b9d-f51c-11ef-822f-a8a159c28aa2','00bcb292-f51c-11ef-822f-a8a159c28aa2','00bc0a5c-f51c-11ef-822f-a8a159c28aa2','00bc37ae-f51c-11ef-822f-a8a159c28aa2','00bc77b5-f51c-11ef-822f-a8a159c28aa2','00bc2e56-f51c-11ef-822f-a8a159c28aa2','00bc8ba1-f51c-11ef-822f-a8a159c28aa2','00bbeda8-f51c-11ef-822f-a8a159c28aa2','00bc60c0-f51c-11ef-822f-a8a159c28aa2','00bc64d4-f51c-11ef-822f-a8a159c28aa2','00bc1888-f51c-11ef-822f-a8a159c28aa2','00bc12cb-f51c-11ef-822f-a8a159c28aa2','00bbf032-f51c-11ef-822f-a8a159c28aa2','00bc996f-f51c-11ef-822f-a8a159c28aa2','00bc1a00-f51c-11ef-822f-a8a159c28aa2','00bcd289-f51c-11ef-822f-a8a159c28aa2','00bc5fdf-f51c-11ef-822f-a8a159c28aa2','00bcd382-f51c-11ef-822f-a8a159c28aa2','00bc9a6e-f51c-11ef-822f-a8a159c28aa2','00bcc36f-f51c-11ef-822f-a8a159c28aa2','00bc7968-f51c-11ef-822f-a8a159c28aa2','00bc03d1-f51c-11ef-822f-a8a159c28aa2','00bca8c7-f51c-11ef-822f-a8a159c28aa2','00bc856d-f51c-11ef-822f-a8a159c28aa2','00bc1ff8-f51c-11ef-822f-a8a159c28aa2','00bc2c55-f51c-11ef-822f-a8a159c28aa2','00bbff2e-f51c-11ef-822f-a8a159c28aa2','00bca16f-f51c-11ef-822f-a8a159c28aa2','00bc029f-f51c-11ef-822f-a8a159c28aa2','00bc771f-f51c-11ef-822f-a8a159c28aa2','00bce0b0-f51c-11ef-822f-a8a159c28aa2','00bcaeb3-f51c-11ef-822f-a8a159c28aa2','00bc3f7c-f51c-11ef-822f-a8a159c28aa2','00bc7e84-f51c-11ef-822f-a8a159c28aa2','00bc2c97-f51c-11ef-822f-a8a159c28aa2','00bbe98f-f51c-11ef-822f-a8a159c28aa2','00bcddc9-f51c-11ef-822f-a8a159c28aa2','00bcc492-f51c-11ef-822f-a8a159c28aa2','00bc35a6-f51c-11ef-822f-a8a159c28aa2','00bc8093-f51c-11ef-822f-a8a159c28aa2','00bc8e82-f51c-11ef-822f-a8a159c28aa2','00bbf20d-f51c-11ef-822f-a8a159c28aa2','00bcd56b-f51c-11ef-822f-a8a159c28aa2','00bbea88-f51c-11ef-822f-a8a159c28aa2','00bbef75-f51c-11ef-822f-a8a159c28aa2','00bca67e-f51c-11ef-822f-a8a159c28aa2','00bbff6a-f51c-11ef-822f-a8a159c28aa2','00bc6b8a-f51c-11ef-822f-a8a159c28aa2','00bce591-f51c-11ef-822f-a8a159c28aa2','00bc7843-f51c-11ef-822f-a8a159c28aa2','00bc8d3b-f51c-11ef-822f-a8a159c28aa2','00bc39c7-f51c-11ef-822f-a8a159c28aa2','00bcaff8-f51c-11ef-822f-a8a159c28aa2','00bc133b-f51c-11ef-822f-a8a159c28aa2','00bc095d-f51c-11ef-822f-a8a159c28aa2','00bce640-f51c-11ef-822f-a8a159c28aa2','00bc2b64-f51c-11ef-822f-a8a159c28aa2','00bcd614-f51c-11ef-822f-a8a159c28aa2','00bcdd94-f51c-11ef-822f-a8a159c28aa2','00bbf5d2-f51c-11ef-822f-a8a159c28aa2','00bc283d-f51c-11ef-822f-a8a159c28aa2','00bc8224-f51c-11ef-822f-a8a159c28aa2','00bcb149-f51c-11ef-822f-a8a159c28aa2','00bc8ad6-f51c-11ef-822f-a8a159c28aa2','00bc8a58-f51c-11ef-822f-a8a159c28aa2','00bbe6a5-f51c-11ef-822f-a8a159c28aa2','00bc00c2-f51c-11ef-822f-a8a159c28aa2','00bbf121-f51c-11ef-822f-a8a159c28aa2','00bc2f8e-f51c-11ef-822f-a8a159c28aa2','00bbe905-f51c-11ef-822f-a8a159c28aa2','00bc559d-f51c-11ef-822f-a8a159c28aa2','00bbfb61-f51c-11ef-822f-a8a159c28aa2','00bcbdbd-f51c-11ef-822f-a8a159c28aa2','00bc2a2b-f51c-11ef-822f-a8a159c28aa2','00bc7568-f51c-11ef-822f-a8a159c28aa2','00bc50e3-f51c-11ef-822f-a8a159c28aa2','00bcdeba-f51c-11ef-822f-a8a159c28aa2','00bc2a6c-f51c-11ef-822f-a8a159c28aa2','00bca63e-f51c-11ef-822f-a8a159c28aa2','00bc0c2a-f51c-11ef-822f-a8a159c28aa2','00bc36f0-f51c-11ef-822f-a8a159c28aa2','00bc0081-f51c-11ef-822f-a8a159c28aa2','00bc89db-f51c-11ef-822f-a8a159c28aa2','00bcb739-f51c-11ef-822f-a8a159c28aa2','00bc2976-f51c-11ef-822f-a8a159c28aa2','00bcbfd9-f51c-11ef-822f-a8a159c28aa2','00bcc804-f51c-11ef-822f-a8a159c28aa2','00bc3d6f-f51c-11ef-822f-a8a159c28aa2','00bc0815-f51c-11ef-822f-a8a159c28aa2','00bc0da9-f51c-11ef-822f-a8a159c28aa2','00bbfd9f-f51c-11ef-822f-a8a159c28aa2','00bbfbd4-f51c-11ef-822f-a8a159c28aa2','00bccfff-f51c-11ef-822f-a8a159c28aa2','00bc2938-f51c-11ef-822f-a8a159c28aa2','00bbe725-f51c-11ef-822f-a8a159c28aa2','00bc3149-f51c-11ef-822f-a8a159c28aa2','00bc99f9-f51c-11ef-822f-a8a159c28aa2','00bca239-f51c-11ef-822f-a8a159c28aa2','00bc6758-f51c-11ef-822f-a8a159c28aa2','00bcb7f9-f51c-11ef-822f-a8a159c28aa2','00bbef17-f51c-11ef-822f-a8a159c28aa2','00bbf6d9-f51c-11ef-822f-a8a159c28aa2','00bca5ff-f51c-11ef-822f-a8a159c28aa2','00bc099d-f51c-11ef-822f-a8a159c28aa2','00bc37fd-f51c-11ef-822f-a8a159c28aa2','00bcc5b3-f51c-11ef-822f-a8a159c28aa2','00bcc8bf-f51c-11ef-822f-a8a159c28aa2','00bc6547-f51c-11ef-822f-a8a159c28aa2','00bbf73c-f51c-11ef-822f-a8a159c28aa2','00bc1e15-f51c-11ef-822f-a8a159c28aa2','00bbf921-f51c-11ef-822f-a8a159c28aa2','00bc28b6-f51c-11ef-822f-a8a159c28aa2','00bc84dc-f51c-11ef-822f-a8a159c28aa2','00bbf673-f51c-11ef-822f-a8a159c28aa2','00bbedd8-f51c-11ef-822f-a8a159c28aa2','00bc1305-f51c-11ef-822f-a8a159c28aa2','00bbe856-f51c-11ef-822f-a8a159c28aa2','00bc7a61-f51c-11ef-822f-a8a159c28aa2','00bc6a60-f51c-11ef-822f-a8a159c28aa2','00bcc78f-f51c-11ef-822f-a8a159c28aa2','00bcae3c-f51c-11ef-822f-a8a159c28aa2','00bccb95-f51c-11ef-822f-a8a159c28aa2','00bbeb8e-f51c-11ef-822f-a8a159c28aa2','00bc2ed5-f51c-11ef-822f-a8a159c28aa2','00bcde08-f51c-11ef-822f-a8a159c28aa2','00bce8ed-f51c-11ef-822f-a8a159c28aa2','00bc66e5-f51c-11ef-822f-a8a159c28aa2','00bcd3b9-f51c-11ef-822f-a8a159c28aa2','00bc5715-f51c-11ef-822f-a8a159c28aa2','00bc0b26-f51c-11ef-822f-a8a159c28aa2','00bbf59f-f51c-11ef-822f-a8a159c28aa2','00bcc57d-f51c-11ef-822f-a8a159c28aa2','00bcb9a6-f51c-11ef-822f-a8a159c28aa2','00bca338-f51c-11ef-822f-a8a159c28aa2','00bbe77a-f51c-11ef-822f-a8a159c28aa2','00bc14c2-f51c-11ef-822f-a8a159c28aa2','00bc0be7-f51c-11ef-822f-a8a159c28aa2','00bbf956-f51c-11ef-822f-a8a159c28aa2','00bc02dd-f51c-11ef-822f-a8a159c28aa2','00bcb9e6-f51c-11ef-822f-a8a159c28aa2','00bbf8b6-f51c-11ef-822f-a8a159c28aa2','00bc13a8-f51c-11ef-822f-a8a159c28aa2','00bc835c-f51c-11ef-822f-a8a159c28aa2','00bc0ba5-f51c-11ef-822f-a8a159c28aa2','00bcacf5-f51c-11ef-822f-a8a159c28aa2','00bc68bf-f51c-11ef-822f-a8a159c28aa2','00bc1d26-f51c-11ef-822f-a8a159c28aa2','00bc16f8-f51c-11ef-822f-a8a159c28aa2','00bcab03-f51c-11ef-822f-a8a159c28aa2','00bc0ae1-f51c-11ef-822f-a8a159c28aa2','00bc21d5-f51c-11ef-822f-a8a159c28aa2','00bca125-f51c-11ef-822f-a8a159c28aa2','00bc1a3e-f51c-11ef-822f-a8a159c28aa2','00bbee87-f51c-11ef-822f-a8a159c28aa2','00bc2aeb-f51c-11ef-822f-a8a159c28aa2','00bcdca4-f51c-11ef-822f-a8a159c28aa2','00bc0aa0-f51c-11ef-822f-a8a159c28aa2','00bc61f1-f51c-11ef-822f-a8a159c28aa2','00bce507-f51c-11ef-822f-a8a159c28aa2','00bc680c-f51c-11ef-822f-a8a159c28aa2','00bbe8a9-f51c-11ef-822f-a8a159c28aa2','00bcb1c5-f51c-11ef-822f-a8a159c28aa2','00bc3db2-f51c-11ef-822f-a8a159c28aa2','00bc5203-f51c-11ef-822f-a8a159c28aa2','00bbec94-f51c-11ef-822f-a8a159c28aa2','00bc8e3b-f51c-11ef-822f-a8a159c28aa2','00bc7fd1-f51c-11ef-822f-a8a159c28aa2','00bcd4e9-f51c-11ef-822f-a8a159c28aa2','00bca738-f51c-11ef-822f-a8a159c28aa2','00bc0264-f51c-11ef-822f-a8a159c28aa2','00bc1534-f51c-11ef-822f-a8a159c28aa2','00bc5274-f51c-11ef-822f-a8a159c28aa2','00bc2154-f51c-11ef-822f-a8a159c28aa2','00bc8d7e-f51c-11ef-822f-a8a159c28aa2','00bc3ad0-f51c-11ef-822f-a8a159c28aa2','00bc7f8f-f51c-11ef-822f-a8a159c28aa2','00bbe9b8-f51c-11ef-822f-a8a159c28aa2','00bc7c0d-f51c-11ef-822f-a8a159c28aa2','00bbe7a5-f51c-11ef-822f-a8a159c28aa2','00bc74d5-f51c-11ef-822f-a8a159c28aa2','00bc0352-f51c-11ef-822f-a8a159c28aa2','00bc666c-f51c-11ef-822f-a8a159c28aa2','00bc01ef-f51c-11ef-822f-a8a159c28aa2','00bc5fa4-f51c-11ef-822f-a8a159c28aa2','00bbfcf6-f51c-11ef-822f-a8a159c28aa2','00bcb044-f51c-11ef-822f-a8a159c28aa2','00bc0046-f51c-11ef-822f-a8a159c28aa2','00bbfeb4-f51c-11ef-822f-a8a159c28aa2','00bc65c2-f51c-11ef-822f-a8a159c28aa2','00bc6b0d-f51c-11ef-822f-a8a159c28aa2','00bca952-f51c-11ef-822f-a8a159c28aa2','00bc98e3-f51c-11ef-822f-a8a159c28aa2','00bc5073-f51c-11ef-822f-a8a159c28aa2','00bc1764-f51c-11ef-822f-a8a159c28aa2','00bc6499-f51c-11ef-822f-a8a159c28aa2','00bca90d-f51c-11ef-822f-a8a159c28aa2','00bc1e61-f51c-11ef-822f-a8a159c28aa2','00bcafb2-f51c-11ef-822f-a8a159c28aa2','00bcd68a-f51c-11ef-822f-a8a159c28aa2','00bc3841-f51c-11ef-822f-a8a159c28aa2','00bce31b-f51c-11ef-822f-a8a159c28aa2','00bc5527-f51c-11ef-822f-a8a159c28aa2','00bc28f5-f51c-11ef-822f-a8a159c28aa2','00bc788b-f51c-11ef-822f-a8a159c28aa2','00bc044c-f51c-11ef-822f-a8a159c28aa2','00bcad76-f51c-11ef-822f-a8a159c28aa2','00bc9d1e-f51c-11ef-822f-a8a159c28aa2','00bc3278-f51c-11ef-822f-a8a159c28aa2','00bc1cad-f51c-11ef-822f-a8a159c28aa2','00bc1852-f51c-11ef-822f-a8a159c28aa2','00bcb77c-f51c-11ef-822f-a8a159c28aa2','00bbf881-f51c-11ef-822f-a8a159c28aa2','00bc8052-f51c-11ef-822f-a8a159c28aa2','00bc99b7-f51c-11ef-822f-a8a159c28aa2','00bc179c-f51c-11ef-822f-a8a159c28aa2','00bc9f8a-f51c-11ef-822f-a8a159c28aa2','00bc819f-f51c-11ef-822f-a8a159c28aa2','00bc0488-f51c-11ef-822f-a8a159c28aa2','00bbf30e-f51c-11ef-822f-a8a159c28aa2','00bc3be5-f51c-11ef-822f-a8a159c28aa2','00bcc338-f51c-11ef-822f-a8a159c28aa2','00bc7d8e-f51c-11ef-822f-a8a159c28aa2','00bcaa0c-f51c-11ef-822f-a8a159c28aa2','00bc26c8-f51c-11ef-822f-a8a159c28aa2','00bbf181-f51c-11ef-822f-a8a159c28aa2','00bbf46a-f51c-11ef-822f-a8a159c28aa2','00bbe9e2-f51c-11ef-822f-a8a159c28aa2','00bc34a4-f51c-11ef-822f-a8a159c28aa2','00bc9fd2-f51c-11ef-822f-a8a159c28aa2','00bbf2a8-f51c-11ef-822f-a8a159c28aa2','00bcb08c-f51c-11ef-822f-a8a159c28aa2','00bc2210-f51c-11ef-822f-a8a159c28aa2','00bcb8ec-f51c-11ef-822f-a8a159c28aa2','00bbf639-f51c-11ef-822f-a8a159c28aa2','00bc3c65-f51c-11ef-822f-a8a159c28aa2','00bbeb0b-f51c-11ef-822f-a8a159c28aa2','00bce5c9-f51c-11ef-822f-a8a159c28aa2','00bc2b29-f51c-11ef-822f-a8a159c28aa2','00bc67d1-f51c-11ef-822f-a8a159c28aa2','00bcc841-f51c-11ef-822f-a8a159c28aa2','00bcc7c7-f51c-11ef-822f-a8a159c28aa2','00bc7d4f-f51c-11ef-822f-a8a159c28aa2','00bc1254-f51c-11ef-822f-a8a159c28aa2','00bc0857-f51c-11ef-822f-a8a159c28aa2','00bbf9ed-f51c-11ef-822f-a8a159c28aa2','00bcb106-f51c-11ef-822f-a8a159c28aa2','00bce927-f51c-11ef-822f-a8a159c28aa2','00bcc668-f51c-11ef-822f-a8a159c28aa2','00bbeab3-f51c-11ef-822f-a8a159c28aa2','00bce0ea-f51c-11ef-822f-a8a159c28aa2','00bc678f-f51c-11ef-822f-a8a159c28aa2','00bc1f4a-f51c-11ef-822f-a8a159c28aa2','00bcdf34-f51c-11ef-822f-a8a159c28aa2','00bbf061-f51c-11ef-822f-a8a159c28aa2','00bc04fb-f51c-11ef-822f-a8a159c28aa2','00bbeee9-f51c-11ef-822f-a8a159c28aa2','00bcc3e2-f51c-11ef-822f-a8a159c28aa2','00bc206c-f51c-11ef-822f-a8a159c28aa2','00bcc04e-f51c-11ef-822f-a8a159c28aa2','00bc091e-f51c-11ef-822f-a8a159c28aa2','00bc29b3-f51c-11ef-822f-a8a159c28aa2','00bbf539-f51c-11ef-822f-a8a159c28aa2','00bc34e1-f51c-11ef-822f-a8a159c28aa2','00bce8ae-f51c-11ef-822f-a8a159c28aa2','00bcb24d-f51c-11ef-822f-a8a159c28aa2','00bc79a8-f51c-11ef-822f-a8a159c28aa2','00bc2035-f51c-11ef-822f-a8a159c28aa2','00bc6b4f-f51c-11ef-822f-a8a159c28aa2','00bc645f-f51c-11ef-822f-a8a159c28aa2','00bc2282-f51c-11ef-822f-a8a159c28aa2','00bc7a20-f51c-11ef-822f-a8a159c28aa2','00bc66aa-f51c-11ef-822f-a8a159c28aa2','00bc1e99-f51c-11ef-822f-a8a159c28aa2','00bcc014-f51c-11ef-822f-a8a159c28aa2','00bc684b-f51c-11ef-822f-a8a159c28aa2','00bbf49e-f51c-11ef-822f-a8a159c28aa2','00bc6ad4-f51c-11ef-822f-a8a159c28aa2','00bc342f-f51c-11ef-822f-a8a159c28aa2','00bc6633-f51c-11ef-822f-a8a159c28aa2','00bcb46e-f51c-11ef-822f-a8a159c28aa2','00bca4c5-f51c-11ef-822f-a8a159c28aa2','00bce67a-f51c-11ef-822f-a8a159c28aa2','00bca548-f51c-11ef-822f-a8a159c28aa2','00bc287d-f51c-11ef-822f-a8a159c28aa2','00bcea2c-f51c-11ef-822f-a8a159c28aa2','00bc2e9a-f51c-11ef-822f-a8a159c28aa2','00bc32fd-f51c-11ef-822f-a8a159c28aa2','00bbf7aa-f51c-11ef-822f-a8a159c28aa2','00bc79e4-f51c-11ef-822f-a8a159c28aa2','00bcad33-f51c-11ef-822f-a8a159c28aa2','00bc3df2-f51c-11ef-822f-a8a159c28aa2','00bce3d3-f51c-11ef-822f-a8a159c28aa2','00bbfaf7-f51c-11ef-822f-a8a159c28aa2','00bc9ce0-f51c-11ef-822f-a8a159c28aa2','00bc3e2e-f51c-11ef-822f-a8a159c28aa2','00bc0fae-f51c-11ef-822f-a8a159c28aa2','00bcadc6-f51c-11ef-822f-a8a159c28aa2','00bcb878-f51c-11ef-822f-a8a159c28aa2','00bc31c6-f51c-11ef-822f-a8a159c28aa2','00bc9673-f51c-11ef-822f-a8a159c28aa2','00bca1b2-f51c-11ef-822f-a8a159c28aa2','00bcd432-f51c-11ef-822f-a8a159c28aa2','00bc2be4-f51c-11ef-822f-a8a159c28aa2','00bbfef6-f51c-11ef-822f-a8a159c28aa2','00bc6089-f51c-11ef-822f-a8a159c28aa2','00bbfb9a-f51c-11ef-822f-a8a159c28aa2','00bce1a3-f51c-11ef-822f-a8a159c28aa2','00bbf276-f51c-11ef-822f-a8a159c28aa2','00bbfa90-f51c-11ef-822f-a8a159c28aa2','00bc6511-f51c-11ef-822f-a8a159c28aa2','00bc6589-f51c-11ef-822f-a8a159c28aa2','00bc62de-f51c-11ef-822f-a8a159c28aa2','00bbf7df-f51c-11ef-822f-a8a159c28aa2','00bc1da4-f51c-11ef-822f-a8a159c28aa2','00bc1730-f51c-11ef-822f-a8a159c28aa2','00bc323e-f51c-11ef-822f-a8a159c28aa2','00bc2c1d-f51c-11ef-822f-a8a159c28aa2','00bc2cd9-f51c-11ef-822f-a8a159c28aa2','00bc81e9-f51c-11ef-822f-a8a159c28aa2','00bcb61a-f51c-11ef-822f-a8a159c28aa2','00bcde80-f51c-11ef-822f-a8a159c28aa2','00bc937f-f51c-11ef-822f-a8a159c28aa2','00bc8855-f51c-11ef-822f-a8a159c28aa2','00bc3a95-f51c-11ef-822f-a8a159c28aa2','00bc8465-f51c-11ef-822f-a8a159c28aa2','00bc50af-f51c-11ef-822f-a8a159c28aa2','00bc8910-f51c-11ef-822f-a8a159c28aa2','00bcb703-f51c-11ef-822f-a8a159c28aa2','00bc0b66-f51c-11ef-822f-a8a159c28aa2','00bc69e4-f51c-11ef-822f-a8a159c28aa2','00bbef47-f51c-11ef-822f-a8a159c28aa2','00bc6315-f51c-11ef-822f-a8a159c28aa2','00bcc302-f51c-11ef-822f-a8a159c28aa2','00bbe87d-f51c-11ef-822f-a8a159c28aa2','00bc5ef3-f51c-11ef-822f-a8a159c28aa2','00bbea0b-f51c-11ef-822f-a8a159c28aa2','00bc622d-f51c-11ef-822f-a8a159c28aa2','00bc11df-f51c-11ef-822f-a8a159c28aa2','00bcd4a3-f51c-11ef-822f-a8a159c28aa2','00bc0a1c-f51c-11ef-822f-a8a159c28aa2','00bccf83-f51c-11ef-822f-a8a159c28aa2','00bce9a9-f51c-11ef-822f-a8a159c28aa2','00bca7cd-f51c-11ef-822f-a8a159c28aa2','00bcdbf0-f51c-11ef-822f-a8a159c28aa2','00bc75ab-f51c-11ef-822f-a8a159c28aa2','00bc5e38-f51c-11ef-822f-a8a159c28aa2','00bc2701-f51c-11ef-822f-a8a159c28aa2','00bc6a96-f51c-11ef-822f-a8a159c28aa2','00bbf151-f51c-11ef-822f-a8a159c28aa2','00bc2602-f51c-11ef-822f-a8a159c28aa2','00bc87c9-f51c-11ef-822f-a8a159c28aa2','00bc0deb-f51c-11ef-822f-a8a159c28aa2','00bbf4d5-f51c-11ef-822f-a8a159c28aa2','00bc29ec-f51c-11ef-822f-a8a159c28aa2','00bcc4cb-f51c-11ef-822f-a8a159c28aa2','00bcd07c-f51c-11ef-822f-a8a159c28aa2','00bce165-f51c-11ef-822f-a8a159c28aa2','00bcb8b1-f51c-11ef-822f-a8a159c28aa2','00bc6a21-f51c-11ef-822f-a8a159c28aa2','00bbf2db-f51c-11ef-822f-a8a159c28aa2','00bce489-f51c-11ef-822f-a8a159c28aa2','00bcba2c-f51c-11ef-822f-a8a159c28aa2','00bcb38a-f51c-11ef-822f-a8a159c28aa2','00bcaef9-f51c-11ef-822f-a8a159c28aa2','00bce969-f51c-11ef-822f-a8a159c28aa2','00bc7c51-f51c-11ef-822f-a8a159c28aa2','00bc27fc-f51c-11ef-822f-a8a159c28aa2','00bbeebc-f51c-11ef-822f-a8a159c28aa2','00bca0dd-f51c-11ef-822f-a8a159c28aa2','00bc2aab-f51c-11ef-822f-a8a159c28aa2','00bc11a7-f51c-11ef-822f-a8a159c28aa2','00bbea33-f51c-11ef-822f-a8a159c28aa2','00bc7484-f51c-11ef-822f-a8a159c28aa2','00bca784-f51c-11ef-822f-a8a159c28aa2','00bc9ab3-f51c-11ef-822f-a8a159c28aa2','00bc68f6-f51c-11ef-822f-a8a159c28aa2','00bc3b12-f51c-11ef-822f-a8a159c28aa2','00bc1290-f51c-11ef-822f-a8a159c28aa2','00bc8890-f51c-11ef-822f-a8a159c28aa2','00bbf508-f51c-11ef-822f-a8a159c28aa2','00bc3eb2-f51c-11ef-822f-a8a159c28aa2','00bbf0f2-f51c-11ef-822f-a8a159c28aa2','00bc2334-f51c-11ef-822f-a8a159c28aa2','00bbed22-f51c-11ef-822f-a8a159c28aa2','00bc894c-f51c-11ef-822f-a8a159c28aa2','00bcc506-f51c-11ef-822f-a8a159c28aa2','00bc57c1-f51c-11ef-822f-a8a159c28aa2','00bc9928-f51c-11ef-822f-a8a159c28aa2','00bca37a-f51c-11ef-822f-a8a159c28aa2','00bca865-f51c-11ef-822f-a8a159c28aa2','00bcc3a9-f51c-11ef-822f-a8a159c28aa2','00bc9810-f51c-11ef-822f-a8a159c28aa2','00bce7bb-f51c-11ef-822f-a8a159c28aa2','00bc000e-f51c-11ef-822f-a8a159c28aa2','00bc0102-f51c-11ef-822f-a8a159c28aa2','00bc9f04-f51c-11ef-822f-a8a159c28aa2','00bc604e-f51c-11ef-822f-a8a159c28aa2','00bbeae2-f51c-11ef-822f-a8a159c28aa2','00bce872-f51c-11ef-822f-a8a159c28aa2','00bcaa84-f51c-11ef-822f-a8a159c28aa2','00bcb2e2-f51c-11ef-822f-a8a159c28aa2','00bc1034-f51c-11ef-822f-a8a159c28aa2','00bcdef9-f51c-11ef-822f-a8a159c28aa2','00bc3d29-f51c-11ef-822f-a8a159c28aa2','00bc0181-f51c-11ef-822f-a8a159c28aa2','00bc617f-f51c-11ef-822f-a8a159c28aa2','00bbf8ee-f51c-11ef-822f-a8a159c28aa2','00bc51ca-f51c-11ef-822f-a8a159c28aa2','00bc6f15-f51c-11ef-822f-a8a159c28aa2','00bbfe7d-f51c-11ef-822f-a8a159c28aa2','00bc3563-f51c-11ef-822f-a8a159c28aa2','00bc0412-f51c-11ef-822f-a8a159c28aa2','00bc3043-f51c-11ef-822f-a8a159c28aa2','00bc75f7-f51c-11ef-822f-a8a159c28aa2','00bc2681-f51c-11ef-822f-a8a159c28aa2','00bc61b8-f51c-11ef-822f-a8a159c28aa2','00bc0c6f-f51c-11ef-822f-a8a159c28aa2','00bbfd68-f51c-11ef-822f-a8a159c28aa2','00bc33ab-f51c-11ef-822f-a8a159c28aa2','00bc3e6c-f51c-11ef-822f-a8a159c28aa2','00bc7c91-f51c-11ef-822f-a8a159c28aa2','00bcd532-f51c-11ef-822f-a8a159c28aa2','00bc9f47-f51c-11ef-822f-a8a159c28aa2','00bc2d52-f51c-11ef-822f-a8a159c28aa2','00bbe8d2-f51c-11ef-822f-a8a159c28aa2','00bbe82a-f51c-11ef-822f-a8a159c28aa2','00bc8789-f51c-11ef-822f-a8a159c28aa2','00bc1943-f51c-11ef-822f-a8a159c28aa2','00bca9d0-f51c-11ef-822f-a8a159c28aa2','00bc23e9-f51c-11ef-822f-a8a159c28aa2','00bcb968-f51c-11ef-822f-a8a159c28aa2','00bc15d5-f51c-11ef-822f-a8a159c28aa2','00bbe57f-f51c-11ef-822f-a8a159c28aa2','00bc5157-f51c-11ef-822f-a8a159c28aa2','00bce601-f51c-11ef-822f-a8a159c28aa2','00bbefa6-f51c-11ef-822f-a8a159c28aa2','00bcc542-f51c-11ef-822f-a8a159c28aa2','00bc9dea-f51c-11ef-822f-a8a159c28aa2','00bc9e33-f51c-11ef-822f-a8a159c28aa2','00bce394-f51c-11ef-822f-a8a159c28aa2','00bce2dd-f51c-11ef-822f-a8a159c28aa2','00bc308c-f51c-11ef-822f-a8a159c28aa2','00bbf1dc-f51c-11ef-822f-a8a159c28aa2','00bc1c6e-f51c-11ef-822f-a8a159c28aa2','00bc6bc6-f51c-11ef-822f-a8a159c28aa2','00bc791e-f51c-11ef-822f-a8a159c28aa2','00bca505-f51c-11ef-822f-a8a159c28aa2','00bcb650-f51c-11ef-822f-a8a159c28aa2','00bc7dcc-f51c-11ef-822f-a8a159c28aa2','00bcdf73-f51c-11ef-822f-a8a159c28aa2','00bc7521-f51c-11ef-822f-a8a159c28aa2','00bc82dc-f51c-11ef-822f-a8a159c28aa2','00bc696f-f51c-11ef-822f-a8a159c28aa2','00bc9ec0-f51c-11ef-822f-a8a159c28aa2','00bc852c-f51c-11ef-822f-a8a159c28aa2','00bc3669-f51c-11ef-822f-a8a159c28aa2','00bcb7bb-f51c-11ef-822f-a8a159c28aa2','00bcb350-f51c-11ef-822f-a8a159c28aa2','00bc1d67-f51c-11ef-822f-a8a159c28aa2','00bc8f45-f51c-11ef-822f-a8a159c28aa2','00bc5390-f51c-11ef-822f-a8a159c28aa2','00bcb211-f51c-11ef-822f-a8a159c28aa2','00bccfc2-f51c-11ef-822f-a8a159c28aa2','00bce40d-f51c-11ef-822f-a8a159c28aa2','00bc089d-f51c-11ef-822f-a8a159c28aa2','00bbebba-f51c-11ef-822f-a8a159c28aa2','00bce7f5-f51c-11ef-822f-a8a159c28aa2','00bc33ee-f51c-11ef-822f-a8a159c28aa2','00bc6886-f51c-11ef-822f-a8a159c28aa2','00bc5354-f51c-11ef-822f-a8a159c28aa2','00bc36ac-f51c-11ef-822f-a8a159c28aa2','00bc69aa-f51c-11ef-822f-a8a159c28aa2','00bc70c4-f51c-11ef-822f-a8a159c28aa2','00bc38c8-f51c-11ef-822f-a8a159c28aa2','00bcc755-f51c-11ef-822f-a8a159c28aa2','00bc242d-f51c-11ef-822f-a8a159c28aa2','00bbf097-f51c-11ef-822f-a8a159c28aa2','00bc2743-f51c-11ef-822f-a8a159c28aa2','00bc7440-f51c-11ef-822f-a8a159c28aa2','00bbec10-f51c-11ef-822f-a8a159c28aa2','00bc0397-f51c-11ef-822f-a8a159c28aa2','00bc2d13-f51c-11ef-822f-a8a159c28aa2','00bc30cc-f51c-11ef-822f-a8a159c28aa2','00bc022a-f51c-11ef-822f-a8a159c28aa2','00bbefd7-f51c-11ef-822f-a8a159c28aa2','00bc8a98-f51c-11ef-822f-a8a159c28aa2','00bcd03c-f51c-11ef-822f-a8a159c28aa2','00bc2dd8-f51c-11ef-822f-a8a159c28aa2','00bcab54-f51c-11ef-822f-a8a159c28aa2','00bc874e-f51c-11ef-822f-a8a159c28aa2','00bbe751-f51c-11ef-822f-a8a159c28aa2','00bc2f13-f51c-11ef-822f-a8a159c28aa2','00bc0f6f-f51c-11ef-822f-a8a159c28aa2','00bc121a-f51c-11ef-822f-a8a159c28aa2','00bca401-f51c-11ef-822f-a8a159c28aa2','00bc531c-f51c-11ef-822f-a8a159c28aa2','00bc376e-f51c-11ef-822f-a8a159c28aa2','00bc8262-f51c-11ef-822f-a8a159c28aa2','00bc2371-f51c-11ef-822f-a8a159c28aa2','00bc32b7-f51c-11ef-822f-a8a159c28aa2','00bc814d-f51c-11ef-822f-a8a159c28aa2','00bc0d23-f51c-11ef-822f-a8a159c28aa2','00bc7b5a-f51c-11ef-822f-a8a159c28aa2','00bcc45c-f51c-11ef-822f-a8a159c28aa2','00bbfd2f-f51c-11ef-822f-a8a159c28aa2','00bc5451-f51c-11ef-822f-a8a159c28aa2','00bc84a1-f51c-11ef-822f-a8a159c28aa2','00bbee5b-f51c-11ef-822f-a8a159c28aa2','00bcd5a1-f51c-11ef-822f-a8a159c28aa2','00bcb31a-f51c-11ef-822f-a8a159c28aa2','00bc7768-f51c-11ef-822f-a8a159c28aa2','00bc53ca-f51c-11ef-822f-a8a159c28aa2','00bbfb2d-f51c-11ef-822f-a8a159c28aa2','00bca6fa-f51c-11ef-822f-a8a159c28aa2','00bc8f08-f51c-11ef-822f-a8a159c28aa2','00bcdc32-f51c-11ef-822f-a8a159c28aa2','00bcdce3-f51c-11ef-822f-a8a159c28aa2','00bcd46b-f51c-11ef-822f-a8a159c28aa2','00bc18c5-f51c-11ef-822f-a8a159c28aa2','00bc1683-f51c-11ef-822f-a8a159c28aa2','00bcb0d1-f51c-11ef-822f-a8a159c28aa2','00bca3c2-f51c-11ef-822f-a8a159c28aa2','00bca016-f51c-11ef-822f-a8a159c28aa2','00bc65fe-f51c-11ef-822f-a8a159c28aa2','00bc7e4c-f51c-11ef-822f-a8a159c28aa2','00bc601a-f51c-11ef-822f-a8a159c28aa2','00bc3629-f51c-11ef-822f-a8a159c28aa2','00bc5563-f51c-11ef-822f-a8a159c28aa2','00bc3b5a-f51c-11ef-822f-a8a159c28aa2','00bce354-f51c-11ef-822f-a8a159c28aa2','00bc85af-f51c-11ef-822f-a8a159c28aa2','00bc1cea-f51c-11ef-822f-a8a159c28aa2','00bc574b-f51c-11ef-822f-a8a159c28aa2','00bccb16-f51c-11ef-822f-a8a159c28aa2','00bbe64d-f51c-11ef-822f-a8a159c28aa2','00bc93bc-f51c-11ef-822f-a8a159c28aa2','00bc35e9-f51c-11ef-822f-a8a159c28aa2','00bc63c5-f51c-11ef-822f-a8a159c28aa2','00bcc719-f51c-11ef-822f-a8a159c28aa2','00bbebe4-f51c-11ef-822f-a8a159c28aa2','00bc2644-f51c-11ef-822f-a8a159c28aa2','00bcbdfb-f51c-11ef-822f-a8a159c28aa2','00bc1fbf-f51c-11ef-822f-a8a159c28aa2','00bbed7b-f51c-11ef-822f-a8a159c28aa2','00bbe95c-f51c-11ef-822f-a8a159c28aa2','00bbec3d-f51c-11ef-822f-a8a159c28aa2','00bc2f51-f51c-11ef-822f-a8a159c28aa2','00bcd2cd-f51c-11ef-822f-a8a159c28aa2','00bc8994-f51c-11ef-822f-a8a159c28aa2','00bcb83b-f51c-11ef-822f-a8a159c28aa2','00bc5f2a-f51c-11ef-822f-a8a159c28aa2','00bc880f-f51c-11ef-822f-a8a159c28aa2','00bc8be5-f51c-11ef-822f-a8a159c28aa2','00bce297-f51c-11ef-822f-a8a159c28aa2','00bca6c3-f51c-11ef-822f-a8a159c28aa2','00bc5e76-f51c-11ef-822f-a8a159c28aa2','00bcac01-f51c-11ef-822f-a8a159c28aa2','00bcd9a0-f51c-11ef-822f-a8a159c28aa2','00bc52df-f51c-11ef-822f-a8a159c28aa2','00bbeb3b-f51c-11ef-822f-a8a159c28aa2','00bcd346-f51c-11ef-822f-a8a159c28aa2','00bc76d1-f51c-11ef-822f-a8a159c28aa2','00bc2249-f51c-11ef-822f-a8a159c28aa2','00bc13e1-f51c-11ef-822f-a8a159c28aa2','00bbf0c3-f51c-11ef-822f-a8a159c28aa2','00bcb3c3-f51c-11ef-822f-a8a159c28aa2','00bc197f-f51c-11ef-822f-a8a159c28aa2','00bce830-f51c-11ef-822f-a8a159c28aa2','00bce073-f51c-11ef-822f-a8a159c28aa2','00bc7a9a-f51c-11ef-822f-a8a159c28aa2','00bbe7ff-f51c-11ef-822f-a8a159c28aa2','00bc7f4d-f51c-11ef-822f-a8a159c28aa2','00bc3189-f51c-11ef-822f-a8a159c28aa2','00bcaf7a-f51c-11ef-822f-a8a159c28aa2','00bcdd1b-f51c-11ef-822f-a8a159c28aa2','00bc7ec2-f51c-11ef-822f-a8a159c28aa2','00bbffd9-f51c-11ef-822f-a8a159c28aa2','00bcb436-f51c-11ef-822f-a8a159c28aa2','00bc0fef-f51c-11ef-822f-a8a159c28aa2','00bbf60a-f51c-11ef-822f-a8a159c28aa2','00bc70ff-f51c-11ef-822f-a8a159c28aa2','00bbfa25-f51c-11ef-822f-a8a159c28aa2','00bce775-f51c-11ef-822f-a8a159c28aa2','00bc6932-f51c-11ef-822f-a8a159c28aa2','00bc78d3-f51c-11ef-822f-a8a159c28aa2','00bcc87e-f51c-11ef-822f-a8a159c28aa2','00bbe67a-f51c-11ef-822f-a8a159c28aa2','00bc3a0d-f51c-11ef-822f-a8a159c28aa2','00bbf816-f51c-11ef-822f-a8a159c28aa2','00bc7688-f51c-11ef-822f-a8a159c28aa2','00bc9787-f51c-11ef-822f-a8a159c28aa2','00bbe604-f51c-11ef-822f-a8a159c28aa2','00bcb18f-f51c-11ef-822f-a8a159c28aa2','00bcda23-f51c-11ef-822f-a8a159c28aa2','00bc031b-f51c-11ef-822f-a8a159c28aa2','00bbf6a8-f51c-11ef-822f-a8a159c28aa2','00bc390a-f51c-11ef-822f-a8a159c28aa2','00bc7adc-f51c-11ef-822f-a8a159c28aa2','00bc3ca5-f51c-11ef-822f-a8a159c28aa2','00bbf70d-f51c-11ef-822f-a8a159c28aa2','00bcae08-f51c-11ef-822f-a8a159c28aa2','00bc3c25-f51c-11ef-822f-a8a159c28aa2','00bcdc6a-f51c-11ef-822f-a8a159c28aa2','00bbf846-f51c-11ef-822f-a8a159c28aa2','00bc763e-f51c-11ef-822f-a8a159c28aa2','00bc511b-f51c-11ef-822f-a8a159c28aa2','00bc351d-f51c-11ef-822f-a8a159c28aa2','00bcd0b7-f51c-11ef-822f-a8a159c28aa2','00bca58a-f51c-11ef-822f-a8a159c28aa2','00bcc62b-f51c-11ef-822f-a8a159c28aa2','00bbed4f-f51c-11ef-822f-a8a159c28aa2','00bc9339-f51c-11ef-822f-a8a159c28aa2','00bc23b0-f51c-11ef-822f-a8a159c28aa2','00bcae7c-f51c-11ef-822f-a8a159c28aa2','00bc8012-f51c-11ef-822f-a8a159c28aa2','00bbe6cd-f51c-11ef-822f-a8a159c28aa2','00bccc0a-f51c-11ef-822f-a8a159c28aa2','00bbeb65-f51c-11ef-822f-a8a159c28aa2','00bc8395-f51c-11ef-822f-a8a159c28aa2','00bc1ddd-f51c-11ef-822f-a8a159c28aa2','00bc2e1b-f51c-11ef-822f-a8a159c28aa2','00bc1109-f51c-11ef-822f-a8a159c28aa2','00bc9092-f51c-11ef-822f-a8a159c28aa2','00bcb3fc-f51c-11ef-822f-a8a159c28aa2','00bc3009-f51c-11ef-822f-a8a159c28aa2','00bbf98c-f51c-11ef-822f-a8a159c28aa2','00bbf1b0-f51c-11ef-822f-a8a159c28aa2','00bc5886-f51c-11ef-822f-a8a159c28aa2','00bc671b-f51c-11ef-822f-a8a159c28aa2','00bce1de-f51c-11ef-822f-a8a159c28aa2','00bbe7d8-f51c-11ef-822f-a8a159c28aa2','00bcdfed-f51c-11ef-822f-a8a159c28aa2','00bcc5ec-f51c-11ef-822f-a8a159c28aa2','00bc3f3e-f51c-11ef-822f-a8a159c28aa2','00bcd64b-f51c-11ef-822f-a8a159c28aa2','00bcbd82-f51c-11ef-822f-a8a159c28aa2','00bcb923-f51c-11ef-822f-a8a159c28aa2','00bcbf62-f51c-11ef-822f-a8a159c28aa2','00bbee06-f51c-11ef-822f-a8a159c28aa2','00bc7b95-f51c-11ef-822f-a8a159c28aa2','00bc634c-f51c-11ef-822f-a8a159c28aa2','00bc2baa-f51c-11ef-822f-a8a159c28aa2','00bcabc8-f51c-11ef-822f-a8a159c28aa2','00bc0cae-f51c-11ef-822f-a8a159c28aa2','00bc2fcf-f51c-11ef-822f-a8a159c28aa2','00bc7140-f51c-11ef-822f-a8a159c28aa2','00bc3202-f51c-11ef-822f-a8a159c28aa2','00bc626a-f51c-11ef-822f-a8a159c28aa2','00bc6406-f51c-11ef-822f-a8a159c28aa2','00bc398a-f51c-11ef-822f-a8a159c28aa2','00bbf004-f51c-11ef-822f-a8a159c28aa2','00bc8cfb-f51c-11ef-822f-a8a159c28aa2','00bc8a18-f51c-11ef-822f-a8a159c28aa2','00bcd9de-f51c-11ef-822f-a8a159c28aa2','00bc01ba-f51c-11ef-822f-a8a159c28aa2','00bc9050-f51c-11ef-822f-a8a159c28aa2','00bc8111-f51c-11ef-822f-a8a159c28aa2','00bce25b-f51c-11ef-822f-a8a159c28aa2','00bce21d-f51c-11ef-822f-a8a159c28aa2','00bc7cd4-f51c-11ef-822f-a8a159c28aa2','00bc8fc6-f51c-11ef-822f-a8a159c28aa2','00bbecf6-f51c-11ef-822f-a8a159c28aa2','00bce44b-f51c-11ef-822f-a8a159c28aa2','00bbecc1-f51c-11ef-822f-a8a159c28aa2','00bc1f88-f51c-11ef-822f-a8a159c28aa2','00bc97ce-f51c-11ef-822f-a8a159c28aa2','00bc56de-f51c-11ef-822f-a8a159c28aa2','00bbea5f-f51c-11ef-822f-a8a159c28aa2','00bc831d-f51c-11ef-822f-a8a159c28aa2','00bcc423-f51c-11ef-822f-a8a159c28aa2','00bcdfb1-f51c-11ef-822f-a8a159c28aa2','00bc98aa-f51c-11ef-822f-a8a159c28aa2','00bc0ce7-f51c-11ef-822f-a8a159c28aa2','00bbfa56-f51c-11ef-822f-a8a159c28aa2','00bc3337-f51c-11ef-822f-a8a159c28aa2','00bc77fe-f51c-11ef-822f-a8a159c28aa2','00bc10ae-f51c-11ef-822f-a8a159c28aa2','00bc5195-f51c-11ef-822f-a8a159c28aa2','00bc1142-f51c-11ef-822f-a8a159c28aa2','00bcd0ef-f51c-11ef-822f-a8a159c28aa2','00bca5bf-f51c-11ef-822f-a8a159c28aa2','00bc548a-f51c-11ef-822f-a8a159c28aa2','00bbf23f-f51c-11ef-822f-a8a159c28aa2','00bbfac4-f51c-11ef-822f-a8a159c28aa2','00bc3ce5-f51c-11ef-822f-a8a159c28aa2','00bc014b-f51c-11ef-822f-a8a159c28aa2','00bcbee6-f51c-11ef-822f-a8a159c28aa2','00bc1373-f51c-11ef-822f-a8a159c28aa2','00bc3733-f51c-11ef-822f-a8a159c28aa2','00bc346b-f51c-11ef-822f-a8a159c28aa2','00bc578a-f51c-11ef-822f-a8a159c28aa2','00bbec66-f51c-11ef-822f-a8a159c28aa2','00bc9855-f51c-11ef-822f-a8a159c28aa2','00bcd30b-f51c-11ef-822f-a8a159c28aa2','00bcacbf-f51c-11ef-822f-a8a159c28aa2','00bc8ecb-f51c-11ef-822f-a8a159c28aa2','00bc62a8-f51c-11ef-822f-a8a159c28aa2','00bc1176-f51c-11ef-822f-a8a159c28aa2','00bc09e0-f51c-11ef-822f-a8a159c28aa2','00bca99c-f51c-11ef-822f-a8a159c28aa2','00bce02d-f51c-11ef-822f-a8a159c28aa2','00bbe6f9-f51c-11ef-822f-a8a159c28aa2','00bccb5d-f51c-11ef-822f-a8a159c28aa2','00bbf9be-f51c-11ef-822f-a8a159c28aa2','00bc88d4-f51c-11ef-822f-a8a159c28aa2','00bc04c5-f51c-11ef-822f-a8a159c28aa2','00bc7bd3-f51c-11ef-822f-a8a159c28aa2','00bcdd59-f51c-11ef-822f-a8a159c28aa2','00bc8f86-f51c-11ef-822f-a8a159c28aa2','00bc9a38-f51c-11ef-822f-a8a159c28aa2','00bc57fb-f51c-11ef-822f-a8a159c28aa2','00bce737-f51c-11ef-822f-a8a159c28aa2','00bc829f-f51c-11ef-822f-a8a159c28aa2','00bcb6cd-f51c-11ef-822f-a8a159c28aa2','00bbee31-f51c-11ef-822f-a8a159c28aa2','00bcaa51-f51c-11ef-822f-a8a159c28aa2','00bc1073-f51c-11ef-822f-a8a159c28aa2','00bca09f-f51c-11ef-822f-a8a159c28aa2','00bcbfa3-f51c-11ef-822f-a8a159c28aa2','00bc3371-f51c-11ef-822f-a8a159c28aa2','00bc56a5-f51c-11ef-822f-a8a159c28aa2','00bc6ed6-f51c-11ef-822f-a8a159c28aa2','00bc15a3-f51c-11ef-822f-a8a159c28aa2','00bcd165-f51c-11ef-822f-a8a159c28aa2','00bce9e9-f51c-11ef-822f-a8a159c28aa2'];
        $xmlIds = [106601, 106592, 106593, 106594];
        $xmlIds = [97293];

        $filter = ['IBLOCK_ID' => $this->catalogIblockId, "!PROPERTY_IS_UPDATED" => 1];
        $filter = ['IBLOCK_ID' => $this->catalogIblockId, "!PROPERTY_IS_UPDATED" => 1, 'ID' => $xmlIds];
//        $filter = ['IBLOCK_ID' => $this->catalogIblockId, "XML_ID" => $xmlIds];
        $select = ['ID', 'NAME', 'XML_ID', 'PROPERTY_CA_ID', 'PROPERTY_HIT'];
        $dbl = CIBlockElement::GetList(["ID" => "ASC"], $filter, false, [], $select);
        while ($arItem = $dbl->Fetch()) {
            $this->arCatalogDataByXML_ID[$arItem["XML_ID"]] = $arItem;

            if (in_array($arItem['ID'], [97293])) {
                print_r($arItem);
                echo "\r\n";
            }
        }

        echo 'Not processing products: '.count($this->arCatalogDataByXML_ID)."\r\n";
//        die();

        /* $arCurCOGoodsId = [];
        $sql = "SELECT GOODS_SITE_ID FROM catalog_app_data";
        $res = $this->connection->query($sql);
        while ($arItem = $res->fetch()) {
            $arCurCOGoodsId[$arItem["GOODS_SITE_ID"]] = $arItem["GOODS_SITE_ID"];
        }

        if (!empty($arCurCOGoodsId)) {
            $arChunks = array_chunk($arCurCOGoodsId, 1000);

            foreach ($arChunks as $chunk) {
                $filter = ['IBLOCK_ID' => $this->catalogIblockId, "ID" => $chunk];
                $select = ['ID', 'NAME', 'XML_ID'];
                $dbl = CIBlockElement::GetList(["ID" => "ASC"], $filter, false, false, $select);
                while ($arItem = $dbl->Fetch()) {
                    if(!empty($arItem["XML_ID"]) && $arItem["XML_ID"] != $arItem["ID"]) {
                        $this->arCatalogDataByXML_ID[$arItem["XML_ID"]] = $arItem;
                    }
                }
            }
        } */

        $this->Logger->log("LOG", "Получено, всего товаров: ".count($this->arCatalogDataByXML_ID));
        $this->EndDebugTime(__FUNCTION__);
    }

    private function PrepareMainProperties() {
        $this->Logger->log("LOG", "Проверям созданы ли основные свойства");
        $this->StartDebugTime(__FUNCTION__);

        foreach ($this->arCAProperties as $code => $arItem) {
            $arItem["IBLOCK_ID"] = $this->catalogIblockId;
            $properties = CIBlockProperty::GetList(["name" => "asc"], ["IBLOCK_ID" => $this->catalogIblockId, "CODE" => $code]);
            if(!$arProp = $properties->GetNext()) {
                $this->CreateProperty($arItem);
            } else {
                $this->arCASiteProperties[$arProp["CODE"]] = $arProp;
            }
        }

        $this->EndDebugTime(__FUNCTION__);
    }

    private function GetCatalogProperties() {
        $this->Logger->log("LOG", "Получаем свойства каталога");
        $this->StartDebugTime(__FUNCTION__);

        $arItem["IBLOCK_ID"] = $this->catalogIblockId;
        $properties = CIBlockProperty::GetList(["name" => "asc"], ["IBLOCK_ID" => $this->catalogIblockId]);
        while($arProp = $properties->GetNext()) {
            $this->arCASiteProperties[$arProp["CODE"]] = $arProp;

            if ($arProp["PROPERTY_TYPE"] == "L") {
                $property_enums = CIBlockPropertyEnum::GetList(
                    ["SORT" => "ASC"],
                    ["IBLOCK_ID" => $this->catalogIblockId, "CODE" => $arProp["CODE"]]
                );
                while ($arEnum = $property_enums->GetNext()) {
                    $this->arCASiteProperties[$arProp["CODE"]]["ENUMS"][$arEnum["XML_ID"]] = $arEnum;
                }
            }
        }

        $this->Logger->log("LOG", "Всего получено ".count($this->arCASiteProperties));
        $this->EndDebugTime(__FUNCTION__);
    }

    private function CreateProperty ($arData = [], $sectionId = 0) {
        $this->Logger->log("LOG", "Создание свойства ".$arData["NAME"]);

        $this->StartDebugTime(__FUNCTION__);

        $iblockProperty = new CIBlockProperty;

        if ($propertyID = $iblockProperty->Add($arData)) {
            \Bitrix\Iblock\Model\PropertyFeature::setFeatures($propertyID, [
                ["FEATURE_ID"=>"DETAIL_PAGE_SHOW", "IS_ENABLED" => "Y", "MODULE_ID" => "iblock"],
                ["FEATURE_ID"=>"LIST_PAGE_SHOW", "IS_ENABLED" => "Y", "MODULE_ID" => "iblock"]
            ]);

            $property = CIBlockProperty::GetByID($propertyID, $this->catalogIblockId)->GetNext();
            $this->arCASiteProperties[$property["CODE"]] = $property;
            $this->Logger->log("LOG", "Свойство ".$arData["NAME"]." добавлено");

            if ($arData["PROPERTY_TYPE"] == "L" && !empty($sectionId)) {
                $this->Logger->log("LOG", "Списочное свойство, добавляем ззначения");

                $expld_code = explode("_", $property["CODE"]);
                $CAPropId = end($expld_code);

                if (empty($this->arCASectionProperties[$sectionId]["PROPERTIES"][$CAPropId]["enumValues"])) {
                    $this->Logger->log("LOG", "Нет значений для добавления");
                } else {
                    foreach ($this->arCASectionProperties[$sectionId]["PROPERTIES"][$CAPropId]["enumValues"] as $arEnum) {
                        $this->CreatEnum($this->arCASiteProperties[$property["CODE"]], $arEnum);
                    }

                    $property_enums = CIBlockPropertyEnum::GetList(
                        ["SORT" => "ASC"],
                        ["IBLOCK_ID" => $this->catalogIblockId, "CODE" => $property["CODE"]]
                    );
                    while ($arEnum = $property_enums->GetNext()) {
                        $this->arCASiteProperties[$property["CODE"]]["ENUMS"][$arEnum["XML_ID"]] = $arEnum;
                    }
                }

                if (!empty($sectionId)) {
                    $this->Logger->log("LOG", "Добавляем привязку свойства ".$this->arCASiteProperties[$property["CODE"]]["NAME"]." к разделу ".$this->arCASectionProperties[$sectionId]["NAME"]);

                    CIBlockSectionPropertyLink::Add($this->sectionConnections[$sectionId], $propertyID, ["IBLOCK_ID" => $this->catalogIblockId]);

                    /*$sql = "INSERT INTO b_iblock_section_property (`IBLOCK_ID`, `SECTION_ID`, `PROPERTY_ID`, `SMART_FILTER`) VALUES
                            ('".$this->catalogIblockId."', '".$this->sectionConnections[$sectionId]."', '".$propertyID."', 'N')";
                    $this->connection->query($sql);*/
                }
            }
        } else {
            $this->Logger->log("ERROR", "Ошибка при создании свойства ".$iblockProperty->LAST_ERROR."\r\n".print_r($arData, true));
        }

        $this->EndDebugTime(__FUNCTION__);
    }

    private function DeleteProperty ($propertyId = 0)
    {
        $this->Logger->log("LOG", "Удаляем свойство товара $propertyId");
        $this->StartDebugTime(__FUNCTION__);

        if (empty($propertyId)) {
            $this->Logger->log("LOG", "Не указан id свойства");
            $this->EndDebugTime(__FUNCTION__);
            return;
        }

        if (!CIBlockProperty::Delete($propertyId)) {
            $this->Logger->log("ERROR", "Не удалось удалть свойство");
        }

        $this->EndDebugTime(__FUNCTION__);
    }

    private function CreateLinkedEnum($property, $arProp, $value) {
        $unitDescription = $this->arCAUnits[$property["unit"]]["SHORT_NAME"];

        if ($property['type'] =='Boolean') {
            $value = $value ? 'Да' : 'Нет';
        }

        $value = str_replace(['"'], "", $value);
        $value = htmlspecialchars($value);

        $pVal = $this->arCASiteProperties[$property["code"]]["ENUMS"][$value]["VALUE"];
        $expldVal = explode("—", $pVal);

        if (empty($arProp["enumValue"])) {
            $arProp["enumValue"]['value'] = $arProp["enumValue"]['id'] = $value;
        }

        if (!empty($unitDescription)) {
            $arProp["enumValue"]["value"] .= " ".$unitDescription;
        }

        if (empty($this->arCASiteProperties[$property["code"]]["ENUMS"][(string)$value])) {
            $this->CreatEnum($this->arCASiteProperties[$property["code"]], $arProp["enumValue"],true);
        } else {
            $this->UpdateEnumValue($this->arCASiteProperties[$property["code"]]["ENUMS"][$value], $arProp["enumValue"]);
        }

        return $this->arCASiteProperties[$property["code"]]["ENUMS"][$value]["ID"];
    }

    private function CreatEnum ($property = [], $enumData = [], $getEnumFlag = false) {
        $this->Logger->log("LOG", "Добавляем значение свойства {$enumData["value"]}");
        $this->StartDebugTime(__FUNCTION__);

        if (empty($property)) {
            $this->Logger->log("LOG", "Нет данных о свойстве");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        if (empty($enumData)) {
            $this->Logger->log("LOG", "Нет данных о значении");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        $ibpenum = new CIBlockPropertyEnum;

        $arFields = [
            'PROPERTY_ID' => $property["ID"],
            'VALUE' => $enumData["value"],
            'XML_ID' => $enumData['id']
        ];

        if (isset($enumData["DEF"])) {
            $arFields["DEF"] = $enumData["DEF"];
        }

        if ($ibpenum->Add($arFields)) {
            $this->Logger->log("LOG", "Значение добавлено");
        } else {
            $this->Logger->log("ERROR", "Ошибка при добавлении значения свойства \r\n".print_r($ibpenum->LAST_ERROR, true));
            $this->Logger->log("ERROR", "\r\n".print_r($arFields, true));
        }

        if ($getEnumFlag) {
            $property_enums = CIBlockPropertyEnum::GetList(
                ["SORT" => "ASC"],
                ["IBLOCK_ID" => $this->catalogIblockId, "CODE" => $property["CODE"]]
            );
            while ($arEnum = $property_enums->GetNext()) {
                $this->arCASiteProperties[$property["CODE"]]["ENUMS"][$arEnum["XML_ID"]] = $arEnum;
            }
        }

        $this->EndDebugTime(__FUNCTION__);
    }

    private function UpdateEnumValue ($property, $enumData = []) {
        $this->Logger->log("LOG", "Обновляем значение '{$enumData["value"]}' свойства {$property["ID"]}");
        $this->StartDebugTime(__FUNCTION__);

        if (empty($enumData)) {
            $this->Logger->log("LOG", "Нет данных о значении");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        $ibpenum = new CIBlockPropertyEnum;

        if ($ibpenum->Update($property["ID"], Array('VALUE' => $enumData["value"]))) {
            $this->Logger->log("LOG", "Значение '{$enumData["value"]}' свойства {$property["ID"]} обновлено");
        } else {
            $this->Logger->log("ERROR", "Ошибка при Обновлении значения свойства \r\n".print_r($ibpenum->LAST_ERROR, true));
        }

        $this->EndDebugTime(__FUNCTION__);
    }

    private function CheckPropertySection ($sectionId = 0, $propertyId = 0, $propertyName = '') {
        $this->Logger->log("LOG", "Проверяем привязку свойства [{$propertyId}] '{$propertyName}' к разделу [{$sectionId}]");
        $this->StartDebugTime(__FUNCTION__);

        if (empty($sectionId)) {
            $this->Logger->log("LOG", "Нет id раздела");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        if (empty($propertyId)) {
            $this->Logger->log("LOG", "Нет id свойства");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        if (empty($this->sectionConnections[$sectionId])) {
            $this->Logger->log("LOG", "Нет привязки к разделу");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        $sql = "SELECT SECTION_ID FROM b_iblock_section_property WHERE SECTION_ID = '".$this->sectionConnections[$sectionId]."' AND PROPERTY_ID = '".$propertyId."' AND IBLOCK_ID = '".$this->catalogIblockId."'";
        $res = $this->connection->query($sql);
        if (!$arItem = $res->fetch()) {
            $this->Logger->log("LOG", "Добавляем привязку свойства [{$propertyId}] '{$propertyName}' к разделу ".$this->arCASectionProperties[$sectionId]["NAME"]);

            $sql = "INSERT INTO b_iblock_section_property (`IBLOCK_ID`, `SECTION_ID`, `PROPERTY_ID`, `SMART_FILTER`) VALUES 
                        ('".$this->catalogIblockId."', '".$this->sectionConnections[$sectionId]."', '".$propertyId."', 'N')";
            $this->connection->query($sql);
        } else {
            $this->Logger->log("LOG", "Обновлять не нужно");
        }

        $this->EndDebugTime(__FUNCTION__);
    }

    private function UpdateProperties() {
        $this->Logger->log("LOG", "Получаем свойства товаров");
        $this->StartDebugTime(__FUNCTION__);
        $el = new CIBlockElement;
        $counter = 0;

        if (empty($this->arModels) || empty($this->arCatalogDataByXML_ID)) {
            $this->Logger->log("LOG", "Нет товаров для обработки");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        $arMainFiles = [];
//        $duplicatedProps = [];
//        $propsByCode = [];
//        $propWithoutCode = [];
//        $catalogMeasure = [];

//        $res_measure = CCatalogMeasure::getList();
//        while($measure = $res_measure->Fetch()) {
//            $catalogMeasure[$measure['SYMBOL_RUS']] = $measure['ID'];
//        }

        foreach ($this->arCatalogDataByXML_ID as $xmlId => $arItem) {
            $arActivationRules = [
                "IMAGES" => false,
                "PROPERTIES" => false
            ];

            if (empty($this->arModelsByXML_ID[$xmlId])) {
                $this->Logger->log("LOG", "В catalog.app нет такой модели [".$arItem["ID"]."] {$arItem["NAME"]}");
                CIBlockElement::SetPropertyValuesEx($arItem["ID"], 81, ['IS_UPDATED' => 1]);
                continue;
            }

            if (empty($this->arModelsByXML_ID[$xmlId]['name'])) {
                $this->Logger->log("LOG", "У товара [".$arItem["ID"]."] нет названия, пропускаем");
                CIBlockElement::SetPropertyValuesEx($arItem["ID"], 81, ['IS_UPDATED' => 1]);
                continue;
            }

            $this->Logger->log("LOG", "Получение свойств товара [".$arItem["ID"]."] ".$arItem["NAME"]);
            $arModelProperties = $this->restAPI->getModelProperties($this->arModelsByXML_ID[$xmlId]["id"]);

            if (!empty($arModelProperties["ERROR"])) {
                $this->Logger->log("ERROR", "Ошибка при получении свойств! ".$arModelProperties["ERROR"]);
                continue;
            }

            $modelSectionId = $arModelProperties["category"]["id"];

            if (empty($this->arCASectionProperties[$modelSectionId])) {
                $this->Logger->log("LOG", "Получаем категории раздела из catalog.app");
                $arSectionPropTMP = $this->restAPI->getCategoryById($modelSectionId);

//                $parentSectionId = $arSectionPropTMP["parentId"];

                if (!empty($arSectionPropTMP["ERROR"])) {
                    $this->Logger->log("ERROR", "Ошибка при получении свойств раздела! ".$arSectionPropTMP["ERROR"]);
                } else {
                    $this->arCASectionProperties[$modelSectionId]["ID"] = $arSectionPropTMP["id"];
                    $this->arCASectionProperties[$modelSectionId]["NAME"] = $arSectionPropTMP["name"];
                    $this->arCASectionProperties[$modelSectionId]["SINGULAR_NAME"] = $arSectionPropTMP["singularName"];

                    foreach ($arSectionPropTMP["properties"] as $arProp) {
                        $this->arCASectionProperties[$modelSectionId]["PROPERTIES"][$arProp["id"]] = $arProp;
                    }

                    foreach ($arSectionPropTMP["propertyGroups"] as $arPropGroup) {
                        $this->arCASectionProperties[$modelSectionId]["PROPERTIES_GROUP"][$arPropGroup["id"]] = $arPropGroup;
                    }
                }
            }

            $arLoadProductArray = [];
            $PROP = [];
            $PROP["CA_ID"] = $arModelProperties["id"];

//            if (!empty($arModelProperties['description']['value'])) {
//                $arLoadProductArray["DETAIL_TEXT_TYPE"] = 'text';
//
//                if (strip_tags($arModelProperties['description']['value']) !== $arModelProperties['description']['value']) {
//                    $arLoadProductArray["DETAIL_TEXT_TYPE"] = 'html';
//                }
//
//                $arLoadProductArray["DETAIL_TEXT"] = $arModelProperties['description']['value'];
//            }

            if (!empty($arModelProperties["images"])) {
                $cropPath = "https://catalog.app/images/crop?url=";

                foreach ($arModelProperties["images"] as $k => $image) {
//                    $url = $cropPath.$image;
                    $url = $image;
//                    $ext = substr($url, -3);
                    $ext = "png";

                    /*if (strstr($image, ".unknown") || strstr($image, ".ebp") || strstr($image, ".webp")) {
                        $url = $image;
                        $ext = "png";
                    } elseif (strstr($image, ".png")) {
                        $url = $image;
                    }*/

                    if (!file_exists($_SERVER["DOCUMENT_ROOT"].'/upload/tmp_image/')) {
                        mkdir($_SERVER["DOCUMENT_ROOT"].'/upload/tmp_image/', 0755);
                    }

                    $local = "/upload/tmp_image/".md5(date("d.m.Y H:i:s"))."_{$arModelProperties["id"]}_{$k}.{$ext}";

                    file_put_contents($_SERVER["DOCUMENT_ROOT"].$local, file_get_contents($url));

                    /*if ($this->ConvertToWebp($url, $local, $ext)) {
                        $local = str_replace($ext, "webp", $local);
                    } else {
                        file_put_contents($_SERVER["DOCUMENT_ROOT"].$local, file_get_contents($url));
                    }*/

                    if (file_exists($_SERVER["DOCUMENT_ROOT"].$local)) {
                        $arModelProperties["images"][$k] = $local;
                    }
                }

                $arMainFiles[] = $arModelProperties["images"][0];
                $mainImage = CFile::MakeFileArray($arModelProperties["images"][0]);
                $arLoadProductArray["DETAIL_PICTURE"] = $arLoadProductArray["PREVIEW_PICTURE"] = $mainImage;

                foreach ($arModelProperties["images"] as $k => $item) {
                    if (empty($this->arCASiteProperties["MORE_PHOTO"])) {
                        $this->CreateProperty($this->arCAProperties["MORE_PHOTO"], 0);
                    }

                    $PROP[$this->arCASiteProperties["MORE_PHOTO"]["ID"]][] = CFile::MakeFileArray($item);
                }

                if (count($PROP[$this->arCASiteProperties["MORE_PHOTO"]["ID"]]) >= $this->arCASettings["AUTO_UPDATE_RULES"]["IMAGES"]) {
                    $arActivationRules["IMAGES"] = true;
                }
            }

            if (empty($this->arCASiteProperties["CA_BRAND"]["ENUMS"][$this->arModelsByXML_ID[$xmlId]["vendor"]["id"]])) {
                $enumData = ['id' => $this->arModelsByXML_ID[$xmlId]["vendor"]["id"], "value" => $this->arModelsByXML_ID[$xmlId]["vendor"]["name"]];

                $this->CreatEnum($this->arCASiteProperties["CA_BRAND"], $enumData, true);
            }

            $PROP["CA_BRAND"] = $this->arCASiteProperties["CA_BRAND"]["ENUMS"][$this->arModelsByXML_ID[$xmlId]["vendor"]["id"]]["ID"];
            $brandName = $this->arCASiteProperties["CA_BRAND"]["ENUMS"][$this->arModelsByXML_ID[$xmlId]["vendor"]["id"]]["VALUE"];

            $goodsPropertiesCount = 0;
//            $weightToCatalog = 0;
//            $unitId = 1;
            if(!empty($arModelProperties["propertyValues"]))  {
                $this->Logger->log("LOG", "У товара [".$arItem["ID"]."] ".$arItem["NAME"]." заполнено ".count($arModelProperties["propertyValues"]). " свойств");
                $linkedProps = [];

                /* region Wcrow settings */
//                $wcrMod = false;
//                if(\bitrix\Main\Loader::includeModule('wcrow.settings')) {
//                    $wcrMod = true;
//                }
                /* endregion */
                foreach ($arModelProperties["propertyValues"] as $arProp) {
                    $goodsPropertiesCount++;
                    $property = $this->arCASectionProperties[$modelSectionId]["PROPERTIES"][$arProp["definitionId"]];
                    $property["code"] = $this->getProperyCode($property);

                    $arPropData = [
                        "NAME" => $property["name"],
                        "ACTIVE" => "Y",
                        "SORT" => $property["order"],
                        "CODE" => $property["code"],
                        "PROPERTY_TYPE" => "S",
                        "IBLOCK_ID" => $this->catalogIblockId,
                        "SECTION_PROPERTY" => "N",
                        "FEATURES" => [
                            [
                                'IS_ENABLED' => "N",
                                'MODULE_ID' => "iblock",
                                'FEATURE_ID' => "SECTION_PROPERTY"
                            ]
                        ]
                    ];

                    if ($arProp['definitionId'] == 10859) {
                        $PROP['CML2_ARTICLE'] = $arProp['stringValue'];
                    }

                    /* region Wcrow settings */
//                    if($wcrMod) {
//                        $propMain = \Wcrow\Settings\Action::findMainPropByName($property["name"]);
//                        $nValue = false;
//                        if($propMain) {
//                            foreach ($propMain AS $saveType => $propId) {
//                                $mainProp = \Wcrow\Settings\Action::getPropertyById($propId);
//                                $nValue = \Wcrow\Settings\Action::compareMainNew($mainProp, $saveType, $property, $arProp);
//                            }
//                            if($nValue) {
//                                $PROP[$mainProp['ID']] = ['VALUE'=>$nValue, 'DESCRIPTION'=>''];
//                                continue;
//                            }
//                        }
//                    }
                    /* endregion */
                    switch($property["type"]) {
                        case "Decimal":
                            $arPropData["PROPERTY_TYPE"] = "N";
                            $arPropData["WITH_DESCRIPTION"] = "Y";

                            if (!empty($this->arCASiteProperties[$property["code"]]["ID"]) && $this->arCASiteProperties[$property["code"]]["PROPERTY_TYPE"] != "N") {
                                $this->DeleteProperty($this->arCASiteProperties[$property["code"]]["ID"]);
                            }

                            if (empty($this->arCASiteProperties[$property["code"]])) {
                                $this->CreateProperty($arPropData, $modelSectionId);
                            }

                            $PROP[$this->arCASiteProperties[$property["code"]]["ID"]] = ["VALUE" => $arProp["decimalValue"], "DESCRIPTION" => $this->arCAUnits[$property["unit"]]["SHORT_NAME"]];

                            break;
                        case "Integer":
                            $arPropData["PROPERTY_TYPE"] = "N";
                            $arPropData["WITH_DESCRIPTION"] = "Y";

                            if (!empty($this->arCAUnits[$property["unit"]]["SHORT_NAME"])) {
                                $arPropData['NAME'] .= ", ".$this->arCAUnits[$property["unit"]]["SHORT_NAME"];
                            }

                            if (!empty($this->arCASiteProperties[$property["code"]]["ID"]) && $this->arCASiteProperties[$property["code"]]["PROPERTY_TYPE"] != "N") {
                                $this->DeleteProperty($this->arCASiteProperties[$property["code"]]["ID"]);
                            }

                            if (empty($this->arCASiteProperties[$property["code"]])) {
                                $this->CreateProperty($arPropData, $modelSectionId);
                            }

                            $PROP[$this->arCASiteProperties[$property["code"]]["ID"]] = ["VALUE" => $arProp["integerValue"], "DESCRIPTION" => $this->arCAUnits[$property["unit"]]["SHORT_NAME"]];

                            break;
                        case "String":
                        case "Range":
                        case "Expression":
                            if (!empty($this->arCASiteProperties[$property["code"]]["ID"]) && $this->arCASiteProperties[$property["code"]]["PROPERTY_TYPE"] != "S") {
                                $this->DeleteProperty($this->arCASiteProperties[$property["code"]]["ID"]);
                            }

                            if (empty($this->arCASiteProperties[$property["code"]])) {
                                $this->CreateProperty($arPropData, $modelSectionId);
                            }

                            $value = $arProp["stringValue"];

                            if ($property["type"] == "Range") {
                                $value = $arProp['minRangeValue'].'...'.$arProp['maxRangeValue'];
                            }

                            $PROP[$this->arCASiteProperties[$property["code"]]["ID"]] = $value;
                            break;
                        case "Enum":
                            $arPropData["PROPERTY_TYPE"] = "L";

                            if (!empty($this->arCASiteProperties[$property["code"]]["ID"]) && $this->arCASiteProperties[$property["code"]]["PROPERTY_TYPE"] != "L") {
                                $this->DeleteProperty($this->arCASiteProperties[$property["code"]]["ID"]);
                            }

                            if (empty($this->arCASiteProperties[$property["code"]])) {
                                $this->CreateProperty($arPropData, $modelSectionId);
                            }

                            $unitDescription = $this->arCAUnits[$property["unit"]]["SHORT_NAME"];

                            if (!empty($this->arCAUnits[$property["unit"]]["DECLENSIONS"])) {
                                $pVal = $this->arCASiteProperties[$property["code"]]["ENUMS"][$arProp["enumValue"]["id"]]["VALUE"];
                                $expldVal = explode("—", $pVal);

                                if (!empty($expldVal[1])) {
                                    $declExpld = explode(",", $this->arCAUnits[$property["unit"]]["DECLENSIONS"]);
                                    $expldVal[1] = trim($expldVal[1]);

                                    if ((int)$expldVal[1] > 0 ) {
                                        $unitDescriptionTmp = declension($expldVal[1], $declExpld);
                                        $expldDesc = explode(" ", $unitDescriptionTmp);
                                        $arProp["enumValue"]["value"] .= " ".$expldDesc[1];
                                    } else {
                                        $arProp["enumValue"]["value"] .= " ".$unitDescription;
                                    }
                                } else {
                                    $declExpld = explode(",", $this->arCAUnits[$property["unit"]]["DECLENSIONS"]);
                                    $expldVal[0] = trim($expldVal[0]);

                                    if ((int)$expldVal[0] > 0 ) {
                                        $unitDescriptionTmp = declension($expldVal[0], $declExpld);
                                        $expldDesc = explode(" ", $unitDescriptionTmp);
                                        $arProp["enumValue"]["value"] .= " ".$expldDesc[1];
                                    } else {
                                        $arProp["enumValue"]["value"] .= " ".$unitDescription;
                                    }
                                }
                            }

                            if (empty($this->arCASiteProperties[$property["code"]]["ENUMS"][$arProp["enumValue"]["id"]])) {
                                $this->CreatEnum($this->arCASiteProperties[$property["code"]], $arProp["enumValue"],true);
                            } else {
                                $this->UpdateEnumValue($this->arCASiteProperties[$property["code"]]["ENUMS"][$arProp["enumValue"]["id"]], $arProp["enumValue"]);
                            }

                            $PROP[$this->arCASiteProperties[$property["code"]]["ID"]] = ["VALUE" => $this->arCASiteProperties[$property["code"]]["ENUMS"][$arProp["enumValue"]["id"]]["ID"], "DESCRIPTION" => ""];
                            $unitDescription = "";

                            break;
                        case "Flag":
                            $arPropData["PROPERTY_TYPE"] = "L";
                            $arPropData["MULTIPLE"] = "Y";

                            if (!empty($this->arCASiteProperties[$property["code"]]["ID"]) && $this->arCASiteProperties[$property["code"]]["PROPERTY_TYPE"] != "L") {
                                $this->DeleteProperty($this->arCASiteProperties[$property["code"]]["ID"]);
                            }

                            if (empty($this->arCASiteProperties[$property["code"]])) {
                                $this->CreateProperty($arPropData, $modelSectionId);
                            }

                            foreach ($arProp["flagValues"] as $arEnums) {
                                if (empty($this->arCASiteProperties[$property["code"]]["ENUMS"][$arEnums["propertyEnum"]["id"]])) {
                                    $this->CreatEnum($this->arCASiteProperties[$property["code"]], $arEnums["propertyEnum"], true);
                                }
                            }

                            foreach ($arProp["flagValues"] as $arEnums) {
                                $PROP[$this->arCASiteProperties[$property["code"]]["ID"]][] = $this->arCASiteProperties[$property["code"]]["ENUMS"][$arEnums["propertyEnum"]["id"]]["ID"];
                            }
                            break;
                        case "Boolean":
                            $arPropData["PROPERTY_TYPE"] = "L";
                            $arPropData["LIST_TYPE"] = "C";

                            if (!empty($this->arCASiteProperties[$property["code"]]["ID"]) && $this->arCASiteProperties[$property["code"]]["PROPERTY_TYPE"] != "L") {
                                $this->DeleteProperty($this->arCASiteProperties[$property["code"]]["ID"]);
                            }

                            if (empty($this->arCASiteProperties[$property["code"]])) {
                                $this->CreateProperty($arPropData, $modelSectionId);
                                $this->CreatEnum($this->arCASiteProperties[$property["code"]], ["id" => 0, "value" => "Нет", "DEF" => "Y"]);
                                $this->CreatEnum($this->arCASiteProperties[$property["code"]], ["id" => 1, "value" => "Да"], true);
                            }

                            $PROP[$this->arCASiteProperties[$property["code"]]["ID"]] = $arProp["booleanValue"] ?
                                $this->arCASiteProperties[$property["code"]]["ENUMS"][1]["ID"] :
                                $this->arCASiteProperties[$property["code"]]["ENUMS"][0]["ID"];

                            break;
                        case "File":
                            if (!empty($this->arCASiteProperties[$property["code"]]["ID"]) && $this->arCASiteProperties[$property["code"]]["PROPERTY_TYPE"] != "S") {
                                $this->DeleteProperty($this->arCASiteProperties[$property["code"]]["ID"]);
                            }

                            if (empty($this->arCASiteProperties[$property["code"]])) {
                                $this->CreateProperty($arPropData, $modelSectionId);
                            }

                            $PROP[$this->arCASiteProperties[$property["code"]]["ID"]] = $arProp["file"];
                            break;
                        case "ModelsList":
                            $arPropData["PROPERTY_TYPE"] = "E";
                            $arPropData["LIST_TYPE"] = "L";
                            $arPropData["MULTIPLE"] = "Y";
                            $arPropData["LINK_IBLOCK_ID"] = $this->catalogIblockId;
                            $modelCatalogAppId = [];

                            if (!empty($this->arCASiteProperties[$property["code"]]["ID"]) && $this->arCASiteProperties[$property["code"]]["PROPERTY_TYPE"] != "E") {
                                $this->DeleteProperty($this->arCASiteProperties[$property["code"]]["ID"]);
                            }

                            if (empty($this->arCASiteProperties[$property["code"]])) {
                                $this->CreateProperty($arPropData, $modelSectionId);
                            }

                            foreach ($arProp["modelValues"] as $modelValue) {
                                $modelCatalogAppId[] = $modelValue["model"]['id'];
                            }

                            $siteProductIds = [];

                            if (!empty($modelCatalogAppId)) {
                                $sql = "SELECT IBLOCK_ELEMENT_ID 
                                FROM b_iblock_element_property 
                                WHERE 
                                    IBLOCK_PROPERTY_ID = '{$this->arCAProperties["CA_ID"]["ID"]}' AND VALUE IN (".implode(',', $modelCatalogAppId).")";

                                $res = $this->connection->query($sql);
                                while($sqlRes = $res->fetch()) {
                                    $siteProductIds[] = $sqlRes["IBLOCK_ELEMENT_ID"];
                                }

                                if (!empty($siteProductIds)) {
                                    $PROP[$this->arCASiteProperties[$property["code"]]["ID"]] = ["VALUE" => $siteProductIds];
                                }
                            }

                            break;
                        default:
                            $this->Logger->log("LOG", "Не определили тип свойства\r\n".print_r($property, true));
                            break;
                    }
                }
            } else {
                $this->Logger->log("LOG", "У товара [".$arItem["ID"]."] ".$arItem["NAME"]." нет свойств");
            }

            $totalSectionProperties = !empty($this->arCASectionProperties[$modelSectionId]["PROPERTIES"]) ? count($this->arCASectionProperties[$modelSectionId]["PROPERTIES"]) : 1;
            $currentGoodsPropertiesPercent = round($goodsPropertiesCount * 100 / $totalSectionProperties);

            if ($currentGoodsPropertiesPercent >= $this->arCASettings["AUTO_UPDATE_RULES"]["PROPERTIES"]) {
                $arActivationRules["PROPERTIES"] = true;
            }

            $PROP["CA_AUTO_ACTIVATE"] = 1;
            $PROP["IS_UPDATED"] = 1; // TODO после обновления каталога удалить!!!

            if (!empty($arItem["PROPERTY_HIT_ENUM_ID"]))
                $PROP["HIT"] = $arItem["PROPERTY_HIT_ENUM_ID"];

            if ($arActivationRules["IMAGES"] && $arActivationRules["PROPERTIES"]) {
                // $PROP["CA_AUTO_ACTIVATE"] = 1;
            }

            if (isset($this->arModelsByXML_ID[$xmlId]['article']) && !empty($this->arModelsByXML_ID[$xmlId]['article'])) {
                $PROP["ARTICLE"] = $this->arModelsByXML_ID[$xmlId]['article'];
//                $PROP["CML2_ARTICLE"] = $this->arModelsByXML_ID[$xmlId]['article'];
            }

            /* region Wcrow settings */
            $arLoadProductArray["PROPERTY_VALUES"] = $PROP;
            /* endregion */
            // удаление фото
            \CIBlockElement::SetPropertyValuesEx(
                $arItem["ID"],
                $arItem["IBLOCK_ID"],
                array(
                    "MORE_PHOTO" => array( // название свойства
                        "VALUE" => array( // значение свойства
                            "del" => "Y" // параметр на удаление
                        )
                    )
                )
            );

            $code = Cutil::translit($arLoadProductArray['NAME'],"ru", ["replace_space" => "-", "replace_other" => "-"]);
            $code = str_replace("quot-", "", $code);
            $code = strtolower($code);
//            $arLoadProductArray['CODE'] = $code;

//            $arLoadProductArray['NAME'] .= " ({$PROP["CA_ID"]})";
            $arLoadProductArray['NAME'] = $this->arModelsByXML_ID[$xmlId]['name'];

            try {
                $this->Logger->log("LOG", "Обновление свойств");
                if (!$el->Update($arItem["ID"], $arLoadProductArray)) {
                    $this->Logger->log("ERROR", "Ошибка при обновлении свойств товара \r\n".print_r($el->LAST_ERROR, true));
                    $this->Logger->log("ERROR", "\r\n".print_r($arLoadProductArray, true));
                    $this->Logger->log("ERROR", "\r\n".print_r($arItem, true));
                } else {
                    foreach ($PROP as $id => $arPROP) {
                        /* region Wcrow settings */
                        /*$value = isset($arPROP['VALUE']) ? $arPROP['VALUE'] : $arPROP;

                        if (is_array($value)) {
                            CIBlockElement::SetPropertyValuesEx($arItem["ID"], $this->arCASettings['CATALOG_APP_CATALOG_ID'], [''.$id.'' => $value]);
                        } else {
                            CIBlockElement::SetPropertyValuesEx($arItem["ID"], $this->arCASettings['CATALOG_APP_CATALOG_ID'], [''.$id.'' => ['VALUE' => $value]]);
                        }*/
                        /* endregion */

                        if (!empty($arPROP["DESCRIPTION"])) {
                            $arPROP["DESCRIPTION"] = addslashes($arPROP["DESCRIPTION"]);
                            $sql = "UPDATE b_iblock_element_property SET DESCRIPTION = '{$arPROP["DESCRIPTION"]}' WHERE IBLOCK_ELEMENT_ID = {$arItem["ID"]} AND IBLOCK_PROPERTY_ID = {$id}";
                            $this->connection->query($sql);
                        }
                    }

//                    if (!empty($linkedProps)) {
//                        foreach ($linkedProps as $data) {
//                            CIBlockElement::SetPropertyValuesEx($data["itemId"], $data['iblockId'], $data['value']);
//                        }
//                    }
                    $counter++;
                    unset($arLoadProductArray);
                    $this->Logger->log("LOG", "Свойства обновлены");
                }
            } catch(Exception $e) {
                $this->Logger->log("ERROR", "Ошибка при обновлении свойств товара \r\n".print_r($e->getMessage(), true));
            }


            $this->Logger->log("LOG", "Удаление временных файлов картинок");
            foreach ($arModelProperties["images"] as $k => $image) {
                @unlink($_SERVER["DOCUMENT_ROOT"].$image);
            }

            foreach ($arMainFiles as $k => $image) {
                @unlink($_SERVER["DOCUMENT_ROOT"].$image);
            }
            $this->Logger->log("LOG", "Файлы удалены");
        }

//        foreach ($propsByCode as $code => $item) {
//            if (count($item) > 1) {
//                $duplicatedProps[$code] = $item;
//            }
//        }
        /* region Wcrow settings */
//        if(\Bitrix\Main\Loader::includeModule('wcrow.settings')){
//            \Wcrow\Settings\Action::clearDupAllProps();
//        }
        /* endregion */
        $this->Logger->log("LOG", "Всего обновлено товаров: ".$counter);
        $this->EndDebugTime(__FUNCTION__);
    }

    private function getProperyCode($property) {
        $name = $property["name"];

        if (preg_match("/^[0-9]$/i", $name[0])) {
            $newName = 'p_'.$name;
            $name = $newName;
        }

        $string = preg_replace( '/[^a-zA-ZА-Яа-яА-Я0-9\s]/u', '', $name );
        $string =  preg_replace('/\s/', ' ', $string);

        $code = Cutil::translit($string,"ru", ["replace_space" => "_", "replace_other" => "_"]);

//        $code = $this->propPrefix.translitProperty($property["name"]);
//        $code = trim(strtoupper($code));
        $code .= "_".$property["id"];

        return strtoupper($code);
    }

    private function getLinkedPropValue($property, $arProp, $needPropType = 'S')
    {
        $value = null;

        switch($property['type']) {
            case "Decimal":
                if ($needPropType == "L") {
                    $value = $this->CreateLinkedEnum($property, $arProp, $arProp["decimalValue"]);
                } else {
                    $value = $arProp["decimalValue"];
                }
                break;
            case "Integer":
                if ($needPropType == "L") {
                    $value = $this->CreateLinkedEnum($property, $arProp, $arProp["integerValue"]);
                } else {
                    $value = $arProp["integerValue"];
                }
                break;
            case "String":
                if ($needPropType == "L") {
                    $value = $this->CreateLinkedEnum($property, $arProp, $arProp["stringValue"]);
                } else {
                    $value = $arProp["stringValue"];
                }
                break;
            case "Enum":
                if ($needPropType == "L") {
                    $value = $this->CreateLinkedEnum($property, $arProp, $arProp["enumValue"]["value"]);
                } else {
                    $value = $arProp["enumValue"]["value"];
                }

                break;
            case "Flag":
                foreach ($arProp["flagValues"] as $arEnums) {
                    if (isset($this->arCASiteProperties[$property["code"]]["ENUMS"]) && empty($this->arCASiteProperties[$property["code"]]["ENUMS"][$arEnums["propertyEnum"]["id"]])) {
                        $this->CreatEnum($this->arCASiteProperties[$property["code"]], $arEnums["propertyEnum"], true);
                    }
                }

                foreach ($arProp["flagValues"] as $arEnums) {
                    $value[] = $arEnums['propertyEnum']['externalId'];
                }
                break;
            case "Boolean":
                if ($needPropType == "L") {
                    $value = $this->CreateLinkedEnum($property, $arProp, $arProp["booleanValue"]);
                } else {
                    $value = $arProp["booleanValue"];
                }
                break;
            case "File":
                $value = $arProp["file"];
                break;
            default:
                break;
        }

        return $value;
    }

    /**
     * @param $filePath
     * @param $localPath
     * @param $ext
     *
     * @return bool
     */
    private function ConvertToWebp($filePath = "", $localPath = "", $ext = "") {
        $this->Logger->log("LOG", "Конвертируем изображение {$localPath} в webp");
        $availableExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'JPG', 'GIF', 'JPEG', 'PNG', "WEBP"];

        if (empty($filePath) || empty($localPath) || empty($ext)) {
            $this->Logger->log("LOG", "Невозможно конвертировать");
            return false;
        }

        if (!in_array($ext, $availableExt)) {
            $this->Logger->log("LOG", "Невозможно конвертировать, неверный формат");
            return false;
        }

        try {
            switch ($ext) {
                case "jpg":
                case "jpeg":
                case "JPG":
                case "JPEG":
                    $im = imagecreatefromjpeg($filePath);
                    $serverFilePath = str_replace($ext, "webp", $localPath);
                    imagewebp($im, $_SERVER["DOCUMENT_ROOT"].$serverFilePath, 100);
                    $this->Logger->log("LOG", "Файл конвертирован");
                    break;
                case "png":
                case "PNG":
                    $im = imagecreatefrompng($filePath);
                    $serverFilePath = str_replace($ext, "webp", $localPath);
                    imagewebp($im, $_SERVER["DOCUMENT_ROOT"].$serverFilePath, 100);
                    $this->Logger->log("LOG", "Файл конвертирован");
                    break;
            }
        } catch (Exception $err) {
            $this->Logger->log("LOG", "Ошибка при конвертировании файла ".print_r($err->getMessage(), true));
            return false;
        }

        return true;
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

(new UpdatePropertiesWorker_new())->StartWorker();