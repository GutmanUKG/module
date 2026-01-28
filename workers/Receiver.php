<?
/*
 * Обработчик на который передается информация о законченных задачах из CatalogApp
 * задачи сохраняются в таблицу catalog_app_tasks
 * сохраняются только задачи по ценооразованию со статусом 1 [status = 1]
 *
 * запускается по обращению catalogApp к этому файлу
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
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/ImarketTriggers.php');
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/catalogAppAPI.php');

class Receiver {
    private $Logger;
    private $connection;
    public $debugData = [];
    private $catalogAppAPI;

    public function __construct () {
        $this->Logger = new ImarketLogger("/upload/log/Receiver/");
        $this->connection = Application::getConnection();
        $this->catalogAppAPI = new catalogAppAPI();
    }

    /**
     * Запуск обработчика
     */
    public function StartReceiver() {
        $this->StartDebugTime(__FUNCTION__);
        $this->Logger->log("LOG", "Начало обработки приема данных от CatalogApp");
        $this->SaveTask(); // сохранение отработанных задач
        $this->EndDebugTime(__FUNCTION__);
        $this->Logger->log("LOG", "Обработка закончена, затраченное время: \r\n".print_r($this->debugData, true));
    }

    /**
     * Сохранение отработанных задач
     * @throws \Bitrix\Main\Db\SqlQueryException
     */
    public function SaveTask() {
        $this->StartDebugTime(__FUNCTION__);
        $arData = [];
        $content = file_get_contents('php://input'); // получаем присланные задачи
        $arData = json_decode($content, true);

        if (!empty($arData)) {
            $this->Logger->log("LOG", "Данные для обработки \r\n".print_r($arData, true));

            // сохраняем только ценообраования [Type = Pricing]
            if ($arData["Type"] == 'Pricing') {
                $this->Logger->log("LOG", 'Сохраняем задачу в таблицу');
                $status = 0;
                $isErrorStatus = false;

                switch ($arData["Status"]) {
                    case "Complete":
                        $status = 1;
                        break;
                    case "Failed":
                        $status = 0;
                        $isErrorStatus = true;
                        break;
                    default:
                        $status = -1;
                        break;
                }

                if ($status >= 0) {
                    // сохраняем
                    $sql = "INSERT INTO catalog_app_tasks (`TYPE`, `TASK_ID`, `STATUS`, `NEED_START`, `ADD_DATE`) VALUES 
                    ('1', '".$arData["TaskId"]."', '".$status."', '1', '".date("d.m.Y H:i:s")."')";
                    $this->connection->query($sql);
                    $this->Logger->log("LOG", 'Успешно сохранено');

                    if ($isErrorStatus) {
                        $ImarketTriggers = new ImarketTriggers();

                        $arrTaskInfo = $this->catalogAppAPI->getPricingTaskInfo($arData["TaskId"]);

                        $ImarketTriggers->SetError(["Ошибка при формировании ценообразования ".$arrTaskInfo["pricingProfile"]["name"]]);
                        $ImarketTriggers->SendTriggerErrors();
                    }
                } else {
                    $this->Logger->log("LOG", 'Не определенный статус задачи!');
                }
            } else {
                $this->Logger->log("LOG", 'Задача не по ценообразованию, пропускаем');
            }
        } else {
            $this->Logger->log("ERROR", "Не получили данных \r\n".print_r($content, true)."\r\n".print_r($arData, true));
        }

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

// Запуск обработчика
(new Receiver())->StartReceiver();