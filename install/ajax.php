<?require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

if (isset($_REQUEST["action"]) && !empty($_REQUEST["action"])) {
    $action = $_REQUEST["action"];

    if (function_exists($action)) {
        $action();
    }
}

function get_iblocks() {
    CModule::IncludeModule("iblock");
    $ibtype = $_REQUEST["ibtype"];
    $arIblocks = [];
    $res = CIBlock::GetList([], ['TYPE'=> $ibtype], true);
    while ($ar_res = $res->Fetch()) {
        $arIblocks[] = $ar_res;
    }

    if (!empty($arIblocks)) :?>
        <select name="CATALOG_IBLOCK_ID" id="CATALOG_IBLOCK_ID">
            <?foreach ($arIblocks as $arItem) :?>
                <option value="<?=$arItem["ID"]?>"><?=$arItem["NAME"]?></option>
            <?endforeach;?>
        </select>
    <?endif;
}?>