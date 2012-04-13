<?
    // уровень показа ошибок
    error_reporting(E_ERROR | E_PARSE);

    $prop = array(
        "backupDirName" => "backup",
        "iniPath" => "config.ini",
        "sqlDumpName" => "dump.sql");
        
    $state = "show";

    if(!empty($_POST))
    {
        try
        {
            if($_POST['action'] == 'maketar')
            {
                maketar($prop);
                $success = "Архив успешно создан. ";
            }
            elseif($_POST['action'] == 'untar')
            {
                
                untar($prop, $_POST);       
                initDB($prop, $_POST);
                updateIni($prop, $_POST);
                $success = "Восстановление прошло успешно. ";
                
                $state = "show"; 
            }
        }
        catch(Exception $e)
        {
            $error = $e->getMessage();
            $success = "";
        }
    }
    
    if(!empty($_GET))
    {
        if(!empty($_GET['backup-file']))
        {
            $state = "untar";
            $fileName = $_GET['backup-file'];
        }
        if(!empty($_GET['backupstatus']) && !empty($error))
        {
            $state = "untar";
            $fileName = $_POST['filename'];
        }
    }
    
    $backupFiles = getBackupFiles($prop); 
    
    
    // создание архива
    function maketar($prop)
    {
        $ini = parse_ini_file($prop["iniPath"]);
        if(!$ini)
            throw new Exception("Невозможно обработать файл " . $prop["iniPath"] . "! ");
        
        $output = array();
        $code;
        exec("mysqldump -u {$ini['core.login']} --single-transaction -p{$ini['core.password']} {$ini['core.dbname']} > {$prop['sqlDumpName']}", $output, $code);
        if($code > 0)
            throw new Exception("Ошибка создания дампа базы " . $prop['sqlDumpName'] . "! ");
        
        if(!is_dir($prop["backupDirName"]))
        {
            $result = mkdir($prop["backupDirName"]);
            if(!$result)
                throw new Exception("Ошибка создания директории " . $prop["backupDirName"] . "! ");
        }
        
        $name = $prop["backupDirName"] . "/" . date("Y-m-d_H-i-s") . "_backup.tar";
        exec("tar -cvf" . $name . " *", $output, $code);
        if($code > 0)
            throw new Exception("Ошибка создания архива " . $name . "! ");
        
        exec("tar --delete --file=" . $name . " " . $prop['backupDirName'], $output, $code);
        if($code > 0)
            throw new Exception("Ошибка изменения архива " . $name . "! ");
        
        exec("tar --delete --file=" . $name . " " . basename(__FILE__), $output, $code);
        if($code > 0)
            throw new Exception("Ошибка изменения архива " . $name . "! ");
        
        exec("gzip " . $name, $output, $code);
        if($code > 0)
            throw new Exception("Ошибка gzip архивации " . $name . "! ");
        
        exec("rm " . $prop["sqlDumpName"], $output, $code);
        if($code > 0)
            throw new Exception("Ошибка удаления временного дампа! ");
        
        exec("chmod 777 -R " . $prop['backupDirName'], $output, $code);
        //if($code > 0)
        //    throw new Exception("Ошибка изменения прав доступа к архиву! ");
    }
    
    // получение списка архивов
    function getBackupFiles($prop)
    {
        if(!is_dir($prop['backupDirName']))
            return array();
        
        $files = scandir($prop['backupDirName'], 1);
    
        $tarFiles = array();
        foreach($files as $file)
        {
            if(substr_count($file, "_backup.tar.gz"))
                $tarFiles[] = $file;
        }

        return $tarFiles;
    }
    
    // разархивирование
    function untar($prop, $userData)
    {       
        $output = array();
        $code;
        exec("tar -xvf " . $prop["backupDirName"] . "/" . $userData['filename'], $output, $code);

        if($code != 0)
            throw new Exception("Ошибка распаковки архива " . $fileName . "! ");
    }
    
    // настойка БД. загрузкка в неё данных из дампа
    function initDB($prop, $userData)
    {
        if(empty($userData['host']) ||
            empty($userData['bd_name']) ||
            empty($userData['u_name']) ||
            empty($userData['u_pass']))
            throw new Exception("Недостаточно данных для работы с БД! ");
        
        // создание новой БД если нужно
        if(!empty($userData['bd_create']))
        {
            if(empty($userData['r_name']) ||
                empty($userData['r_pass']))
                throw new Exception("Недостаточно данных для работы администратора с БД! ");

            $link = mysql_connect($userData['host'], $userData['r_name'], $userData['r_pass']);
            if(!$link)
                throw new Exception("Невозможно подключиться к MySQL");
            
            $query = "CREATE DATABASE IF NOT EXISTS `" . $userData['bd_name'] . "`";
            $result = mysql_query($query);
            if(!$result)
                throw new Exception("Невозможно создать БД " . $userData['bd_name']);   
        }
        
        // выгрузка БД в базу
        $output = array();
        $code;
        exec("mysql -u" . $userData['u_name'] . " -h" . $userData['host'] . " -p" . $userData['u_pass'] . " " . $userData['bd_name'] . " " . " < " . $prop["sqlDumpName"], $output, $code);

        if($code > 0)
            throw new Exception("Ошибка восстановления БД! ");
            
        // удаление файла дампа БД
        exec("rm " . $prop["sqlDumpName"], $output, $code);
        if($code > 0)
            throw new Exception("Невозможно удалить файл дампа БД! ");
    }
    
    // обновление конфигурационнаого ini файла
    function updateIni($prop, $userData)
    {
        if(empty($userData['host']) ||
            empty($userData['bd_name']) ||
            empty($userData['u_name']) ||
            empty($userData['u_pass']))
            throw new Exception("Недостаточно данных для обновления " . $prop["iniPath"] . "! ");
        
        $handle = @fopen($prop["iniPath"], "r+");
        $handleWrite = @fopen("New" . $prop["iniPath"], "c+");
        if ($handle) {
            while (($buffer = fgets($handle)) !== false)
            {
                $iniStr = parse_ini_string($buffer);
                
                if(array_key_exists('core.host', $iniStr))
                    $fwrite = fwrite($handleWrite, 'core.host = "' . $userData['host'] . '"' . "\n");
                elseif(array_key_exists('core.login', $iniStr))
                    $fwrite = fwrite($handleWrite, 'core.login = "' . $userData['u_name'] . '"' . "\n");
                elseif(array_key_exists('core.password', $iniStr))
                    $fwrite = fwrite($handleWrite, 'core.password = "' . $userData['u_pass'] . '"' . "\n");
                elseif(array_key_exists('core.dbname', $iniStr))
                    $fwrite = fwrite($handleWrite, 'core.dbname = "' . $userData['bd_name'] . '"' . "\n");
                else
                    $fwrite = fwrite($handleWrite, $buffer);
                
                if ($fwrite == false) {
                    throw new Exception("Ошибка записи в " . $prop["iniPath"] . "! ");
                }
            }
            if (!feof($handle)) {
                throw new Exception("Ошибка чтения " . $prop["iniPath"] . "! ");
            }
            
            fclose($handle);
            fclose($handleWrite);
            
            // удаление старого ini файла
            $result = unlink($prop["iniPath"]);
            if(!$result)
                throw new Exception("Невозможно удалить временный файл! ");
            
            // переименование временного файла
            $result = rename("New" . $prop["iniPath"], $prop["iniPath"]);
            if(!$result)
                throw new Exception("Невозможно переименовать временный файл! ");
        }
    }

