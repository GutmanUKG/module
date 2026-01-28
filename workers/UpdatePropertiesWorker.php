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


class UpdatePropertiesWorker {
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
        $this->Logger = new ImarketLogger("/upload/log/UpdatePropertiesWorker/");
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
            return false;
        }

        // проставить статус
        $this->UpdateStatus(1);

        // получить каталог из catalogApp
        $this->GetCatalogModels();
        // получить товары сайта из текущего ЦО
        $this->GetCatalogGoods();

        if (!empty($this->arModelsByXML_ID)) {
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
        }

        // проверяет группы свойств
//        $this->CheckPropertiesGroups(); // обязательно должно быть после обновления свойств

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

            if (!empty($catalogAppSection["PROPERTIES"])) {
                $this->Logger->log("LOG", "Привязанных свойств к разделу в catalog.app ".count($catalogAppSection["PROPERTIES"]));

                foreach ($catalogAppSection["PROPERTIES"] as $CAProperty) {
                    $this->Logger->log("LOG", "Проверяем свойство '{$CAProperty["name"]}'");
                    $propCode = $this->getProperyCode($CAProperty);

                    if (empty($propCode)) {
                        $this->Logger->log("LOG", "Нет кода свойства!");
                        continue;
                    }

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
                                ('".$this->catalogIblockId."', '".$siteSectionId."', '".$siteSectionPropertyData[$propCode]["ID"]."', 'N')";
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

//        $this->arModels = $this->restAPI->GetModels();
//        $this->arModels = array_reverse($this->arModels, true);

        $date = date("Y-m-d", strtotime("-5 day"))."T00:00:00";
        $this->arModels = $this->restAPI->GetModelCartModified($date);

        $cartModified = $this->restAPI->GetModelModified($date);

        if (!empty($cartModified)) {
            if (!empty($this->arModels)) {
                $this->arModels = array_merge($this->arModels, $cartModified);
            } else {
                $this->arModels = $cartModified;
            }
        }

        foreach ($this->arModels as $k => $arModel) {
            $this->arModelsByXML_ID[$arModel["externalId"]] = $arModel;
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

		$filter = ['IBLOCK_ID' => $this->catalogIblockId/*, "!PROPERTY_IS_UPDATED" => 1*/]; // TODO Удалить после полного обновления
        $select = ['ID', 'NAME', 'XML_ID', 'PROPERTY_CA_ID'];
        $dbl = CIBlockElement::GetList(["ID" => "ASC"], $filter, false, [], $select);
        while ($arItem = $dbl->Fetch()) {
            $this->arCatalogDataByXML_ID[$arItem["XML_ID"]] = $arItem;
        }

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

    private function DeleteProperty ($propertyId = 0): void
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
            $this->Logger->log("ERROR", "Ошибка при добавлении значения свойства \r\n".prit_r($ibpenum->LAST_ERROR, true));
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
            $this->Logger->log("ERROR", "Ошибка при Обновлении значения свойства \r\n".prit_r($ibpenum->LAST_ERROR, true));
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

            if (!empty($arModelProperties['description']['value'])) {
                $arLoadProductArray["DETAIL_TEXT_TYPE"] = 'text';

                if (strip_tags($arModelProperties['description']['value']) !== $arModelProperties['description']['value']) {
                    $arLoadProductArray["DETAIL_TEXT_TYPE"] = 'html';
                }

                $arLoadProductArray["DETAIL_TEXT"] = $arModelProperties['description']['value'];
            }

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

                foreach ($arModelProperties["propertyValues"] as $arProp) {
                    $goodsPropertiesCount++;
                    $property = $this->arCASectionProperties[$modelSectionId]["PROPERTIES"][$arProp["definitionId"]];
                    $property["code"] = $this->getProperyCode($property);

//                    $propData = [];
//                    if ($this->useDescriptionForPropData && !empty($property['description'])) {
//                        $propData = json_decode($property['description'], true);
//                        $property["code"] = $propData['CODE'];
//                    } else {
//                        $property["code"] = $this->getProperyCode($property);
//                    }

//                    if (empty($property["code"])) {
//                        $property["code"] = $this->getProperyCode($property);
//
//                        if (empty($property["code"])) {
//                            $propWithoutCode[] = $property;
//                            continue;
//                        }
//                    }
//                    else {
//                        $propsByCode[$property["code"]][] = $property;
//                    }

//                    if (!empty($this->arCASiteProperties[$property["code"]]["ID"])) {
//                        $this->CheckPropertySection($modelSectionId, $this->arCASiteProperties[$property["code"]]["ID"], $property["name"]);
//                    }

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

                            if (!empty($this->arCASiteProperties[$property["code"]]["ID"]) && $this->arCASiteProperties[$property["code"]]["PROPERTY_TYPE"] != "S") {
                                $this->DeleteProperty($this->arCASiteProperties[$property["code"]]["ID"]);
                            }

                            if (empty($this->arCASiteProperties[$property["code"]])) {
                                $this->CreateProperty($arPropData, $modelSectionId);
                            }

                            $PROP[$this->arCASiteProperties[$property["code"]]["ID"]] = $arProp["stringValue"];
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

            if ($arActivationRules["IMAGES"] && $arActivationRules["PROPERTIES"]) {
                // $PROP["CA_AUTO_ACTIVATE"] = 1;
            }

            if (isset($this->arModelsByXML_ID[$xmlId]['article']) && !empty($this->arModelsByXML_ID[$xmlId]['article'])) {
                $PROP["ARTICLE"] = $this->arModelsByXML_ID[$xmlId]['article'];
            }

            $arLoadProductArray["PROPERTY_VALUES"] = $PROP;

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

//            $el->Update($arItem["ID"], array(
//
//                // удаление фотографии на странице анонса
//                "PREVIEW_PICTURE" => array('del' => 'Y'),
//
//                // удаление фотографии на детальной странице
//                "DETAIL_PICTURE" => array('del' => 'Y'),
//
//            ), false, false);
//
            // обновление веса товара
//            $catalogFields = [];
//            if (!empty($weightToCatalog)) {
//                $catalogFields['WEIGHT'] = $weightToCatalog;
//                $this->Logger->log("LOG", "Обновляем вес {$catalogFields["WEIGHT"]} товару [{$arItem["ID"]}] {$arItem["NAME"]}");
//            }
//
            // обновление единицы измерения товара
//            if (!empty($unitId)) {
//                $catalogFields['MEASURE'] = $unitId;
//                $this->Logger->log("LOG", "Обновляем единицу измерения {$catalogFields["MEASURE"]} товару [{$arItem["ID"]}] {$arItem["NAME"]}");
//            }
//
//            if (!empty($catalogFields)) {
//                CCatalogProduct::Update($arItem["ID"], $catalogFields);
//            }


            if (!empty($this->arCASectionProperties[$modelSectionId]['SINGULAR_NAME'])) {
                $arLoadProductArray['NAME'] = $this->arCASectionProperties[$modelSectionId]['SINGULAR_NAME'].' '.$brandName.' '.mb_strtolower($this->arModelsByXML_ID[$xmlId]['name']);
            } else {
                $arLoadProductArray['NAME'] = $brandName.' '.$this->arModelsByXML_ID[$xmlId]['name'];
            }

            $code = Cutil::translit($arLoadProductArray['NAME'],"ru", ["replace_space" => "-", "replace_other" => "-"]);
            $code = str_replace("quot-", "", $code);
            $code = strtolower($code);
            $arLoadProductArray['CODE'] = $code;

            $arLoadProductArray['NAME'] .= " ({$PROP["CA_ID"]})";

            try {
                $this->Logger->log("LOG", "Обновление свойств");
                if (!$el->Update($arItem["ID"], $arLoadProductArray)) {
                    $this->Logger->log("ERROR", "Ошибка при обновлении свойств товара \r\n".print_r($el->LAST_ERROR, true));
                } else {
                    foreach ($PROP as $id => $arPROP) {
//                        $value = $arPROP['VALUE'] ?? $arPROP;

//                        if (is_array($value)) {
//                            CIBlockElement::SetPropertyValuesEx($arItem["ID"], $this->arCASettings['CATALOG_APP_CATALOG_ID'], [''.$id.'' => $value]);
//                        } else {
//                            CIBlockElement::SetPropertyValuesEx($arItem["ID"], $this->arCASettings['CATALOG_APP_CATALOG_ID'], [''.$id.'' => ['VALUE' => $value]]);
//                        }

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

        $this->Logger->log("LOG", "Всего обновлено товаров: ".$counter);
        $this->EndDebugTime(__FUNCTION__);
    }

    private function getProperyCode($property) {
        $code = $this->propPrefix.translitProperty($property["name"]);
        $code = strtoupper($code);
        $code .= "_".$property["id"];

        return $code;
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
    private function ConvertToWebp($filePath = "", $localPath = "", $ext = ""): bool {
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

(new UpdatePropertiesWorker())->StartWorker();