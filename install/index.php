<?
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Application;
use Bitrix\Main;

Loc::loadMessages(__FILE__);

Class imarket_catalog_app extends CModule {
    const MODULE_ID = 'imarket.catalog_app';
    var $MODULE_ID = 'imarket.catalog_app';
    var $MODULE_VERSION;
    var $MODULE_VERSION_DATE;
    var $MODULE_NAME;
    var $MODULE_DESCRIPTION;
    var $MODULE_CSS;
    var $strError = '';
    var $NEED_MAIN_VERSION = '20.0';
    var $NEED_MODULES = array();
    var $errors;
    var $modulePath = '';
    var $modulePlace = '';
    private $connection;

    function __construct () {
        $arModuleVersion = array();
        include(dirname(__FILE__) . "/version.php");
        $this->MODULE_VERSION = $arModuleVersion["VERSION"];
        $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
        $this->MODULE_NAME = Loc::getMessage(self::MODULE_ID . "_MODULE_NAME");
        $this->MODULE_DESCRIPTION = Loc::getMessage(self::MODULE_ID . "_MODULE_DESC");

        $this->PARTNER_NAME = Loc::getMessage(self::MODULE_ID . "_PARTNER_NAME");
        $this->PARTNER_URI = Loc::getMessage(self::MODULE_ID . "_PARTNER_URI");

        $this->connection = Application::getConnection();

        $this->modulePath = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/" . self::MODULE_ID;
        $this->modulePlace = 'bitrix/modules';
    }

    function InstallDB ($arParams = array()) {
        global $DB, $APPLICATION;
        $this->errors = false;

        // Database tables creation
        if (!$DB->Query("SELECT 'x' FROM catalog_app_settings WHERE 1 = 0", true))
//            $this->errors = $DB->RunSQLBatch($this->modulePath . "/install/db/" . strtolower($DB->type) . "/install.sql");

        if ($this->errors !== false) {
            $APPLICATION->ThrowException(implode("<br>", $this->errors));
            return false;
        } else {
            /*$rowExists = false;
            $sql = "SELECT * FROM catalog_app_settings";
            $res = $DB->Query($sql);
            while($arItem = $res->Fetch()) {
                if (!empty($arItem["CATALOG_IBLOCK_ID"])) {
                    $rowExists = true;
                }
            }

            if ($rowExists) {
                $sql = "UPDATE catalog_app_settings SET CATALOG_IBLOCK_ID = ".$_REQUEST["iblock"];
                $DB->Query($sql);
            } else {
                $sql = "INSERT INTO catalog_app_settings (`CATALOG_IBLOCK_ID`) VALUES ('".$_REQUEST["iblock"]."')";
                $DB->Query($sql);
            }*/

            return true;
        }
    }

    function UnInstallDB ($arParams = array()) {
        global $DB, $APPLICATION;
        $this->errors = false;

//        $this->errors = $DB->RunSQLBatch($this->modulePath . "/install/db/" . strtolower($DB->type) . "/uninstall.sql");

        if ($this->errors !== false) {
            $APPLICATION->ThrowException(implode("<br>", $this->errors));
            return false;
        }

        return true;
    }

    function InstallEvents () {
        return true;
    }

    function UnInstallEvents () {
        $eventManager = Main\EventManager::getInstance();
        return true;
    }

    function InstallFiles ($arParams = array()) {
        if (is_dir($p = $this->modulePath . '/admin')) {
            if ($dir = opendir($p)) {
                while (false !== $item = readdir($dir)) {
                    if ($item == '..' || $item == '.' || $item == 'menu.php')
                        continue;

                    file_put_contents($file = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/' . self::MODULE_ID . '_' . $item,
                        '<' . '? require($_SERVER["DOCUMENT_ROOT"]."/'.$this->modulePlace.'/' . self::MODULE_ID . '/admin/' . $item . '");?' . '>');
                }

                closedir($dir);
            }
        }

        if (is_dir($p = $this->modulePath . '/workers')) {
            if ($dir = opendir($p)) {
                while (false !== $item = readdir($dir)) {
                    if ($item == '..' || $item == '.')
                        continue;

                    if (!$this->checkFolderExists($_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . self::MODULE_ID )) {
                        $this->createFolder($_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . self::MODULE_ID);
                    }

                    $text2 = '<' . '? if (empty($_SERVER["DOCUMENT_ROOT"])) {$_SERVER["DOCUMENT_ROOT"] = realpath(dirname(__FILE__) . \'/../../..\');}
                    require($_SERVER["DOCUMENT_ROOT"]."/'.$this->modulePlace.'/' . self::MODULE_ID . '/workers/' . $item . '");?'. '>';

                    file_put_contents($file = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . self::MODULE_ID . '/' . $item, $text2);
                }

                closedir($dir);
            }
        }

        file_put_contents($file = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/' . self::MODULE_ID . '/ajax.php',
            '<' . '? require($_SERVER["DOCUMENT_ROOT"]."/'.$this->modulePlace.'/' . self::MODULE_ID . '/install/ajax.php");?' . '>');

        return true;
    }

    function UnInstallFiles () {
        if (is_dir($p = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/' . self::MODULE_ID . '/admin')) {
            if ($dir = opendir($p)) {
                while (false !== $item = readdir($dir)) {
                    if ($item == '..' || $item == '.')
                        continue;
                    unlink($_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/' . self::MODULE_ID . '_' . $item);
                }
                closedir($dir);
            }
        }

//        if (is_dir($p = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/' . self::MODULE_ID . '/cron/')) {
//            if ($dir = opendir($p)) {
//                while (false !== $item = readdir($dir)) {
//                    if ($item == '..' || $item == '.')
//                        continue;
//
//                    unlink($_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/' . self::MODULE_ID . '/cron/' . $item);
//                }
//                closedir($dir);
//            }
//        }

        return true;
    }

    function DoInstall () {
        global $APPLICATION, $step, $DB;
        if (is_array($this->NEED_MODULES) && !empty($this->NEED_MODULES)) {
            foreach ($this->NEED_MODULES as $module)
                if (!IsModuleInstalled($module))
                    $this->ShowForm('ERROR', Loc::getMessage('Db_NEED_MODULES', array('#MODULE#' => $module)));
        }

        if (strlen($this->NEED_MAIN_VERSION) <= 0 || version_compare(SM_VERSION, $this->NEED_MAIN_VERSION) >= 0) {
            $step = IntVal($step);

            if ($step < 2) {
                $GLOBALS["install_step"] = 1;

                $this->InstallDB();

                if (!IsModuleInstalled(self::MODULE_ID)) {
                    $this->InstallFiles();
                    RegisterModule(self::MODULE_ID);
                }

                $this->ShowForm('OK', Loc::getMessage('MOD_INST_OK'));
//                $APPLICATION->IncludeAdminFile(GetMessage("INSTALL_TITLE"), $this->modulePath."/install/step.php");
            }
            /*elseif ($step == 2) {
                $GLOBALS["errors"] = $this->errors;
                $GLOBALS["install_step"] = 2;

                $this->InstallDB();

                $APPLICATION->IncludeAdminFile(GetMessage("INSTALL_TITLE"), $this->modulePath . "/install/step.php");
            } elseif ($step == 3) {
                $GLOBALS["errors"] = $this->errors;
                $GLOBALS["install_step"] = 3;

                if (isset($_REQUEST["create_worker"])) {
                    CAgent::AddAgent(
                        "CatalogAppAgents::CreateWorker();", // имя функции
                        $this->MODULE_ID,                    // идентификатор модуля
                        "N",                                 // агент не критичен к кол-ву запусков
                        600,                                 // интервал запуска - 1 сутки
                        "",                                  // дата первой проверки на запуск
                        "Y",                                 // агент активен
                        "",                                  // дата первого запуска
                        500);                                // сортировка

                    $sql = "UPDATE catalog_app_settings SET CREATE_WORKER = 1";
                    $DB->Query($sql);
                }

                if (isset($_REQUEST["update_worker"])) {
                    CAgent::AddAgent(
                        "CatalogAppAgents::UpdateWorker();", // имя функции
                        $this->MODULE_ID,                    // идентификатор модуля
                        "N",                                 // агент не критичен к кол-ву запусков
                        180,                                 // интервал запуска - 1 сутки
                        "",                                  // дата первой проверки на запуск
                        "Y",                                 // агент активен
                        "",                                  // дата первого запуска
                        500);                                // сортировка

                    $sql = "UPDATE catalog_app_settings SET UPDATE_WORKER = 1";
                    $DB->Query($sql);
                }

                if (isset($_REQUEST["update_property_worker"])) {
                    CAgent::AddAgent(
                        "CatalogAppAgents::UpdatePropertyWorker();", // имя функции
                        $this->MODULE_ID,                            // идентификатор модуля
                        "N",                                         // агент не критичен к кол-ву запусков
                        350,                                         // интервал запуска - 1 сутки
                        "",                                          // дата первой проверки на запуск
                        "Y",                                         // агент активен
                        "",                                          // дата первого запуска
                        500);                                        // сортировка

                    $sql = "UPDATE catalog_app_settings SET UPDATE_PROPERTY_WORKER = 1";
                    $DB->Query($sql);
                }


                CAgent::AddAgent(
                    "CatalogAppAgents::WorkersChecker();", // имя функции
                    $this->MODULE_ID,                      // идентификатор модуля
                    "N",                                   // агент не критичен к кол-ву запусков
                    350,                                   // интервал запуска - 1 сутки
                    "",                                    // дата первой проверки на запуск
                    "Y",                                   // агент активен
                    "",                                    // дата первого запуска
                    500);                                  // сортировка

                $APPLICATION->IncludeAdminFile(GetMessage("INSTALL_TITLE"), $this->modulePath . "/install/step.php");
            } elseif ($step == 4) {
                $GLOBALS["errors"] = $this->errors;
                $GLOBALS["install_step"] = 4;

                $sql = "UPDATE catalog_app_settings SET CATALOG_APP_USER = '".$_REQUEST["catalogApp_user"]."', CATALOG_APP_PASSWORD = '".$_REQUEST["catalogApp_pass"]."'";
                $DB->Query($sql);
                if (!IsModuleInstalled(self::MODULE_ID)) {
                    $this->InstallFiles();
                    RegisterModule(self::MODULE_ID);
                }
            }*/

            $this->ShowForm('OK', Loc::getMessage('MOD_INST_OK'));
        } else {
            $this->ShowForm('ERROR', Loc::getMessage('Db_NEED_RIGHT_VER', array('#NEED#' => $this->NEED_MAIN_VERSION)));
        }
    }

    function DoUninstall () {
        UnRegisterModule(self::MODULE_ID);
        $this->UnInstallEvents();
        $this->UnInstallDB();
        $this->UnInstallFiles();

        CAgent::RemoveModuleAgents($this->MODULE_ID);
    }

    function ShowForm ($type, $message, $buttonName = '') {
        global $APPLICATION;

        $keys = array_keys($GLOBALS);
        for ($i = 0; $i < count($keys); $i++)
            if ($keys[$i] != 'i' && $keys[$i] != 'GLOBALS' && $keys[$i] != 'strTitle' && $keys[$i] != 'filepath')
                global ${$keys[$i]};

        $PathInstall = str_replace('\\', '/', __FILE__);
        $PathInstall = substr($PathInstall, 0, strlen($PathInstall) - strlen('/index.php'));
        IncludeModuleLangFile($PathInstall . '/install.php');

        $APPLICATION->SetTitle(Loc::getMessage('Db_SCOM_INSTALL_NAME'));
        include($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
        echo CAdminMessage::ShowMessage(array('MESSAGE' => $message, 'TYPE' => $type));
        ?>
        <p>
            <a href="/bitrix/admin/imarket.catalog_app_app.php">Перейти к настройкам</a>
        </p>

        <form action="<?= $APPLICATION->GetCurPage() ?>" method="get">
            <p>
                <input type="hidden" name="lang" value="<?= LANG ?>"/>
                <input type="submit" value="<?= strlen($buttonName) ? $buttonName : Loc::getMessage('MOD_BACK') ?>"/>
            </p>
        </form>

        <?include($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
        die();
    }

    private function checkFolderExists($folder = '') : bool
    {
        if (!$folder) {
            return false;
        }

        if (!file_exists($folder)) {
            return false;
        }

        return true;
    }

    private function createFolder($folder = '') : void
    {
        $folderPath = explode('/', $folder);

        $resultFolder = '';
        foreach ($folderPath as $checkFolder) {
            if (empty($checkFolder)) {
                continue;
            }

            $resultFolder .= '/'.$checkFolder;

            if (!$this->checkFolderExists($resultFolder)) {
                mkdir($resultFolder.'/', 0755);
            }
        }
    }
}
?>