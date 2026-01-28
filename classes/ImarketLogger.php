<?namespace ImarketHeplers;

use Bitrix\Main\Config\Option;

/**
 * Class ImarketLogger
 * @package ImarketHeplers
 */
class ImarketLogger {
    private $loggerLogPath = '/upload/log/Logger/';
    private $logFolder = '/upload/log/';
    public $lastError = '';
    public $lastLog = '';
    
    private $isActive = true;

    /**
     * ImarketLogger constructor.
     * @param string $folderPath
     */
    public function __construct ($folderPath = "") {
        if (!empty($folderPath)) {
            $this->loggerLogPath = $folderPath;

            $folderExists = false;
            $logFolders = $this->GetLoggerSettings();

            if (!empty($logFolders)) {
                foreach ($logFolders as $folder) {
                    if ($folder == $folderPath) {
                        $folderExists = true;
                        break;
                    }
                }
            }

            if (!$folderExists || empty($logFolders)) {
                $logFolders[] = $folderPath;
                $this->SetLoggerSettings($logFolders);
            }
        }

        if (!file_exists($_SERVER["DOCUMENT_ROOT"].$this->logFolder)) {
            mkdir($_SERVER["DOCUMENT_ROOT"].$this->logFolder, 0777, true);
        }
    }

    private function GetLoggerSettings () {
        $logFolders = Option::get("imarketLogger", "logFolders");

        if (!empty($logFolders)) {
            $logFolders = json_decode($logFolders);
        }

        return $logFolders ? $logFolders : [];
    }

    /**
     * @param array $values
     *
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     */
    private function SetLoggerSettings ($values = []) {
        $logFolders = json_encode($values);
        Option::set("imarketLogger", "logFolders", $logFolders);
    }

    /**
     * @param int $type [ERROR, LOG]
     * @param string $str
     * @return bool
     */
    public function log($type = "LOG", $str = "") {
        if (!$this->isActive) {
            return true;
        }

        if (empty($type)) {
            $this->log("ERROR", 'No logger type');
            return $this->lastError;
        }

        if (empty($str)) {
            $this->log("ERROR", 'Empty logger string');
            return $this->lastError;
        }

        $str = date("d.m.Y H:i:s")." ".$type." - ".$str;
        $defaultFileName = '.'.date("d.m.Y").'_loggerLog';

        switch ($type) {
            case "ERROR":
                $fileName = '.'.date("d.m.Y").'_loggerErrors';
                $this->lastError = ["ERROR" => $str];
                $str .= "\r\nBug trace\r\n".print_r(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), true);
                break;
            case "LOG":
            case "DEBUG":
                $fileName = '.'.date("d.m.Y").'_loggerLog';
                $this->lastLog = $str;
                break;
            default:
                $this->logger("ERROR", 'Unknown type for log');
                return $this->lastError;
                break;
        }

        if (!is_dir($_SERVER["DOCUMENT_ROOT"].$this->loggerLogPath)) {
            mkdir($_SERVER["DOCUMENT_ROOT"].$this->loggerLogPath, 0777);
        }

        if ($type != "LOG") {
            $filePath = $_SERVER["DOCUMENT_ROOT"].$this->loggerLogPath.$fileName;
        }

        $defaultFilePath = $_SERVER["DOCUMENT_ROOT"].$this->loggerLogPath.$defaultFileName;

        $str .= "\r\n";
        if ($type == "ERROR") {
            file_put_contents($filePath, $str, FILE_APPEND);
        }

        file_put_contents($defaultFilePath, $str, FILE_APPEND);

        return true;
    }

    public function clearFolders () {
        $this->log("LOG", "Удаление старых логов");

        $folders = $this->GetLoggerSettings();
        $nowTime = strtotime(date("d.m.Y H:i:s"));
        $needTime = 604800; // неделя

        $this->log("LOG", "Получены каталоги ".print_r($folders, true));

        if (!empty($folders)) {
            foreach ($folders as $folder) {
                $this->log("LOG", "Проверяем каталог {$folder}");

                $files = [];
                $RFolder = $folder;
                $folder = realpath(dirname(__FILE__). '/../../../..').$folder;
                $files = scandir($folder);

                if (!empty($files)) {
                    $this->log("LOG", "Проверяем файлы");

                    foreach ($files as $file) {
                        if ($file == "." || $file == "..") {
                            continue;
                        }

                        $fileSize = filesize($folder.$file);
                        $fileTime = filectime($folder.$file);

                        if ($fileSize > 5368709120) { // файл больше 5 ГБ
                            $this->log("LOG", "Файл ".$RFolder.$file." большого размера ".($fileSize / 1024)." MB, удаляем");
                            @unlink($folder.$file);
                        }

                        if (($nowTime - $fileTime) > $needTime) {
                            $this->log("LOG", "Файл ".$RFolder.$file." будет удален");

                            if (file_exists($folder.$file)) {
                                @unlink($folder.$file);
                            }
                        } else {
                            $this->log("LOG", "Файл ".$RFolder.$file." не нужно удалять");
                        }
                    }
                } else {
                    $this->log("LOG", "В каталоге {$RFolder} нет файлов");
                }
            }
        }

        $this->log("LOG", "Удаление завершено");
    }
}
?>