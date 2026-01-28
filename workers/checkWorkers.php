<?
if (empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = realpath(dirname(__FILE__) . '/../../../..');
}
use Bitrix\Main\Application;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/workers/WorkersChecker.php');
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/ImarketTriggers.php');

$workerChecker = new WorkersChecker();
$ImarketTriggers = new ImarketTriggers();
// ключ - название обработчика
$arCheckRules = [
    "create" => ["NAME" => "Создания товаров", "TIME" => 1440, "TIME_DESC" => "часов", "FILENAME" => "CreateWorker.php"],
    "update_price" => ["NAME" => "Выгрузки из catalogApp", "TIME" => 60, "TIME_DESC" => "часа", "FILENAME" => "UpdatePriceWorker.php"],
    "update_properties" => ["NAME" => "Обновление свойств", "TIME" => 120, "TIME_DESC" => "часов", "FILENAME" => "UpdatePropertiesWorker.php"],
    "deletedGoods" => ["NAME" => "Удаление товаров", "TIME" => 1440, "TIME_DESC" => "часов", "FILENAME" => "DeleteGoodsWorker.php"],
    "moveInSection" => ["NAME" => "Перемещение товаров в разделы", "TIME" => 1440, "TIME_DESC" => "часов", "FILENAME" => "MoveGoodsInSectionWorker.php"],
];
$arData = $workerChecker->Check();
$currentTime = date("d.m.Y H:i:s");
$arTasks = [];
exec("ps -U bitrix -u bitrix u", $arTasks, $result); // получить все задачи на сервере

foreach ($arData as $worker => $data) {
    if (!empty($arCheckRules[$worker])) {
        $data["TIME_START"] = (string)$data["TIME_START"];

        if (((strtotime($currentTime) - strtotime($data["TIME_START"])) / 60) > $arCheckRules[$worker]["TIME"]) {
            $scriptActive = false;
            foreach ($arTasks as $line) {
                if (strstr($line, $arCheckRules[$worker]["FILENAME"])) {
                    $scriptActive = true;
                }
            }

            // скрипт все еще работает
            if ($scriptActive) {
                $data["TIME_START"] = (string)$data["TIME_START"];
                $hours = round($arCheckRules[$worker]["TIME"] / 60);
                $data["TIME_START"] = date('Y-m-d H:i:s', strtotime("+{$hours} hours", strtotime($data["TIME_START"])));

                $sql = "UPDATE catalog_app_workers SET TIME_START = '{$data["TIME_START"]}' WHERE WORKER_ID = '{$worker}'";
                Application::getConnection()->query($sql);

                $ImarketTriggers->SetError(["Проблемы с обработчиком '".$arCheckRules[$worker]["NAME"]."'! Последний запуск был больше ".round($arCheckRules[$worker]["TIME"] / 60)." ".$arCheckRules[$worker]["TIME_DESC"]." назад. Обработчик все еще работает, изменена дата проверки."]);
            } else { // скрипт не работает
                if ($data["BUSY"] == 1) { // стоит флаг занятости обработчика
                    $sql = "UPDATE catalog_app_workers SET BUSY = 0 WHERE WORKER_ID = '{$worker}'";
                    Application::getConnection()->query($sql);

                    $ImarketTriggers->SetError(["Проблемы с обработчиком '".$arCheckRules[$worker]["NAME"]."'! Последний запуск был больше ".round($arCheckRules[$worker]["TIME"] / 60)." ".$arCheckRules[$worker]["TIME_DESC"]." назад. Перезапущен автоматически."]);
                } else { // флага нет, нужно смотреть в ручную почему не работает
                    $ImarketTriggers->SetError(["Проблемы с обработчиком '".$arCheckRules[$worker]["NAME"]."'! Последний запуск был больше ".round($arCheckRules[$worker]["TIME"] / 60)." ".$arCheckRules[$worker]["TIME_DESC"]." назад."]);
                    $ImarketTriggers->SetError(["Обработчик не занят, нужно разработчикам разобраться в чем ошибка!!! Время проверки сдвинуто на 1 час"]);

                    $data["TIME_START"] = date('Y-m-d H:i:s', strtotime("+1 hours", strtotime($data["TIME_START"])));
                    $sql = "UPDATE catalog_app_workers SET TIME_START = '{$data["TIME_START"]}' WHERE WORKER_ID = '{$worker}'";
                    Application::getConnection()->query($sql);
                }
            }

            if (!empty($ImarketTriggers->GetErrors())) {
                $ImarketTriggers->SendTriggerErrors();
            }
        }
    }
}

// проверить добавление задач
/*global $DB;
$sql = "SELECT ADD_DATE FROM catalog_app_tasks ORDER BY ID DESC LIMIT 1";
$res = $DB->Query($sql);
while($arItem = $res->Fetch()) {
    $lastRow = $arItem;
}

$lastAddTime = time() - strtotime($lastRow["ADD_DATE"]);
if ($lastAddTime / 60 > 14400) {
    $ImarketTriggers->SetError(["Новые задачи по ЦО не добавлялись больше 4-х часов, нужно разобраться в чем ошибка!"]);
    if (!empty($ImarketTriggers->GetErrors())) {
        $ImarketTriggers->SendTriggerErrors();
    }
}*/