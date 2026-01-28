<?
/**
 * Перенесение товаров из раздела "разобрать" в сопоставленные разделы
 * сопоставления беруться из таблицы section_connections_1c
 * в обработку берутся товары только у которых есть catalogApp id
 *
 * Запуск на кроне раз в сутки
 */

if (empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = realpath(dirname(__FILE__) . '/../../../..');
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Config\Option;
use ImarketHeplers\ImarketLogger,
    Bitrix\Main\Application;

if (!class_exists("ImarketHeplers\ImarketLogger")) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/ImarketLogger.php');
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/RestAPI.php');
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/ImarketSectionsConnections.php');
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/workers/WorkersChecker.php');
set_time_limit(0);
ini_set('memory_limit', '40960M'); // много кушает оперативки!!!!


class MoveGoodsInSectionWorker {
    private $Logger; // класс для логирования
    private $connection; // подключение к БД
    private $restAPI; // класс api rest-а
    public $debugData = []; // данные для дебага
    private $workersChecker; // класс для работы с обработчиками
    private $workerData = []; // данные об обработчиках
    private $workerId = 'moveInSection'; // id обработчика в табице worker_busy
    private $sectionConnections = []; // сопоставление разделов catalogApp и сайта, ключ - id catalogApp
    private $arGoodsToMove = []; // массив товаров для переноса в разделы
    private $arCatalogAppModels = []; // модели catalogApp
    private $arCategories = []; // разделы catalogApp

    private $catalogIblockId = 0;

    public function __construct () {
        $this->Logger = new ImarketLogger("/upload/log/MoveGoodsInSectionWorker/");
        $this->connection = Application::getConnection();
        $this->restAPI = new RestAPI();
        $this->workersChecker = new WorkersChecker();

        $this->catalogIblockId = Option::get("imarket.catalog_app", "CATALOG_IBLOCK_ID");
    }

    public function StartWorker() {
        $this->Logger->log("LOG", "Начало обработки");
        $this->StartDebugTime(__FUNCTION__);

        if (!$this->CheckStatus()) {
            $this->Logger->log("LOG", "Не нужно обрабатывать");
            return false;
        }

        $this->UpdateStatus(1);

        // получение товаров для переноса
        $this->GetGoodsToMove();
        // получить модели catalogApp
        $this->GetModes();
        // получить разделы catalogApp
        $this->GetCategories();

        // получить сопоставление разделов catalogApp и сайта
        $this->GetSectionsConnections();
        // перенос товара в нужный раздел
        $this->MoveGoodsToSections();

        $this->UpdateStatus(0);

        $this->EndDebugTime(__FUNCTION__);
        $this->Logger->log("LOG", "Затраченное время: \r\n".print_r($this->debugData, true));
        $this->Logger->log("LOG", "Обработка закончена");
    }

    /**
     * Получить модели из catalogApp
     */
    private function GetModes() {
        $this->StartDebugTime(__FUNCTION__);
        $this->Logger->log("LOG", "Получаем данные о моделях из catalogApp");

        if (empty($this->arCatalogAppModels)) {
            $arCatalogAppModels = $this->restAPI->GetModels();

            foreach ($arCatalogAppModels as $arItem) {
                $this->arCatalogAppModels[$arItem["id"]] = $arItem;
            }
        }

        $this->Logger->log("LOG", "Всего получено моделей ".count($this->arCatalogAppModels));
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
     * получение товаров для переноса
     */
    private function GetGoodsToMove() {
        $this->StartDebugTime(__FUNCTION__);
        $this->Logger->log("LOG", "Получаем товары для перемещения в разделы");

        $arSelect = ["ID", "PROPERTY_CA_ID", "IBLOCK_SECTION_ID"];
        $arFilter = ["IBLOCK_ID" => $this->catalogIblockId, "!PROPERTY_CA_ID" => ''];
        $rsResCat = CIBlockElement::GetList(Array("ID" => "ASC"), $arFilter, false, false, $arSelect);
        while($arItem = $rsResCat->GetNext()) {
            if (!empty($arItem["PROPERTY_CA_ID_VALUE"])) {
                $this->arGoodsToMove[$arItem["PROPERTY_CA_ID_VALUE"]] = $arItem;
            }
        }

        $this->Logger->log("LOG", "Всего товаров для переноса ".count($this->arGoodsToMove));
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
     * перенос товара в нужный раздел
     *
     * @return bool
     */
    private function MoveGoodsToSections() {
        $this->Logger->log("LOG", "Переносим товары в разделы");
        $this->StartDebugTime(__FUNCTION__);

        if (empty($this->arGoodsToMove)) {
            $this->Logger->log("LOG", "Нет товаров для переноса");
            $this->EndDebugTime(__FUNCTION__);
            return false;
        }

        $el = new CIBlockElement;
        $updateCounter = 0;
        foreach ($this->arGoodsToMove as $catalogAppId => $arItem) {
            $catalogAppModel = $this->arCatalogAppModels[$catalogAppId];

            if ($arItem["IBLOCK_SECTION_ID"] == $this->sectionConnections[$catalogAppModel["category"]["id"]]) {
                continue;
            }

            $this->Logger->log("LOG", "Переносим товар ".$catalogAppId);

            if (empty($catalogAppModel["category"]["id"])) {
                continue;
            }

            if (!empty($this->arCategories[$catalogAppModel["category"]["id"]]["singularName"])) {
                $sectionName = $this->arCategories[$catalogAppModel["category"]["id"]]["singularName"];
            } else {
                $sectionName = $catalogAppModel["category"]["name"];
            }

            $name = trim($sectionName). " ".trim($catalogAppModel["vendor"]["name"])." ".trim($catalogAppModel["name"]);

            if (!empty($catalogAppModel["color"])) {
                $name = trim($name);
                $name .= " ".trim($catalogAppModel["color"]);
            }

            if (!empty($catalogAppModel["article"]) && ($catalogAppModel["article"] != $catalogAppModel["name"])) {
                $name = trim($name);
                $name .= " [".trim($catalogAppModel["article"])."]";
            }

            $name = str_replace(["&quot;", "&amp;quot;"], '"', $name);

            $arLoadProductArray = ["IBLOCK_SECTION" => $this->sectionConnections[$catalogAppModel["category"]["id"]], "NAME" => $name];
            $el->Update($arItem["ID"], $arLoadProductArray);

            $updateCounter++;
        }

        $this->Logger->log("LOG", "Всего перенесено ".$updateCounter);
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

        if ($this->workerData[$this->workerId]["UF_BUSY"] == 1) {
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

(new MoveGoodsInSectionWorker())->StartWorker();