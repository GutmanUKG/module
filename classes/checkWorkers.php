<?
if (empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = realpath(dirname(__FILE__) . '/../../..');
}

require($_SERVER['DOCUMENT_ROOT'] . '/rest/api/WorkersChecker.php');
require($_SERVER['DOCUMENT_ROOT'] . '/local/classes/ImarketTriggers.php');
//
$workerChecker = new WorkersChecker();
$ImarketTriggers = new ImarketTriggers();
// ключ - название обработчика, значение - время, сколько не вызывался (минут)
$arCheckRules = [
    "update" => ["NAME" => "выгрузки из catalogApp", "TIME" => 120],
    "retailCatalog" => ["NAME" => "формирования каталога для RetailCRM", "TIME" => 120],
    "create" => ["NAME" => "создания товаров", "TIME" => 1440],
    "retailPrice" => ["NAME" => "отправки прайсов в RetailCRM", "TIME" => 180],
];
$arData = $workerChecker->Check();
$currentTime = date("d.m.Y H:i:s");

foreach ($arData as $worker => $data) {
    if (!empty($arCheckRules[$worker])) {
        $data["UF_TIME_START"] = (string)$data["UF_TIME_START"];

        if (((strtotime($currentTime) - strtotime($data["UF_TIME_START"])) / 60) > $arCheckRules[$worker]["TIME"]) {
            $ImarketTriggers->SetError(["Проблемы с обработчиком '".$arCheckRules[$worker]["NAME"]."'! Последний запуск был больше ".$arCheckRules[$worker]["TIME"]." минут назад"]);

            if (!empty($ImarketTriggers->GetErrors())) {
                $ImarketTriggers->SendTriggerErrors();
            }
        }
    }
}