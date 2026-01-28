<?
if (empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = realpath(dirname(__FILE__) . '/../../../..');
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use ImarketHeplers\ImarketLogger,
    Bitrix\Main\Application;

if (!class_exists("ImarketHeplers\ImarketLogger")) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/ImarketLogger.php');
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/RestAPI.php');
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/workers/WorkersChecker.php');


class DeleteGoodsWorker {
    private $Logger; // класс для логирования
    private $connection; // подключение к БД
    private $workerId = 'deletedGoods'; // id обработчика в табице worker_busy
    private $restAPI; // класс api rest-а
    public $debugData = []; // данные для дебага
    private $workersChecker; // класс для работы с обработчиками
    private $workerData;

    public function __construct () {
        $this->Logger = new ImarketLogger("/upload/log/DeleteGoodsWorker/");
        $this->connection = Application::getConnection();
        $this->restAPI = new RestAPI();
        $this->workersChecker = new WorkersChecker();
    }

    /**
     * Запуск обработчика
     */
    public function StartWorker() {
        $this->Logger->log("LOG", "Начало обработки");
        $this->StartDebugTime(__FUNCTION__);

        if (!$this->CheckStatus()) {
            $this->Logger->log("LOG", "Не нужно обрабатывать");
            return false;
        }

        $this->UpdateStatus(1);

        $this->Logger->log("LOG", "Получение удаленных товаров");
        // $lastUpdateTime = date("Y-m-d", strtotime($this->workerData[$this->workerId]["TIME_END"]))."T".date("H:i:s", strtotime($this->workerData[$this->workerId]["TIME_END"]));
        $d = date('Y-m-d');
        $lastUpdateTime = date('Y-m-d', strtotime($d. ' -1 days')).'T00:00:00';

//		$lastUpdateTime = date("Y-m")."-01T00:00:00";

        $arDeletedGoods = $this->restAPI->GetDeletedGoods($lastUpdateTime);
        $this->Logger->log("LOG", "Получено товаров ".count($arDeletedGoods));

        if (!empty($arDeletedGoods)) {
            foreach ($arDeletedGoods as $arItem) {
                $arCAGoodsXmlIds[] = $arItem["externalId"];
            }

            if (!empty($arCAGoodsXmlIds)) {
                $arChunks = array_chunk($arCAGoodsXmlIds, 5000);
                foreach ($arChunks as $chunk) {
                    $arGoodsIds = [];
                    $arGoodsIdsData = [];
                    $sql = "SELECT ID, NAME FROM b_iblock_element WHERE XML_ID IN ('".implode("','", $chunk)."')";
                    $res = $this->connection->query($sql);
                    while ($propArItem = $res->fetch()) {
                        $arGoodsIds[] = $propArItem["ID"];
                        $arGoodsIdsData[$propArItem["ID"]] = $propArItem;
                    }

                    $this->Logger->log("LOG", "Найдено товаров в каталоге ".count($arGoodsIds));

                    if (!empty($arGoodsIds)) {
                        foreach ($arGoodsIds as $gID) {

                            if(!CIBlockElement::Delete($gID)) {
                                $this->Logger->log("ERROR", "Ошибка при удалении товара ".$arGoodsIdsData[$gID]['NAME']);
                            } else{
                                $this->Logger->log("LOG", "Удален товар " . $arGoodsIdsData[$gID]['NAME']);
                            }
                        }
                    }
                }
            }
        }

        $this->UpdateStatus(0);

        $this->EndDebugTime(__FUNCTION__);
        $this->Logger->log("LOG", "Затраченное время: \r\n".print_r($this->debugData, true));
        $this->Logger->log("LOG", "Обработка закончена");
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

(new DeleteGoodsWorker())->StartWorker();