?>

<!DOCTYPE html>
<html>
    <head>
        <title>Резервное копирование</title>
        <meta charset="UTF-8">
        <link href="http://install.umi-cms.ru/style_2.css" type="text/css" rel="stylesheet" />
        <script src="http://install.umi-cms.ru/js/jquery-1.4.2.min.js" type="text/javascript"></script>
        <script src="http://install.umi-cms.ru/js/jquery.corner.js" type="text/javascript"></script>
        <!--[if IE]><script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script><![endif]-->
        
        <style type="text/css">
            .not-show { 
                display: none;
            }
            body {
                -moz-border-bottom-colors: none;
                -moz-border-image: none;
                -moz-border-left-colors: none;
                -moz-border-right-colors: none;
                -moz-border-top-colors: none;
                border-color: #EDEDED #CECECE #CECECE;
                border-radius: 10px 10px 10px 10px;
                border-right: 1px solid #CECECE;
                border-style: solid;
                border-width: 1px;
                box-shadow: 5px 5px 5px #DADADA, -0.4em 0 0.2em #DADADA;
            }
            form {
                background: none;
                border-color: none;
                border-radius: 0;
                border-style: solid;
                border-width: 0;
                box-shadow: none;
            }
            input {
                display: block;
                margin-bottom: 12px;
            }
            span {
                color: #353944;
                font-size: 20px;
                text-align: left;
            }
            .indent {
                margin-left: 50px;
            }
            .footer {
                width: 900px;
            }
            .notice.error {
                background: pink;
                border: 1px solid red;
            }
            .notice.success {
                background: lightgreen;
                border: 1px solid green;
            }
            .notice {
                background: url("img/iconic/gray_dark/minus_alt_24x24.png") no-repeat scroll 10px center lightyellow;
                border: 1px solid gold;
                margin: 10px 0;
                padding: 10px 20px 10px 40px;
            }
       </style>
        
        <script type="text/javascript">
	        $(document).ready(function(){
                $('#bd_create').live("change", function(){
                    if($("#bd_create:checked").val())
                    {
                        $('.for_root').removeClass('not-show');
                    }
                    else
                    {
                        $('.for_root').addClass('not-show');
                    }
                });
            });
	    </script>
        
    </head>
    <body>
        <div class="header">
            <p class="check_user">Резервное копирование сайта</p>
        </div>
        <div class="main">
            <div class="shadow_some">
                <div class="padding_big">
                    <? if(!empty($error)) :?>
                        <div class="notice error"><?=$error?></div>
                    <? elseif(!empty($success)) :?>
                        <div class="notice success"><?=$success?></div>
                    <? endif;?>
                    <? if($state == "show") :?>
                        <form class="indent" method="POST">
                            <input type="hidden" value="maketar" name="action">
                            <input class="next_step_submit marginr_px next indent" type="submit" value="Сделать резервную копию">
                        </form>
                            <div class="clear"></div>
                        <form class="indent" method="GET">
                            <div class="backup-files-container">
                                <? foreach($backupFiles as $file) :?>
                                    <div class="backup-files-item">
                                        <span class="indent"><?=$file?>&nbsp;&nbsp;&nbsp;</span><button class="next_step_submit marginr_px next indent" type="submit" name="backup-file" value="<?=$file?>">Распаковать</button>
                                    </div>
                                <? endforeach;?>
                            </div>
                        </form>
                    <? elseif($state == "untar") :?>
                        <form class="indent" method="POST" action="<?=basename(__FILE__)?>?backupstatus=ok">
                            <input type="hidden" value="untar" name="action">
                            <input type="hidden" value="<?=$fileName?>" name="filename">
                            <label for="host">Хост</label>
                            <input id="host" name="host" value="<?=$_POST['host']?>" />
                            <label for="bd_name">Имя БД</label>
                            <input id="bd_name" name="bd_name" value="<?=$_POST['bd_name']?>" />
                            <label for="u_name">Имя пользователя БД</label>
                            <input id="u_name" name="u_name" value="<?=$_POST['u_name']?>" />
                            <label for="u_pass">Пароль пользователя БД</label>
                            <input id="u_pass" name="u_pass" value="<?=$_POST['u_pass']?>" />
                            <label for="bd_create">Создать БД</label>
                            <input type="checkbox" id="bd_create" name="bd_create" />
                            <div class="clear"></div>
                            <div class="for_root not-show">
                                <label for="r_name">Имя администратора БД</label>
                                <input id="r_name" name="r_name" value="<?=$_POST['r_name']?>" />
                                <label for="r_pass">Пароль администратора БД</label>
                                <input id="r_pass" name="r_pass" value="<?=$_POST['r_pass']?>" />
                            </div>
                            <button class="next_step_submit marginr_px next" type="submit">Сохранить</button>
                        </form>
                    <? endif;?>
                </div>
            </div>
        </div>
        <div class="footer">
        </div>
    </body>
</html>