<?
/**
 * Обработчик, котороый обновляет информацию по товарам:
 * цену, наличие, доступность, рассрочки, доставку и т.д.
 *
 * Запуск на кроне раз в 2 минуты
 */

if (empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = realpath(dirname(__FILE__) . '/../../../..');
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use ImarketHeplers\ImarketLogger,
    Bitrix\Main\Application,
    \Bitrix\Iblock\PropertyIndex,
    Bitrix\Main\Config\Option;

if (!class_exists("ImarketHeplers\ImarketLogger")) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/ImarketLogger.php');
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/RestAPI.php');
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/CCatalogDiscount.php');
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/ImarketSectionsConnections.php');
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/workers/WorkersChecker.php');
ini_set('memory_limit', '40960M'); // много кушает оперативки!!!!
set_time_limit(0);

class UpdatePriceWorker {
    private $Logger; // класс для логирования
    private $connection; // подключение к БД
    private $restAPI; // класс api rest-а
    public $debugData = []; // данные для дебага
    private $workersChecker; // класс для работы с обработчиками
    private $workerData = []; // данные об обработчиках
    private $workerId = 'update_price'; // id обработчика в табице worker_busy
    private $profilesRules = []; // правила обраотки профилей, хранятся в таблице catalog_app_profiles_rules
    private $arOurPricingProfiles = []; // профили, которые нужно обрабатывать, ключ - id в catalogApp, значение - id на сате
    private $arPricingRules = []; // профили, которые нужно обрабатывать, ключ - id в catalogApp, значение - id на сате
    private $arCompletedTasks = []; // данные о законченных задачав в catalogApp
    private $arCatalogDataByXML_ID = []; // весь каталог сайта, где ключ xml_id элемента
    private $arCatalogDataById = []; // весь каталог сайта, где ключ id элемента
    private $uploadTable = 'catalog_app_data'; // основная таблица с выгрузкой из catalogApp
    private $currentProfileId = 0; // какой профиль обрабатывается в текущий момент
    private $arCatalogApp = []; // каталог для обработки из таблицы с выгрузкой [$uploadTable]
    private $sectionConnections = []; // сопоставление разделов catalogApp и сайта, ключ - id catalogApp
    private $actionProducts = []; // текущие акционнае товары
    private $discountProducts = []; // текущие товары со скидки
    private $toActions = []; // товары, которые нужно добавить в акцию
    private $toDiscounts = []; // товары, которые нужно добавить в скидки
    private $removeFromActions = []; // товары, которые нужно удалить из акций
    private $removeFromDiscounts = []; // товары, которые нужно удалить из скидок
    private $sortIndex = []; // сортировака товара
    private $arFinalGoodsIds = []; // массив id товаров, которые можно обновлять
    private $arExportAttributes = []; // массив с данными о производителях импортерах сервисных центрах
    private $arSuppliers = []; // массив с данными о поставщиках
    private $arCatalogAppModels = []; // массив с моделями из catalogApp, ключ catalogApp id
    private $needUpdateSiteCatalog = false;
    private $arGoodsPrices = []; // существующие цены товаров
    private $arCatalogAppVendors = []; // Бренды из catalogApp, ключ id бренда
    private $catalogIblockId = 0;
    private $arCASettings = [];

    public function __construct () {
        $this->Logger = new ImarketLogger("/upload/log/UpdatePriceWorker/");
        $this->connection = Application::getConnection();
        $this->restAPI = new RestAPI();
        $this->workersChecker = new WorkersChecker();

        $this->arCASettings["CATALOG_APP_CATALOG_ID"] = Option::get("imarket.catalog_app", "CATALOG_APP_CATALOG_ID");
        $this->arCASettings["CATALOG_IBLOCK_ID"] = Option::get("imarket.catalog_app", "CATALOG_IBLOCK_ID");
        $this->arCASettings["AUTO_UPDATE_GOODS"] = Option::get("imarket.catalog_app", "AUTO_UPDATE_GOODS");
        $this->arCASettings["AUTO_UPDATE_RULES"] = Option::get("imarket.catalog_app", "AUTO_UPDATE_RULES");
        $this->arCASettings["AUTO_UPDATE_RULES"] = unserialize($this->arCASettings["AUTO_UPDATE_RULES"]);

        if (!empty($this->arCASettings["CATALOG_IBLOCK_ID"])) {
            $this->catalogIblockId = $this->arCASettings["CATALOG_IBLOCK_ID"];
        }

        if (!CModule::IncludeModule('iblock')) {
            $this->Logger->log("ERROR", "Не удалось подключить модуль iblock");
            die();
        }
    }

    /**
     * Запуск обработчика, получение выгрузки, формирование данных
     *
     * @throws \Bitrix\Main\Db\SqlQueryException
     * @throws \Bitrix\Main\SystemException
     */
    public function StartWorker() {
        $this->Logger->log("LOG", "Начало обработки");
        $this->StartDebugTime(__FUNCTION__);
        $arProfileData = [];

        if (!$this->CheckStatus()) {
            $this->Logger->log("LOG", "Не нужно обрабатывать");
            return false;
        }

        $this->UpdateStatus(1);

        // получить правила профилей для работы
        $this->GetProfilesRules();
        // получить завершенные ценообразования
        $this->GetCompletedTask();

        #Получение товаров из catalog app и сохранение их#
        if (!empty($this->arCompletedTasks)) {
            // проходим по всем завершенным таскам и получаем данные по товарам
            foreach ($this->arCompletedTasks as $taskId => $arTask) {
                if (!isset($arTask["id"])) {
                    continue;
                }

                $this->Logger->log("LOG", "Обрабатываем задачу \r\n".print_r($arTask, true));

                if (empty($this->arOurPricingProfiles[$taskId])) {
                    $this->Logger->log("LOG", "Данную задачу не нужно обрабатывать");
                    $this->UpdateCompletedTaskStatus($arTask["id"], 0);
                    continue;
                }

                $this->needUpdateSiteCatalog = true;

                // получить товары со скидками, обязательно перед получением всех товаров [GetCatalogGoods]
                $this->GetDiscountProducts();
                // получить все товары каталога, ключ xml_id
                $this->GetCatalogGoods();

                $this->GetExportAttributes($taskId);
                // но на всякий случай можно запихнуть в цикл и подставлять нужный профиль
                $this->GetSuppliers();
                // получить модели
                $this->GetModes();
                // получить бренды
                $this->GetVendors();

                $this->currentProfileId = $this->arOurPricingProfiles[$taskId];
                $this->Logger->log("LOG", "Получаем данные для профиля ".$this->arOurPricingProfiles[$taskId]);
                $arProfileData = $this->restAPI->GetProfilePrices([$taskId => $this->arOurPricingProfiles[$taskId]]);

//                $this->updateOldPrices();

                $this->SaveProfileData($arProfileData);

                $this->UpdateCompletedTaskStatus($arTask["id"], 0);

                // обновить рассрочку банков, TODO подумать над этим, не стандартный функционал
//                $this->UpdateBankInstallments($taskId);
            }
        } else {
            $this->Logger->log("LOG", "Нечего обновлять");
        }
        #Конец Получение товаров из catalog app и сохранение их#

        if ($this->needUpdateSiteCatalog) {
            #Формирование данных и обновление каталога сайта#
            // получить все записи из таблицы для выгрузки
            $this->arCatalogApp = $this->restAPI->GetCatalogAppCatalog();

            // отсеиваем товары, которые не нужно обновлять
            $this->CheckProfilesPermission("UPDATE");
            // получить сопоставленные разделы
            $this->GetSectionsConnections();
            // подготовить данные, добавляем недостающие значение и формируем данные для прямых обновлений таблиц
            // [$this->actionProducts, $this->discountProducts]
            $this->PrepareGoodsData();

            // проверить есть ли цена у товара
            $this->CheckProductsPrice();
            // обновление данных товара
            $this->UpdateGoodsData();
            // обновление цен доставк по товарам TODO думать над функционалом
//            $this->UpdateDeliveryData();
            // обновление активности товаров
            $this->SetActive();
            // установка сортировки активным товарам
            $this->SetSort();

            // обновление количества по складам
            $this->updateByStock();

            // добавление скидок товарам
//            $this->CreateDiscounts();

            // удаление цен и обнуление остатков
            $this->ClearProductsData();

            // Сбросить кэш
            $this->ClearCache();
            #Конец Формирование данных и обновление каталога сайта#
        }

        $this->DeleteNotCOGoods();

        $this->UpdateStatus(0);

        $this->EndDebugTime(__FUNCTION__);
        $this->Logger->log("LOG", "Затраченное время: \r\n".print_r($this->debugData, true));
        $this->Logger->log("LOG", "Обработка закончена");
    }

    private function updateOldPrices()
    {
        $this->Logger->log("LOG", "Обновление старых цен");

        $basePriceCurrency = "RUB";
        $sql = "SELECT CURRENCY FROM b_catalog_currency WHERE BASE = 'Y'";
        $res = $this->connection->query($sql);
        if ($item = $res->fetch()) {
            $basePriceCurrency = $item["CURRENCY"];
        }

        $sql = "SELECT GOODS_SITE_ID, PRICE FROM catalog_app_data";
        $res = $this->connection->query($sql);
        while($arItem = $res->fetch()) {
            $arFields = Array(
                "PRODUCT_ID" => $arItem['GOODS_SITE_ID'],
                "CATALOG_GROUP_ID" => 3,
                "PRICE" => $arItem['PRICE'],
                "CURRENCY" => $basePriceCurrency
            );

            $priceRes = CPrice::GetList(
                array(),
                array(
                    "PRODUCT_ID" => $arItem['GOODS_SITE_ID'],
                    "CATALOG_GROUP_ID" => 3
                )
            );

            if ($arr = $priceRes->Fetch()) {
                if (!empty($arr["CURRENCY"])) {
                    $arFields["CURRENCY"] = $arr["CURRENCY"];
                }

                CPrice::Update($arr["ID"], $arFields);
            } else {
                CPrice::Add($arFields);
            }
        }

        $this->Logger->log("LOG", "Цены обновлены");
    }

    private function updateByStock(): void
    {
        $this->Logger->log("LOG", "Обновляем данные по складам");

        $sql = "SELECT GOODS_SITE_ID, GOODS_XML_ID FROM catalog_app_data";
        $res = $this->connection->query($sql);
        while($arItem = $res->fetch()) {
            $arSiteCatalogByXmlId[$arItem['GOODS_XML_ID']] = $arItem['GOODS_SITE_ID'];
        }

        $arStores = [];
        $dbResult = CCatalogStore::GetList(
            ['PRODUCT_ID' => 'ASC', 'ID' => 'ASC'],
            ['ACTIVE' => 'Y', "ID" => 1],
            false,
            false,
            ["ID", "TITLE", "XML_ID"]
        );
        while ($arItem = $dbResult->Fetch()) {
            $arStores[$arItem['XML_ID']] = $arItem;
        }

        $sql = "DELETE FROM b_catalog_store_product";
        $this->connection->query($sql);

        $modelsSuppliers = [];
        foreach ($this->arSuppliers as $supplierId => $supplier) {
//            if (!empty($arStores[$supplier['externalId']])) {
                $supplierModels = $this->restAPI->GetSupplierPrices($supplierId);

                foreach ($supplierModels as $model) {
                    if (empty($model['inStockAmount'])) {
                        continue;
                    }

                    $xmlId = $model['model']['externalId'] ?? $model['model']['id'];
                    $count = $model['inStockAmount'];

                    if(!empty($arSiteCatalogByXmlId[$xmlId])) {
                        if (empty($modelsSuppliers[$model['model']['externalId']][$model['supplier']['name']])) {
                            $modelsSuppliers[$model['model']['externalId']][$model['supplier']['name']] = $model['supplier']['name']. ' - '.$model['price'].' - '.$model['inStockAmount'].';';
                        }

                        $data = [
                            "PRODUCT_ID" => $arSiteCatalogByXmlId[$xmlId],
                            "STORE_ID" => 1,
                            "AMOUNT" => $count
                        ];

//                        $this->Logger->log("LOG", print_r($data, true));

                        $sql = "SELECT ID FROM b_catalog_store_product WHERE PRODUCT_ID = '{$arSiteCatalogByXmlId[$xmlId]}' AND STORE_ID = 1";
                        $res = $this->connection->query($sql);
                        if ($arItem = $res->fetch()) {
                            \CCatalogStoreProduct::Update($arItem['ID'], $data);
                        } else {
                            \CCatalogStoreProduct::Add($data);
                        }
                    }
                }
//            }
        }

        $this->Logger->log("LOG", "Обновление данных поставщиков");
        foreach ($modelsSuppliers as $xmlId => $suppliers) {
            if (empty($arSiteCatalogByXmlId[$xmlId])) {
                continue;
            }

            $supplierData = '';
            foreach ($suppliers as $supplier) {
                $supplierData .= $supplier.' ';
            }

            $this->Logger->log("LOG", "Обновляем данные для товара [{$xmlId}]: {$supplierData}");
            CIBlockElement::SetPropertyValuesEx($arSiteCatalogByXmlId[$xmlId], $this->catalogIblockId, array('SUPPLIERS' => $supplierData));
        }

        $this->Logger->log("LOG", "Обновление завершено");
    }

    private function DeleteNotCOGoods () {
        $this->Logger->log("LOG", "Удаляем цену товарам не в ЦО");
        $COGoods = [];

        $sql = "SELECT GOODS_SITE_ID FROM catalog_app_data";
        $res = $this->connection->query($sql);
        while($arItem = $res->fetch()) {
            $COGoods[$arItem['GOODS_SITE_ID']] = $arItem['GOODS_SITE_ID'];
        }

        $goodsToDelete = [];
        $filter = ['IBLOCK_ID' => $this->catalogIblockId, "!ID" => $COGoods];
        $select = ['ID'];
        $dbl = CIBlockElement::GetList(["ID" => "ASC"], $filter, false, false, $select);
        while ($arItem = $dbl->Fetch()) {
            $goodsToDelete[] = $arItem["ID"];
        }

        $this->Logger->log("LOG", "Товаров для удаления: ".count($goodsToDelete));

        if (!empty($goodsToDelete)) {
            $sql = "DELETE FROM b_catalog_price WHERE PRODUCT_ID IN (".implode(",", $goodsToDelete).")";
            $this->connection->query($sql);

            /*foreach ($goodsToDelete as $pid) {
                if(!CIBlockElement::Delete($pid)) {
                    $this->Logger->log("ERROR", "Ошибка при удалении товара [{$pid}]");
                } else {
                    $this->Logger->log("LOG", "Товар [{$pid}] удален");
                }
            }*/
        }

        $this->Logger->log("LOG", "Удаление завершено");
    }

    /**
     * Получить завершенные задачи из таблицы
     */
    private function GetCompletedTask() {
        $this->StartDebugTime(__FUNCTION__);
        $this->Logger->log("LOG", "Получаем данные по завершенным задачам");
        $this->arCompletedTasks = $this->restAPI->CheckCompletedTacks();

        $this->Logger->log("LOG", "Кол-во завершенных задач ".count($this->arCompletedTasks));
        $this->EndDebugTime(__FUNCTION__);
    }

    private function ClearProductsData()
    {
        $this->Logger->log("LOG", "Удаление цен и обнуление остатков");

        $COProductIds = [];
        $sql = "SELECT GOODS_SITE_ID FROM catalog_app_data";
        $res = $this->connection->query($sql);
        foreach ($res as $row) {
            $COProductIds[] = $row['GOODS_SITE_ID'];
        }

        if (!empty($COProductIds)) {
            $notCOProducts = [];

            $arSelect = ["ID"];
            $arFilter = ["IBLOCK_ID" => $this->catalogIblockId, "!ID" => $COProductIds];
            $rsResCat = CIBlockElement::GetList(Array("ID" => "ASC"), $arFilter, false, false, $arSelect);
            while($arItem = $rsResCat->GetNext()) {
                $notCOProducts[] = $arItem['ID'];
            }

            $this->Logger->log("LOG", "Товаров для обработки ".count($notCOProducts));
            if (!empty($notCOProducts)) {
                $notCOProductIds = [];
                foreach ($notCOProducts as $notCOProduct) {
                    $this->Logger->log("LOG", "Удаление цены для товара [{$notCOProduct}]");
//                    if (!CPrice::DeleteByProduct($notCOProduct)) {
//                        $this->Logger->log("ERROR", "Ошибка при удалении цены товара!!!");
//                    }

                    $notCOProductIds[] = $notCOProduct;
                }

                $sql = "UPDATE b_catalog_product SET QUANTITY=0 WHERE ID IN (".implode(",", $notCOProductIds).")";
                $this->Logger->log("LOG", "sql {$sql}");
                $this->connection->query($sql);


                $this->Logger->log("LOG", "Чистим склады");
                $sql = "UPDATE b_catalog_store_product SET AMOUNT=0 WHERE PRODUCT_ID IN (".implode(",", $notCOProductIds).")";
                $this->connection->query($sql);
            }
        }

    }

    /**
     * Получить завершенные задачи из таблицы
     */
    private function UpdateCompletedTaskStatus($taskId = 0, $status = 0) {
        $this->StartDebugTime(__FUNCTION__);
        $this->Logger->log("LOG", "Обновляем статус задачи ".$taskId);
        $this->restAPI->UpdateCompletedTaskStatus($taskId, $status);

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * Получить модели из catalogApp
     */
    private function GetModes() {
        $this->StartDebugTime(__FUNCTION__);
        $this->Logger->log("LOG", "Получаем данные о моделях из catalogApp");

        if (empty($this->arCatalogAppModels)) {
            $ModelFile = $_SERVER["DOCUMENT_ROOT"] . "/upload/CatalogAppModels.txt";

//            if (file_exists($ModelFile) && (time() - filemtime($ModelFile)) > 86400) {
//                $models = file_get_contents($ModelFile);
//                $arCatalogAppModels = unserialize($models);
//            } else {
                $arCatalogAppModels = $this->restAPI->GetModels();

                file_put_contents($ModelFile, serialize($arCatalogAppModels));
//            }

            foreach ($arCatalogAppModels as $arItem) {
                $this->arCatalogAppModels[$arItem["id"]] = $arItem;
            }
        }

        $this->Logger->log("LOG", "Всего получено моделей ".count($this->arCatalogAppModels));
        $this->EndDebugTime(__FUNCTION__);
    }

    private function GetVendors() {
        $this->StartDebugTime(__FUNCTION__);
        $this->Logger->log("LOG", "Получаем данные о брендах из catalogApp");

        $arCatalogAppVendors = $this->restAPI->GetVendors();

        foreach ($arCatalogAppVendors as $arItem) {
            $this->arCatalogAppVendors[$arItem["id"]] = $arItem;
        }

        $this->Logger->log("LOG", "Всего получено брендов ".count($this->arCatalogAppVendors));
        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * Сохранить полученные данные по завершеным таскам
     *
     * @param $arProfileData
     * @throws \Bitrix\Main\Db\SqlQueryException
     */
    private function SaveProfileData($arProfileData) {
        $this->StartDebugTime(__FUNCTION__);
        $dataCount = count($arProfileData);
        $this->Logger->log("LOG", "Количество записей для обработки: ".$dataCount);
        $this->Logger->log("LOG", "Сохраняем данные профиля");

        $delSql = "DELETE FROM ".$this->uploadTable." WHERE SITE_PROFILE_ID = ".$this->currentProfileId;
        $this->connection->query($delSql);

        if (!empty($arProfileData)) {
            $chunks = array_chunk($arProfileData, 1500);
            $this->Logger->log("LOG", "Обрабатываем записи по ".count($chunks[0]). " записей");
            $proceed = 0;
            foreach ($chunks as $ck => $chunk) {
                $sql = "INSERT INTO ".$this->uploadTable." (
                `GOODS_XML_ID`, 
                `GOODS_SITE_ID`, 
                `CATALOG_APP_ID`, 
                `DELIVERY_TIME`, 
                `DELIVERY_COUNTRY_TIME`, 
                `DELIVERY_PRICE`, 
                `DELIVERY_COUNTRY_PRICE`, 
                `INSTALLMENT_PRICE`, 
                `MAX_INSTALLMENT_COST`,
                `MIN_RETAIL_PRICE`, 
                `PRICE`, 
                `ORIGINAL_PRICE`, 
                `PROFIT`,
                `CURRENCY`,  
                `IN_STOCK_AMOUNT`, 
                `SITE_CATEGORY_ID`, 
                `CATALOG_APP_CATEGORY`, 
                `VENDOR`, 
                `MODEL`, 
                `ARTICLE`,                
                `COMMENT`, 
                `PRODUCER`, 
                `IMPORTER`, 
                `SERVICE_CENTERS`, 
                `WARRANTY`, 
                `PRODUCT_LIFE_TIME`, 
                `COLOR`, 
                `EAN`, 
                `SITE_PROFILE_ID`, 
                `SUPPLIER_ID`
                ) VALUES ";

                foreach ($chunk as $k => $arItem) {
                    if ($k > 0) {
                        $sql .= ", ";
                    }

                    if (empty($arItem['id'])) {
                        $arItem['id'] = $arItem['nPCatalogAppId'];
                    }

                    $model = $this->arCatalogAppModels[$arItem["nPCatalogAppId"]];
                    $vendor = $this->arCatalogAppVendors[$model['vendor']['id']]['name'];

                    $sql .= "(
                    '".$arItem["id"]."',
                    '".$this->arCatalogDataByXML_ID[$arItem["id"]]["ID"]."',
                    '".$this->restAPI->PrepareCatalogAppId($arItem["catalogAppId"])."',
                    '".$arItem["deliveryTime"]."',
                    '".($arItem["deliveryTime"] + $this->arExportAttributes[$arItem["exportAttributeId"]]["deliveryCountryTime"])."',
                    '".$arItem["deliveryTownPrice"]."',
                    '".$arItem["deliveryCountryPrice"]."',
                    '".$arItem["installmentPrice"]."',
                    '".$arItem["maxInstallmentCost"]."',
                    '".$arItem["minRetailPrice"]."',
                    '".$arItem["price"]."',
                    '".$arItem["originalPrice"]."',
                    '".$arItem["profit"]."',
                    '".$arItem["currency"]."',
                    '".$arItem["inStockAmount"]."',
                    '".$this->arCatalogDataByXML_ID[$arItem["id"]]["IBLOCK_SECTION_ID"]."',
                    '".$this->arCatalogAppModels[$arItem["nPCatalogAppId"]]["category"]["id"]."',
                    '".addslashes($vendor)."',
                    '".addslashes($this->arCatalogAppModels[$arItem["nPCatalogAppId"]]['name'])."',
                    '".addslashes($this->arCatalogAppModels[$arItem["nPCatalogAppId"]]["article"])."',
                    '".addslashes($this->arExportAttributes[$arItem["exportAttributeId"]]["comment"])."',
                    '".addslashes($this->arExportAttributes[$arItem["exportAttributeId"]]["producer"])."',
                    '".addslashes($this->arExportAttributes[$arItem["exportAttributeId"]]["importer"])."',
                    '".addslashes($this->arExportAttributes[$arItem["exportAttributeId"]]["serviceCenters"])."',
                    '".$this->arExportAttributes[$arItem["exportAttributeId"]]["warranty"]."',
                    '".$this->arExportAttributes[$arItem["exportAttributeId"]]["productLifeTime"]."',
                    '".$arItem["color"]."',
                    '".$arItem["ean"]."',
                    '".$arItem["profileId"]."',
                    '".$this->arSuppliers[$arItem["supplierId"]]["externalId"]."'
                    )";
                }

                $proceed += count($chunk);
                $this->connection->query($sql);
                $this->Logger->log("LOG", "Обрабатано ".$proceed." из ".$dataCount);
            }
        } else {
            $this->Logger->log("LOG", "Нет данных для обработки");
        }

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * получить сопоставление разделов catalogApp и сайта
     *
     * @throws \Bitrix\Main\Db\SqlQueryException
     * @throws \Bitrix\Main\SystemException
     */
    private function GetSectionsConnections() {
        $this->Logger->log("LOG", "Получаем сопоставления разделов");
        $this->StartDebugTime(__FUNCTION__);

        $sConnect = new ImarketSectionsConnections();
        $this->sectionConnections = $sConnect->getAll();

        $this->Logger->log("LOG", "Данные получены, всего сопоставленных разделов ".count($this->sectionConnections));

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * Сформировать данные для сохранения
     */
    private function PrepareGoodsData() {
        $this->Logger->log("LOG", "Подготавливаем данные о товарах для работы");
        $this->StartDebugTime(__FUNCTION__);

        foreach ($this->arCatalogApp as $profileId => $arItems) {
            foreach ($arItems as $catalogAppId => $arItem) {
                if (!$this->profilesRules[$profileId]["SAVE"] || $this->profilesRules[$profileId]["SKIP"]) {
                    continue;
                }

                // пропускаем еще не созданные товары
                if (empty($arItem["GOODS_SITE_ID"])) {
                    continue;
                }

                $this->arCatalogApp[$arItem["SITE_PROFILE_ID"]][$catalogAppId]["SITE_CATEGORY_ID"] = $arItem['SITE_CATEGORY_ID'];
                $this->arCatalogApp[$arItem["SITE_PROFILE_ID"]][$catalogAppId]["PROFIT"] = $arItem['PROFIT'] ? $arItem['PROFIT'] : 0;

                // TODO попробовать обойтить без отдельных массивов!!!
                if (isset($arItem['ORIGINAL_PRICE']) && $arItem['ORIGINAL_PRICE'] > 0) {
                    if ($arItem['PRICE'] == $arItem['ORIGINAL_PRICE']) {
                        // если цена равна оригинальной цене - в акции
                        if (is_array($this->actionProducts) && !array_key_exists($arItem['GOODS_SITE_ID'], $this->actionProducts)) {
                            $this->toActions[$arItem['GOODS_SITE_ID']] = $arItem['GOODS_SITE_ID'];
                        }
                    } elseif ($arItem['ORIGINAL_PRICE'] > $arItem['PRICE']) {
                        // если оригинальная цена больше обычной цены - в скидки
                        $this->toDiscounts[$arItem['GOODS_SITE_ID']] = ["DISCOUNT_PRICE" => $arItem['PRICE'], "PRICE" => $arItem['ORIGINAL_PRICE']];

                        $this->arCatalogApp[$profileId][$catalogAppId]["PRICE"] = $arItem['ORIGINAL_PRICE'];
                    }
                } else {
                    // если нет originalPrice
                    if (array_key_exists($arItem['GOODS_SITE_ID'], $this->actionProducts)) {
                        // если товар в акциях - снимаем флаг акция
                        $this->removeFromActions[$arItem['GOODS_SITE_ID']] = $arItem['GOODS_SITE_ID'];
                    }

                    if (array_key_exists($arItem['GOODS_SITE_ID'], $this->discountProducts)) {
                        // если товар в скидках - тоже снимаем флаг акции
                        $this->removeFromDiscounts[$arItem['GOODS_SITE_ID']] = $arItem['GOODS_SITE_ID'];
                    }
                }

                $pSort = $this->arCatalogDataById[$arItem["GOODS_SITE_ID"]]["SORT"];
                $sortIndex = null;

                if ($pSort < -499) {
                    $sortIndex = $pSort;
                } else {
                    $sortIndex = $arItem["DELIVERY_TIME"] == 0 ? 0 : 100000;
                    $sortIndex -= $arItem["IN_STOCK_AMOUNT"];
                    $productIndex = $arItem["GOODS_SITE_ID"] % 100;
                    $sortIndex += $productIndex;
                }

                $sortIndex = intVal($sortIndex);
                $this->sortIndex[$sortIndex][$arItem["GOODS_SITE_ID"]] = $arItem["GOODS_SITE_ID"]; // TODO попробовать обойтись без отдельного массива
                $this->arCatalogApp[$arItem["SITE_PROFILE_ID"]][$catalogAppId]["SORT"] = $sortIndex;
                $this->arFinalGoodsIds[$arItem["GOODS_SITE_ID"]] = $arItem["GOODS_SITE_ID"];
            }
        }

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * обновление данных товара
     *
     * @return bool
     */
    private function UpdateGoodsData() {
        $this->Logger->log("LOG", "Обновляем данные по товарам");
        $this->StartDebugTime(__FUNCTION__);

        if (empty($this->arCatalogApp)) {
            $this->Logger->log("LOG", "Нет товаров для обработки");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        $basePriceCurrency = "RUB";
        $sql = "SELECT CURRENCY FROM b_catalog_currency WHERE BASE = 'Y'";
        $res = $this->connection->query($sql);
        if ($item = $res->fetch()) {
            $basePriceCurrency = $item["CURRENCY"];
        }

		$arSiteProductIds = [];
        $counter = 0;
        $itemsCount = 0;
        $productStatuses = [];
        foreach ($this->arCatalogApp as $profileId => $arItems) {
            $arStock = [];
            $arPrices = [];

            $itemsCount += count($this->arCatalogApp[$profileId]);
            foreach ($arItems as $catalogAppId => $arItem) {
                $counter++;
                if(empty($arItem["GOODS_SITE_ID"])) {
                    continue;
                }

                $arPrices[$arItem["PRICE"]][] = $arItem["GOODS_SITE_ID"];
                $arStock[$arItem["IN_STOCK_AMOUNT"]][] = $arItem["GOODS_SITE_ID"];
				$arSiteProductIds[] = $arItem["GOODS_SITE_ID"];

//				CCatalogProduct::Update($arItem["GOODS_SITE_ID"], ['UF_DELIVERY' => $arItem['DELIVERY_TIME']]);
//                CIBlockElement::SetPropertyValuesEx($arItem["GOODS_SITE_ID"], $this->catalogIblockId, ['SROK_POSTAVKI' => $arItem['DELIVERY_TIME']]);
            }

            // обновление цены товара
            if (!empty($arPrices)) {
                $this->Logger->log("LOG", "Обновляем цены");

                foreach ($arPrices as $price => $goodsIds) {
                    foreach ($goodsIds as $id) {
                        if (empty($this->arPricingRules[$profileId]["PRICE_ID"]) || $price < 2) {
                            continue;
                        }

                        $this->Logger->log("LOG", "Обновляем цену {$price} для товара ".$id." из профиля ".$profileId);
                        $arFields = Array(
                            "PRODUCT_ID" => $id,
                            "CATALOG_GROUP_ID" => ($this->arPricingRules[$profileId]["PRICE_ID"] ? $this->arPricingRules[$profileId]["PRICE_ID"] : 1),
                            "PRICE" => $price,
                            "CURRENCY" => $basePriceCurrency
                        );

                        $res = CPrice::GetList(
                            array(),
                            array(
                                "PRODUCT_ID" => $id,
                                "CATALOG_GROUP_ID" => ($this->arPricingRules[$profileId]["PRICE_ID"] ? $this->arPricingRules[$profileId]["PRICE_ID"] : 1)
                            )
                        );

                        if ($arr = $res->Fetch()) {
                            if (!empty($arr["CURRENCY"])) {
                                $arFields["CURRENCY"] = $arr["CURRENCY"];
                            }

                            CPrice::Update($arr["ID"], $arFields);
                        } else {
                            CPrice::Add($arFields);
                        }
                    }

                    /*$chunks = array_chunk($goodsIds, 5000);
                    foreach ($chunks as $chunk) {
                        $this->Logger->log("LOG", "Обновляем цены '".$price."' товарам ".count($chunk));

                        $sql = "UPDATE b_catalog_price 
                            SET 
                                PRICE = ".$price.", 
                                PRICE_SCALE = ".$price.", 
                                CATALOG_GROUP_ID = '".($this->arPricingRules[$profileId]["PRICE_ID"] ? $this->arPricingRules[$profileId]["PRICE_ID"] : 1)."'
                            WHERE PRODUCT_ID IN (".implode(",", $chunk).") AND CATALOG_GROUP_ID = '".($this->arPricingRules[$profileId]["PRICE_ID"] ? $this->arPricingRules[$profileId]["PRICE_ID"] : 1)."'";
                        $this->connection->query($sql);
                    }*/
                }
            }

            // ставим товарам количество
            if (!empty($arStock)) {
                $this->Logger->log("LOG", "Обновляем количество");
                foreach ($arStock as $amount => $goodsIds) {
                    $chunks = array_chunk($goodsIds, 5000);
                    foreach ($chunks as $chunk) {
                        $this->Logger->log("LOG", "Обновляем количество '".$amount."' товарам ".count($chunk));

                        $sql = "UPDATE b_catalog_product SET
                            AVAILABLE = 'Y',
                            QUANTITY = ".$amount."
                        WHERE
                            ID IN (".implode(",", $chunk).")";
                        $this->connection->query($sql);
                    }
                }
            }

            $this->Logger->log("LOG", "Обработано ".$counter." из ".$itemsCount);
        }
		
		if (!empty($arSiteProductIds)) {
			$sql = "DELETE FROM b_catalog_price WHERE PRODUCT_ID IN (".implode(",", $arSiteProductIds).") AND PRICE = 0";
			$this->connection->query($sql);
		}

        $this->EndDebugTime(__FUNCTION__);
    }

    private function CheckProductsPrice () {
        $this->Logger->log("LOG", "Проверяем цены у товаров");
        $this->StartDebugTime(__FUNCTION__);

        if (empty($this->arCatalogApp)) {
            $this->Logger->log("LOG", "Нет товаров для обработки");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        $sql = "SELECT PRODUCT_ID, CATALOG_GROUP_ID FROM b_catalog_price";
        $res = $this->connection->query($sql);
        while ($arItem = $res->fetch()) {
            $this->arGoodsPrices[$arItem["PRODUCT_ID"]][$arItem["CATALOG_GROUP_ID"]] = $arItem;
        }

        foreach ($this->arCatalogApp as $profileId => $arItems) {
            foreach ($arItems as $catalogAppId => $arItem) {
                if (empty($arItem["GOODS_SITE_ID"])) {
                    continue;
                }

                if (empty($this->arGoodsPrices[$arItem["GOODS_SITE_ID"]][$this->arPricingRules[$profileId]["PRICE_ID"]])) {
                    $sql = "INSERT INTO b_catalog_price 
                        (`PRODUCT_ID`, `CATALOG_GROUP_ID`, `PRICE`) VALUES 
                        ('".$arItem["GOODS_SITE_ID"]."', '".$this->arPricingRules[$profileId]["PRICE_ID"]."', '0')";
                    $this->connection->query($sql);
                }
            }
        }

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * установка активности товарам
     * @return bool
     */
    private function SetActive() {
        $this->Logger->log("LOG", "Устанавливаем активность товарам");
        $this->StartDebugTime(__FUNCTION__);

        $isAutoActivate = false;

        if (!$this->arCASettings["AUTO_UPDATE_GOODS"]) {
            $this->Logger->log("LOG", "Товары не активируются автоматически, делается вручную!");
            $this->EndDebugTime(__FUNCTION__);

            $isAutoActivate = false;
        }

        if (empty($this->arFinalGoodsIds)) {
            $this->Logger->log("LOG", "Нет товаров для обработки");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        $this->Logger->log("LOG", "Всего товаров для проверки ".count($this->arFinalGoodsIds));

        $arGoodsToActive = [];
        // $arGoodsToActive = $this->arFinalGoodsIds;
        /* $arSelect = ["ID", "PROPERTY_CA_AUTO_ACTIVATE", "PROPERTY_13494"];
        $arFilter = ["IBLOCK_ID" => $this->catalogIblockId, "ID" => $this->arFinalGoodsIds];
        $rsResCat = CIBlockElement::GetList(Array("ID" => "ASC"), $arFilter, false, false, $arSelect);
        while($arItem = $rsResCat->GetNext()) {
            if ($arItem["PROPERTY_CA_AUTO_ACTIVATE_VALUE"] == 1 && $isAutoActivate) {
                $arGoodsToActive[] = $arItem["ID"];
            } elseif ($arItem["PROPERTY_13494_ENUM_ID"] == 14131) {
                $arGoodsToActive[] = $arItem["ID"];
            }
        } */
		
		$arSelect = ["ID"];
        $arFilter = ["IBLOCK_ID" => $this->catalogIblockId];
        $rsResCat = CIBlockElement::GetList(Array("ID" => "ASC"), $arFilter, false, false, $arSelect);
        while($arItem = $rsResCat->GetNext()) {
            $arGoodsToActive[] = $arItem['ID'];
        }

        $this->Logger->log("LOG", "Товаров для активации ".count($arGoodsToActive));

        /* $arDeactivateGoods = [];
        $arSelect = ["ID"];
        $arFilter = ["IBLOCK_ID" => $this->catalogIblockId, "ACTIVE" => "Y", "!ID" => $arGoodsToActive];
        $rsResCat = CIBlockElement::GetList(Array("ID" => "ASC"), $arFilter, false, false, $arSelect);
        while($arItem = $rsResCat->GetNext()) {
            $arDeactivateGoods[] = $arItem["ID"];
        }

        if (!empty($arDeactivateGoods)) {
            $this->Logger->log("LOG", count($arDeactivateGoods)." товаров(а) будет деактивировано");
            $chunks = array_chunk($arDeactivateGoods, 10000);
            foreach ($chunks as $chunk) {
                $sql = "UPDATE b_iblock_element SET ACTIVE = 'N' WHERE ID IN (".implode(",", $chunk).")";
                $this->connection->query($sql);

                $sql = 'UPDATE b_catalog_product SET QUANTITY = 0, AVAILABLE = "N" WHERE ID IN (' .implode(",", $chunk). ')';
                $this->connection->query($sql);
            }
        } */

        if (!empty($arGoodsToActive)) {
            $this->Logger->log("LOG", count($arGoodsToActive)." товаров(а) будет активировано");
            $chunks2 = array_chunk($arGoodsToActive, 10000);
            foreach ($chunks2 as $chunk2) {
                $sql = "UPDATE b_iblock_element SET ACTIVE = 'Y' WHERE ID IN (".implode(",", $chunk2).")";
                $this->connection->query($sql);

                $this->Logger->log("LOG", "Обновление фасетных индексов");
                foreach ($chunk2 as $gid) {
                    PropertyIndex\Manager::updateElementIndex($this->catalogIblockId, $gid);
                }
            }
        } else {
            $this->Logger->log("LOG", "Нет товаров для активации");
        }

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * Установка сортировке активным товарам
     */
    private function SetSort() {
        $this->Logger->log("LOG", "Устанавливаем сортировку товарам");
        $this->StartDebugTime(__FUNCTION__);

        $updateCount = 0;
        foreach ($this->sortIndex as $sort => $products) {
            if (count($products) == 0) {
                continue;
            }

            $newProducts = [];
            foreach($products as $val) {
                if (empty(trim($val))) {
                    continue;
                }

                $newProducts[] = $val;
            }

            if (empty($newProducts)) {
                continue;
            }

            $chunks = array_chunk($newProducts, 10000);
            foreach ($chunks as $chunk) {
                $updateCount += count($chunk);
                $sql = 'UPDATE b_iblock_element SET SORT = ' . $sort . ' 
                    WHERE ID IN (' . implode(',', $chunk) . ') AND IBLOCK_ID = ' . $this->catalogIblockId;
                $this->connection->query($sql);

                $sql = "UPDATE b_catalog_product SET AVAILABLE = 'Y' WHERE ID IN (" . implode(',', $chunk) . ")";
                $this->connection->query($sql);
            }
        }

        $this->Logger->log("LOG", "Сортировака обновлена ".$updateCount." товарам");

        $this->Logger->log("LOG", "Сброс сортировки товарам не в ЦО");
        $dropSortIds = [];
        $sql = "SELECT ID FROM b_iblock_element WHERE IBLOCK_ID = '" . $this->catalogIblockId . "' AND ID NOT IN (".implode(',', $this->arFinalGoodsIds).")";
        $res = $this->connection->query($sql);
        while ($arItem = $res->fetch()) {
            $dropSortIds[] = $arItem['ID'];
        }

        $chunks = array_chunk($dropSortIds, 10000);
        foreach ($chunks as $chunk) {
            $sql = 'UPDATE b_iblock_element SET SORT = -1000000
                    WHERE ID IN (' . implode(',', $chunk) . ') AND IBLOCK_ID = ' . $this->catalogIblockId;
            $this->connection->query($sql);

            $sql = "UPDATE b_catalog_product SET AVAILABLE = 'N' WHERE ID IN (" . implode(',', $chunk) . ")";
            $this->connection->query($sql);
        }

        $this->Logger->log("LOG", "Сортировка сброшена ".count($dropSortIds)." товарам");
        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * Установки скидок товарам
     *
     * @return bool
     * @throws \Bitrix\Main\Db\SqlQueryException
     * @throws \Bitrix\Main\SystemException
     */
    private function CreateDiscounts() {
        $this->Logger->log("LOG", "Создание скидок");
        $this->StartDebugTime(__FUNCTION__);

        $sql = 'TRUNCATE TABLE `b_catalog_discount`';
        Application::getInstance()->getConnection()->query($sql);
        $sql = 'TRUNCATE TABLE `b_catalog_discount2product`';
        Application::getInstance()->getConnection()->query($sql);
        $sql = 'TRUNCATE TABLE `b_catalog_discount_cond`';
        Application::getInstance()->getConnection()->query($sql);

        if (empty($this->toDiscounts)) {
            $this->Logger->log("LOG", "Нет товароа для создания скидок");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        // проверим товары, которые должны попасть в скидки
//        $this->Logger->log("LOG", "Проверка товаров, которые должны попасть в скидки");
//        $checkingProducts = array_keys($this->toDiscounts);
//        $filter = ['IBLOCK_ID' => CATALOG_IBLOCK_ID, 'ID' => $checkingProducts];
//        $select = ['ID', 'IBLOCK_SECTION_ID'];
//        $dbl = CIBlockElement::GetList([], $filter, false, false, $select);
//        while ($res = $dbl->Fetch()) {
//            $sId = $res['IBLOCK_SECTION_ID'];
//
//            if (empty($sId) || $sId == STORAGE_SECTION) { // пустой или временный раздел не добавляем в скидки
//                continue;
//            }
//
//            unset($this->toDiscounts[$res['ID']]);
//        }

        if (empty($this->toDiscounts)) {
            $this->Logger->log("LOG", "Нет товароа для создания скидок");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        $arToAdd = [];
        foreach ($this->toDiscounts as $productId => $arItem) {
            $arToAdd[$productId] = [
                'id' => $productId,
                'name' => $this->arCatalogDataById[$productId]["NAME"] . ' - ' . $productId,
                'price' => $arItem["DISCOUNT_PRICE"]
            ];
        }

        if (!empty($arToAdd)) {
            $dayStart = mktime(0, 0, 0);
            $insDate = date('Y-m-d H:i:s', $dayStart);

            $parts = [];
            $valueType = 'S';
            $currency = 'RUB';

            $dbl = CSite::GetList($by = "id", $order = "asc", ['ACTIVE' => 'Y']);
            $siteList = [];
            while ($res = $dbl->Fetch()) {
                $siteId = $res['ID'];
                $siteList[$siteId] = $siteId;
            }

            foreach ($siteList as $siteId => $site) {
                foreach ($arToAdd as $item) {
                    $productId = $item['id'];

                    $discountName = $item['name'];
                    $conditions = ['CLASS_ID' => 'CondGroup', 'DATA' => ['All' => 'AND', 'True' => 'True'], 'CHILDREN' => [0 => ['CLASS_ID' => 'CondIBElement', 'DATA' => ['logic' => 'Equal', 'value' => $productId]]]];

                    $price = floatval($item['price']);
                    $conditions = serialize($conditions);
                    $unPack = '((((isset($arProduct["PARENT_ID"]) ? ((isset($arProduct["ID"]) && ($arProduct["ID"] == ' . $productId . ')) || $arProduct["PARENT_ID"] == ' . $productId . ') : (isset($arProduct["ID"]) && ($arProduct["ID"] == ' . $productId . '))))))';
                    $parts[$siteId][] = "('" . $siteId . "', '" . $insDate . "', '" . addslashes($discountName) . "', 0, '" . $valueType . "', '" . $price . "', '" . $currency . "', '" . $dateCreate . "', '" . $dateCreate . "', 2, '" . addslashes($conditions) . "', '" . addslashes($unPack) . "')";
                }
            }

            if (empty($parts)) {
                $this->Logger->log("LOG", "Нет данных для добавления");
                $this->EndDebugTime(__FUNCTION__);

                return false;
            }

            foreach ($parts as $siteId => $part) {
                foreach ($part as $k => $p) {
                    $sql = '
                        INSERT INTO b_catalog_discount 
                        ( 
                            `SITE_ID`, 
                            `ACTIVE_FROM`, 
                            `NAME`,
                            `MAX_DISCOUNT`, 
                            `VALUE_TYPE`, 
                            `VALUE`, 
                            `CURRENCY`, 
                            `TIMESTAMP_X`,  
                            `DATE_CREATE`,  
                            `VERSION`, 
                            `CONDITIONS`, 
                            `UNPACK`) VALUES '.$p;

                    $this->connection->query($sql);
                }
            }

            $sql = 'SELECT ID, CONDITIONS FROM b_catalog_discount';
            $dbl = $this->connection->query($sql);
            while ($res = $dbl->fetch()) {
                $actionId = $res['ID'];
                $conditions = $res['CONDITIONS'];
                $cond = unserialize($conditions);
                $productId = $cond['CHILDREN'][0]['DATA']['value'];

                $actionIdList[$actionId] = $actionId;
                $actionIdToProduct[$actionId] = $productId;
            }

            if (isset($actionIdList) && count($actionIdList) > 0) {
                foreach ($actionIdList as $actionId) {
                    $sql = 'INSERT INTO b_catalog_discount_cond (`DISCOUNT_ID`, `USER_GROUP_ID`, `PRICE_TYPE_ID`, `ACTIVE`) VALUES (' . $actionId . ', -1, -1, "Y")';
                    Application::getInstance()->getConnection()->query($sql);
                }
            }

            if (isset($actionIdToProduct) && count($actionIdToProduct) > 0) {
                foreach ($actionIdToProduct as $actionId => $productId) {
                    $sql = 'INSERT INTO b_catalog_discount2product (`DISCOUNT_ID`, `PRODUCT_ID`) VALUES (' . $actionId . ',' . $productId . ')';
                    $this->connection->query($sql);
                }
            }
        }

        $this->Logger->log("LOG", "Всего добавлено скидок: ".count($this->toDiscounts));

        $this->EndDebugTime(__FUNCTION__);
    }


    #Helpers
    /**
     * получение правил профилей для работы
     *
     * @throws \Bitrix\Main\Db\SqlQueryException
     */
    private function GetProfilesRules() {
        $this->Logger->log("LOG", "Получение правил профилей");
        $this->StartDebugTime(__FUNCTION__);

        $sql = "SELECT * FROM catalog_app_rules";
        $res = $this->connection->query($sql);
        while($arItem = $res->fetch()) {
            $this->profilesRules[$arItem["SITE_PROFILE_ID"]] = unserialize($arItem["RULES"]);
            $this->arOurPricingProfiles[$arItem["CATALOG_APP_ID"]] = $arItem["SITE_PROFILE_ID"];
            $this->arPricingRules[$arItem["SITE_PROFILE_ID"]] = $arItem;
        }

        $this->Logger->log("LOG", "Всего получено профилей ".count($this->arOurPricingProfiles));
        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * сбросить кэш
     */
    private function clearCache () {
        $this->Logger->log("LOG", "Очистка кеша");
        BXClearCache(true);
        $GLOBALS["CACHE_MANAGER"]->CleanAll();
        $GLOBALS["stackCacheManager"]->CleanAll();
        $page = \Bitrix\Main\Composite\Page::getInstance();
        $page->deleteAll();
		CIBlock::clearIblockTagCache($this->catalogIblockId);
    }

    /**
     * Проверить права профиля на событие и исключить это товары если не прошло
     *
     * @param string $rule
     */
    private function CheckProfilesPermission($rule = '') {
        $this->Logger->log("LOG", "Проверяем профиль на правило ".$rule);
        $this->StartDebugTime(__FUNCTION__);
        $newProductList = [];
        foreach ($this->arCatalogApp as $profileId => $arItems) {
            if (!$this->profilesRules[$profileId][$rule]) {
                $this->Logger->log("LOG", "Убрали товары профиля ".$profileId.", не прошло по правилу ".$rule);
                continue;
            }

            $newProductList[$profileId] = $arItems;
        }

        $this->arCatalogApp = $newProductList;

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     *Получить товары из каталога со скидкой
     */
    private function GetDiscountProducts() {
        $this->Logger->log("LOG", "Получаем товары каталога со скидкой");
        $this->StartDebugTime(__FUNCTION__);

        if (empty($this->discountProducts)) {
            $select = ["ID", "VALUE", "PRODUCT_ID"];
            $dbl = CCatalogDiscount::GetList(["ID" => "ASC"], [], false, false, $select);
            while ($res = $dbl->Fetch()) {
                $actionId = $res['ID'];
                $price = round($res['VALUE'], 2);
                $productId = $res['PRODUCT_ID'];

                $this->discountProducts[$productId][$actionId] = $price;
            }
        }

        $this->Logger->log("LOG", "Получено, всего товаров со скидкой: ".count($this->discountProducts));
        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * Получаем все товары каталога, ключ xml_id
     *
     */
    private function GetCatalogGoods() {
        $this->Logger->log("LOG", "Получаем товары каталога");
        $this->StartDebugTime(__FUNCTION__);

        if (empty($this->arCatalogDataByXML_ID)) {
            $filter = ['IBLOCK_ID' => $this->catalogIblockId];
            $select = ['ID', 'NAME', 'XML_ID', 'SORT', "IBLOCK_SECTION_ID"];
            $dbl = CIBlockElement::GetList(["ID" => "ASC"], $filter, false, false, $select);
            while ($arItem = $dbl->Fetch()) {
                if(!empty($arItem["XML_ID"]) && $arItem["XML_ID"] != $arItem["ID"]) {
                    $this->arCatalogDataByXML_ID[$arItem["XML_ID"]] = $arItem;
                }

                $this->arCatalogDataById[$arItem["ID"]] = $arItem;

                if (!array_key_exists($arItem["ID"], $this->discountProducts)) {
                    $this->actionProducts[$arItem["ID"]] = $arItem["ID"];
                }
            }
        }

        $this->Logger->log("LOG", "Получено, всего товаров: ".count($this->arCatalogDataByXML_ID));
        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * получить поставщика, сервисные центры товаров
     *
     * @param int $profileId
     */
    private function GetExportAttributes($profileId = 0) {
        $this->Logger->log("LOG", "Получаем данные по поставщикам, сервисным центрам...");
        $this->StartDebugTime(__FUNCTION__);

        if (empty($this->arExportAttributes)) {
            $arExportAttributes = $this->restAPI->GetExportAttributes($profileId);

            foreach ($arExportAttributes as $arItem) {
                $this->arExportAttributes[$arItem["id"]] = $arItem;
            }
        }

        $this->Logger->log("LOG", "Получено всего атрибутов: ".count($this->arExportAttributes));

        $this->EndDebugTime(__FUNCTION__);
    }

    /**
     * получить поставщиков из catalogApp
     */
    private function GetSuppliers() {
        $this->Logger->log("LOG", "Получаем данные о поставщиках");
        $this->StartDebugTime(__FUNCTION__);

        if (empty($this->arSuppliers)) {
            $arSuppliers = $this->restAPI->GetSuppliers();

            foreach ($arSuppliers as $arItem) {
                $this->arSuppliers[$arItem["id"]] = $arItem;
            }
        }

        $this->Logger->log("LOG", "Всего получено: ".count($this->arSuppliers));

        $this->EndDebugTime(__FUNCTION__);
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

        if ($this->workerData[$this->workerId]["BUSY"] == 1) {
            return false;
        }

        return true;
    }

    /**
     * Обновить статус обработчика
     * TODO возможно перенести в класс WorkersChecker
     *
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

(new UpdatePriceWorker())->StartWorker();