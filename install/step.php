<?IncludeModuleLangFile(__FILE__);

$modulePath = "/bitrix/modules/imarket.catalog_app";
$modulePlace = 'bitrix/modules';

if ($GLOBALS["install_step"] < 2) {
    $arIblocksTypes = [];
    $db_iblock_type = CIBlockType::GetList();
    while ($ar_iblock_type = $db_iblock_type->Fetch()) {
        if ($arIBType = CIBlockType::GetByIDLang($ar_iblock_type["ID"], LANG)) {
            $arIblocksTypes[] = $arIBType;
        }
    }?>
    <p><strong>Укажите онфоблок каталога</strong></p>
    <form action="<?=$APPLICATION->GetCurPage()?>" name="form1">
        <?=bitrix_sessid_post()?>
        <input type="hidden" name="lang" value="<?=LANGUAGE_ID?>" />
        <input type="hidden" name="id" value="imarket.catalog_app" />
        <input type="hidden" name="install" value="Y" />
        <input type="hidden" name="step" value="2" />

        <table border="0" cellspacing="0" cellpadding="3">
            <tr>
                <td>
                    <select name="ibtype" id="ibtype">
                        <option>---Выберите тип инфоблока-</option>
                        <?foreach ($arIblocksTypes as $arItem) :?>
                            <option value="<?=$arItem["ID"]?>"><?=$arItem["NAME"]?></option>
                        <?endforeach;?>
                    </select>
                </td>
            </tr>
            <tr>
                <td id="idlockSelectArea"></td>
            </tr>
        </table>
        <input type="submit" name="inst" value="<?=GetMessage("NEXT")?>" />
    </form>

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script>
        $(document).on("change", "#ibtype", function () {
            $.ajax({
                url: '<?=$modulePath?>/install/ajax.php',
                data: 'action=get_iblocks&ibtype=' + $(this).val(),
                type: 'post',
                beforeSend: function () {

                },
                success: function (msg) {
                    $("#idlockSelectArea").html(msg);
                }
            }).done(function () {

            });
        });
    </script>
<?} elseif ($GLOBALS["install_step"] == 2) {?>
    <p><strong>Настройка работы обработчиков</strong></p>
    <form action="<?=$APPLICATION->GetCurPage()?>" name="form1">
        <?=bitrix_sessid_post()?>
        <input type="hidden" name="lang" value="<?=LANGUAGE_ID?>" />
        <input type="hidden" name="id" value="imarket.catalog_app" />
        <input type="hidden" name="install" value="Y" />
        <input type="hidden" name="step" value="3" />

        <table border="0" cellspacing="0" cellpadding="3">
            <tr>
                <td>
                    <label for="create_worker">Включить синхронизацию каталога (создание товаров)</label>
                    <input type="checkbox" value="1" name="create_worker" id="create_worker" />
                </td>
            </tr>
            <tr>
                <td>
                    <label for="update_worker">Включить обновление каталога (цены)</label>
                    <input type="checkbox" value="1" name="update_worker" id="update_worker" />
                </td>
            </tr>
            <tr>
                <td>
                    <label for="update_property_worker">Включить обновление каталога (свойства)</label>
                    <input type="checkbox" value="1" name="update_property_worker" id="update_property_worker" />
                </td>
            </tr>
        </table>
        <input type="submit" name="inst" value="<?=GetMessage("NEXT")?>" />
    </form>


<?} elseif ($GLOBALS["install_step"] == 3) {?>
    <p><strong>Настройка авторизации</strong></p>
    <form action="<?=$APPLICATION->GetCurPage()?>" name="form1">
        <?=bitrix_sessid_post()?>
        <input type="hidden" name="lang" value="<?=LANGUAGE_ID?>" />
        <input type="hidden" name="id" value="imarket.catalog_app" />
        <input type="hidden" name="install" value="Y" />
        <input type="hidden" name="step" value="4" />

        <table border="0" cellspacing="0" cellpadding="3">
            <tr>
                <td>
                    <label for="catalogApp_user">Логин авторизации в catalog.app</label>
                </td>
                <td>
                    <input type="text" value="" name="catalogApp_user" id="catalogApp_user" />
                </td>
            </tr>
            <tr>
                <td>
                    <label for="catalogApp_pass">Пароль авторизации в catalog.app</label>
                </td>
                <td>
                    <input type="text" value="" name="catalogApp_pass" id="catalogApp_pass" />
                </td>
            </tr>
        </table>
        <input type="submit" name="inst" value="<?=GetMessage("NEXT")?>" />
    </form>
<?}?>