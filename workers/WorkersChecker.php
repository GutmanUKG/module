<?
/**
 * проверка занятости обработчиков
 */

if (empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = realpath(dirname(__FILE__) . '/../../../..');
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use ImarketHeplers\ImarketLogger,
    Bitrix\Main\Application;

if (!class_exists("ImarketHeplers\ImarketLogger")) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/ImarketLogger.php');
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/function.php');

class WorkersChecker {
    private $Logger;
    private $connection;
    private $table = 'catalog_app_workers';
    private $arData;

    public function __construct () {
        $this->Logger = new ImarketLogger("/upload/log/WorkersChecker/");
        $this->connection = Application::getConnection();
    }

    public function Check($workerId = '') {
        $this->Logger->log("LOG", "Старт проверки обработчиков");
        $this->GetWorkersData($workerId);

        return $this->arData;
    }

    private function GetWorkersData($workerId = '') {
        $this->arData = [];

        $sql = "SELECT * FROM ".$this->table;
        if (!empty($workerId)) {
            $sql .= " WHERE WORKER_ID = '".$workerId."'";
            $this->Logger->log("LOG", "Получаем данные по обработчику ".$workerId);
        } else {
            $this->Logger->log("LOG", "Получаем список обработчиков");
        }

        $res = $this->connection->query($sql);
        while($arItem = $res->fetch()) {
            $this->arData[$arItem["WORKER_ID"]] = $arItem;
        }

        $this->Logger->log("LOG", "Получено, всего обработчиков ".count($this->arData));
    }

    public function UpdateWorkerStatus($worketId = '', $workerStatus = 0) {
        $this->Logger->log("LOG", "Обновляем статус обработчику ".$worketId);
        $insDate = date('Y-m-d H:i:s', time());
        $sql = "UPDATE ".$this->table." SET BUSY = ".$workerStatus;

        if ($workerStatus == 1) {
            $sql .= ", TIME_START = ".StringEscape($insDate);
        } else {
            $sql .= ", TIME_END = ".StringEscape($insDate);
        }

        $sql .= " WHERE WORKER_ID = '".$worketId."'";

        $this->connection->query($sql);
    }

    public function UpdateWorkerStart($worketId = '', $workerStart = 0) {
        $this->Logger->log("LOG", "Обновляем параметр старта обработчику ".$worketId." на ".$workerStart);
        $sql = "UPDATE ".$this->table." SET NEED_START = ".$workerStart." WHERE WORKER_ID = '".$worketId."'";
        $this->connection->query($sql);
    }
}
