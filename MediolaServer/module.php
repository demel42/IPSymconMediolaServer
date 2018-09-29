<?php

require_once __DIR__ . '/../libs/common.php';  // globale Funktionen

if (!defined('vtBoolean')) {
    define('vtBoolean', 0);
    define('vtInteger', 1);
    define('vtFloat', 2);
    define('vtString', 3);
    define('vtArray', 8);
    define('vtObject', 9);
}

if (!defined('otCategory')) {
    define('otCategory', 0);
    define('otInstance', 1);
    define('otVariable', 2);
    define('otScript', 3);
    define('otEvent', 4);
    define('otMedia', 5);
    define('otLink', 6);
}

class MediolaServer extends IPSModule
{
    use MediolaServerCommon;

    private $semaphoreID = __CLASS__;
    private $semaphoreTM = 250;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('hostname', '');
        $this->RegisterPropertyInteger('port', '80');

        $this->RegisterPropertyString('accesstoken', '');
        $this->RegisterPropertyString('password', '');

        $this->RegisterPropertyString('task_ident', 'ips-callback=1');

        // maximales Alter eines Queue-Eintrags
        $this->RegisterPropertyInteger('max_age', 60 * 60);
        // maximales Wartezeit eines Queue-Eintrags nach Task-Aufruf
        $this->RegisterPropertyInteger('max_wait', 10);

        $this->RegisterTimer('Cycle', 0, 'MediolaServer_Cycle(' . $this->InstanceID . ');');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    // Inspired by module SymconTest/HookServe
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->RegisterHook('/hook/MediolaServer');
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $vpos = 0;
        $this->MaintainVariable('Queue', $this->Translate('CallBack-Queue'), vtString, '', $vpos++, true);
        $this->MaintainVariable('UnfinishedActions', $this->Translate('Count of unfinished actions'), vtInteger, '', $vpos++, true);

        $hostname = $this->ReadPropertyString('hostname');
        $port = $this->ReadPropertyInteger('port');
        $accesstoken = $this->ReadPropertyString('accesstoken');
        $password = $this->ReadPropertyString('password');

        if ($hostname == '' || $port == 0) {
            $this->SetStatus(201);
        } else {
            $this->SetStatus(102);
        }

        // Inspired by module SymconTest/HookServe
        // Only call this in READY state. On startup the WebHook instance might not be available yet
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RegisterHook('/hook/MediolaServer');
        }

        $this->SetTimer();
    }

    public function Cycle()
    {
        $this->CheckAction();
        $this->SetTimer();
    }

    public function SetTimer()
    {
        $n = $this->GetValue('UnfinishedActions');
        if ($n) {
            $msec = 250;
        } else {
            $sdata = $this->GetValue('Queue');
            if ($sdata != '') {
                $ts = time();
                $actions = json_decode($sdata, true);
                foreach ($actions as $action) {
                    if ($action['creation'] < $ts) {
                        $id = $action['id'];
                        $ts = $action['creation'];
                    }
                }
                $max_age = $this->ReadPropertyInteger('max_age');
                $sec = $ts - time() + $max_age;
                if ($sec < 1) {
                    $sec = 1;
                }
                $msec = $sec * 1000;
            } else {
                $msec = 0;
            }
        }
        $this->SendDebug(__FUNCTION__, 'unfinished actions=' . $n . ', msec=' . $msec, 0);
        $this->SetTimerInterval('Cycle', $msec);
    }

    public function GetConfigurationForm()
    {
        $formElements = [];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'hostname', 'caption' => 'Hostname'];
        $formElements[] = ['type' => 'Label', 'label' => 'Port is 80 for the Gateway and typically 8088 for the NEO Server'];
        $formElements[] = ['type' => 'NumberSpinner', 'name' => 'port', 'caption' => 'Port'];

        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'accesstoken', 'caption' => 'Accesstoken'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'password', 'caption' => 'Password'];

        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'task_ident', 'caption' => 'Ident of mediola-task'];
        $formElements[] = ['type' => 'NumberSpinner', 'name' => 'max_age', 'caption' => 'Max. age of queue'];
        $formElements[] = ['type' => 'NumberSpinner', 'name' => 'max_wait', 'caption' => 'Max. wait for reply'];

        $formActions = [];
        $formActions[] = ['type' => 'Button', 'label' => 'Verify Configuration', 'onClick' => 'MediolaServer_VerifyConfiguration($id);'];
        $formActions[] = ['type' => 'Button', 'label' => 'Show Queue', 'onClick' => 'MediolaServer_ShowQueue($id);'];
        $formActions[] = ['type' => 'Label', 'label' => '____________________________________________________________________________________________________'];
        $formActions[] = [
                            'type'    => 'Button',
                            'caption' => 'Module description',
                            'onClick' => 'echo "https://github.com/demel42/IPSymconMediolaServer/blob/master/README.md";'
                        ];

        $formStatus = [];
        $formStatus[] = ['code' => '101', 'icon' => 'inactive', 'caption' => 'Instance getting created'];
        $formStatus[] = ['code' => '102', 'icon' => 'active', 'caption' => 'Instance is active'];
        $formStatus[] = ['code' => '104', 'icon' => 'inactive', 'caption' => 'Instance is inactive'];
        $formStatus[] = ['code' => '201', 'icon' => 'error', 'caption' => 'Instance is inactive (invalid configuration)'];

        return json_encode(['elements' => $formElements, 'actions' => $formActions, 'status' => $formStatus]);
    }

    public function VerifyConfiguration()
    {
        $msg = '';
        $err = '';
        $ret = $this->do_HttpRequest('/info', []);
        if ($ret != '') {
            if (isset($ret['XC_SUC'])) {
                $data = $ret['XC_SUC'];
                $name = $data['name'];
                $msv = $data['msv']; // Firmware
                $hwv = $data['hwv']; // Hardware-Version
                $start = $data['start']; // Startzeitpunkt
                switch ($hwv) {
                    case 'A1':
                        $hw = 'NEO Server';
                        break;
                    case 'E1':
                        $hw = 'Gateway V5';
                        break;
                    case 'EA':
                        $hw = 'Gateway V5+';
                        break;
                    default:
                        $hw = $this->Translate('unknown type') . ' \'' . $hwv . '\'';
                        break;
                }

                $msg = PHP_EOL;
                $msg .= $this->Translate('check succeeded') . ':' . PHP_EOL;
                $msg .= '  ' . $this->Translate('Name') . ': ' . $name . PHP_EOL;
                $msg .= '  ' . $this->Translate('Hardware') . ': ' . $hw . PHP_EOL;
                $msg .= '  ' . $this->Translate('Firmware') . ': ' . $msv . PHP_EOL;
                $msg .= '  ' . $this->Translate('Boot time') . ': ' . date('d.m.Y H:i', $start);
            } elseif (isset($ret['XC_ERR'])) {
                $err = $this->Translate('error') . ': ' . $data['msg'];
            } else {
                $err = $this->Translate('unknown result') . ': ' . print_r($ret, true);
            }
        } else {
            $msg = PHP_EOL;
            $msg .= $this->Translate('check failed') . ':' . PHP_EOL;
            $err = $this->Translate('reason unknown');
        }
        if ($err != '') {
            $msg = PHP_EOL;
            $msg .= $this->Translate('check failed') . ':' . PHP_EOL;
            $msg .= '  ' . $err;
        }
        echo $msg;
    }

    public function ShowQueue()
    {
        $msg = $this->Translate('Information of callback-queue') . PHP_EOL;
        $msg .= PHP_EOL;

        $sdata = $this->GetValue('Queue');
        if ($sdata != '') {
            $n_pending = 0;
            $n_wait = 0;
            $n_overdue = 0;
            $n_done = 0;
            $n_ok = 0;
            $n_fail = 0;
            $n_other = 0;

            $msg .= $this->Translate('List of actions') . ':' . PHP_EOL;
            $actions = json_decode($sdata, true);
            foreach ($actions as $action) {
                $id = $action['id'];
                switch ($action['status']) {
                    case 'pending':
                        $n_pending++;
                        break;
                    case 'waiting':
                        $n_wait++;
                        break;
                    case 'overdue':
                        $n_overdue++;
                        break;
                    case 'done':
                        $n_done++;
                        break;
                    case 'ok':
                        $n_ok++;
                        break;
                    case 'fail':
                        $n_fail++;
                        break;
                    default:
                        $n_other++;
                        break;
                }
                $status = $action['status'];
                $data = $action['data'];
                $mode = $data['mode'];
                switch ($mode) {
                    case 'executeCommand':
                        $md = 'cmd';
                        $keys = ['room', 'device', 'action', 'value'];
                        break;
                    case 'executeMacro':
                        $md = 'macro';
                        $keys = ['group', 'macro'];
                        break;
                    case 'getStatus':
                        $md = 'status';
                        $keys = ['room', 'device', 'action', 'variable'];
                        break;
                    default:
                        $keys = [];
                        break;
                }

                $r = '';
                foreach ($keys as $key) {
                    if (isset($data[$key])) {
                        $r .= ($r != '' ? ', ' : '') . $this->Translate($key) . '=' . $data[$key];
                    }
                }
                $s = $this->Translate('id') . '=' . $id;
                $s .= ', ' . $this->Translate('status') . '=' . $status;
                $s .= ', ' . $this->Translate('created') . '=' . date('H:i', $action['creation']);
                $s .= ', ' . $md . ' [' . $r . ']';
                if (isset($action['duration'])) {
                    $s .= ', ' . $this->Translate('duration') . '=' . sprintf('%.2f', $action['duration']);
                }
                if (isset($action['err'])) {
                    $s .= ', ' . $this->Translate('error') . '=' . $action['err'];
                }
                $msg .= '  ' . $s . PHP_EOL;
            }

            $s = '';
            if ($n_pending) {
                $s .= '  ' . $this->Translate('pending') . ': ' . $n_pending . PHP_EOL;
            }
            if ($n_wait) {
                $s .= '  ' . $this->Translate('waiting') . ': ' . $n_wait . PHP_EOL;
            }
            if ($n_overdue) {
                $s .= '  ' . $this->Translate('overdue') . ': ' . $n_overdue . PHP_EOL;
            }
            if ($n_done) {
                $s .= '  ' . $this->Translate('done') . ': ' . $n_done . PHP_EOL;
            }
            if ($n_ok) {
                $s .= '  ' . $this->Translate('ok') . ': ' . $n_ok . PHP_EOL;
            }
            if ($n_fail) {
                $s .= '  ' . $this->Translate('fail') . ': ' . $n_fail . PHP_EOL;
            }
            if ($n_other) {
                $s .= '  ' . $this->Translate('other') . ': ' . $n_other . PHP_EOL;
            }

            $msg .= PHP_EOL;
            $msg .= $this->Translate('Count of actions') . ':' . PHP_EOL;
            $msg .= $s . PHP_EOL;
        } else {
            $msg .= '  ' . $this->Translate('no entries');
        }

        echo $msg;
    }

    // Inspired from module SymconTest/HookServe
    protected function ProcessHookData()
    {
        $root = realpath(__DIR__);
        $uri = $_SERVER['REQUEST_URI'];
        $this->SendDebug(__FUNCTION__, 'uri=' . $uri, 0);
        if (substr($uri, -1) == '/') {
            $this->SendDebug(__FUNCTION__, 'substr uri=' . substr($uri, -1), 0);
            http_response_code(404);
            die('File not found!');
        }

        $pos = strpos($uri, '?');
        $baseuri = $pos ? substr($uri, 0, $pos) : $uri;
        if ($baseuri == '/hook/MediolaServer') {
            $this->Callback($_GET);
            return;
        }
        http_response_code(404);
        die('File not found!');
    }

    private function do_HttpRequest($cmd, $args)
    {
        $hostname = $this->ReadPropertyString('hostname');
        $port = $this->ReadPropertyInteger('port');
        $accesstoken = $this->ReadPropertyString('accesstoken');
        $password = $this->ReadPropertyString('password');

        $url = 'http://' . $hostname;
        if ($port > 0) {
            $url .= ':' . $port;
        }
        $url .= (substr($cmd, 0, 1) != '/' ? '/' : '') . $cmd;
        $n_arg = 0;
        if ($accesstoken != '') {
            $url .= ($n_arg++ ? '&' : '?') . 'at=' . $accesstoken;
        } elseif ($password != '') {
            $url .= ($n_arg++ ? '&' : '?') . 'auth=' . $password;
        }
        if ($args != '') {
            foreach ($args as $arg => $value) {
                $url .= ($n_arg++ ? '&' : '?') . $arg;
                if ($value != '') {
                    $url .= '=' . rawurlencode($value);
                }
            }
        }

        $this->SendDebug(__FUNCTION__, 'http-get: url=' . $url, 0);

        $time_start = microtime(true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $cdata = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, ' => httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);

        $statuscode = 0;
        $err = '';
        $jdata = '';
        $ret = false;
        if ($httpcode != 200) {
            $err = "got http-code $httpcode";
        } else {
            $jdata = json_decode($cdata, true);
            if ($jdata == '') {
                $err = 'malformed response';
                $this->SendDebug(__FUNCTION__, 'cdata=' . print_r($cdata, true), 0);
            } else {
                $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
                if (isset($jdata['XC_SUC'])) {
                    $ret = $jdata;
                } else {
                    if (isset($jdata['XC_ERR'])) {
                        $ret = $jdata;
                        $err = $ret['XC_ERR']['msg'];
                    } else {
                        $ret = false;
                        $err = 'unknown result ' . $cdata;
                    }
                }
            }
        }

        if ($err != '') {
            echo 'url=' . $url . ' => err=' . $err . PHP_EOL;
            $this->SendDebug(__FUNCTION__, ' => err=' . $err, 0);
        }

        return $ret;
    }

    public function CallTask(string $args)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $jargs = json_decode($args, true);
        if ($jargs == '') {
            $n = strpos($args, '=');
            if ($n) {
                $arg = substr($args, 0, $n);
                $value = substr($args, ($n + 1));
            } else {
                $arg = $args;
                $value = '';
            }
            $jargs = [$arg => $value];
        }
        $ret = $this->do_HttpRequest('/tm/http', $jargs);
        $r = $ret != '' && isset($ret['XC_SUC']);
        $ident = '';
        foreach ($jargs as $arg => $value) {
            $ident .= ($ident != '' ? '&' : '') . $arg . '=' . $value;
        }
        $s = 'call task \'' . $ident . '\' on ' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, $s);
        return $r;
    }

    private function setVal(string $adr, string $type, string $value)
    {
        $args = [
                'XC_FNC' => 'setVar',
                'id'     => $adr,
                'type'   => $type,
                'value'  => $value,
            ];
        $ret = $this->do_HttpRequest('/cmd', $args);
        $r = $ret != '' && isset($ret['XC_SUC']);
        return $r;
    }

    public function SetValueString(string $adr, string $sval)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $value = rawurlencode($sval);
        $r = $this->setVal($adr, 'STRING', $value);
        $s = 'set var adr=' . $adr . ', sval=\'' . $sval . '\', value=\'' . $value . '\', host=' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        $s = 'set var \'' . $adr . '\' to value \'' . $sval . '\' on ' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
        IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, $s);
        return $r;
    }

    public function SetValueBoolean(string $adr, bool $bval)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $value = $bval ? 'on' : 'off';
        $r = $this->setVal($adr, 'ONOFF', $value);
        $s = 'set var adr=' . $adr . ', bval=' . ($bval ? 'true' : 'false') . ', value=\'' . $value . '\', host=' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        $s = 'set var \'' . $adr . '\' to value \'' . ($bval ? 'true' : 'false') . '\' on ' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
        IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, $s);
        return $r;
    }

    public function SetValueInteger(string $adr, int $ival)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $value = strtoupper(dechex($ival));
        while (strlen($value) < 4) {
            $value = '0' . $value;
        }
        $r = $this->setVal($adr, 'INT', $value);
        $s = 'set var adr=' . $adr . ', ival=' . $ival . ', value=\'' . $value . '\', host=' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        $s = 'set var \'' . $adr . '\' to value \'' . $ival . '\' on ' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
        IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, $s);
        return $r;
    }

    public function SetValueFloat(string $adr, float $fval)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $value = strtoupper(dechex(floor($fval * 100)));
        while (strlen($value) < 4) {
            $value = '0' . $value;
        }
        $r = $this->setVal($adr, 'FLOAT', $value);
        $s = 'set var adr=' . $adr . ', fval=' . $fval . ', value=\'' . $value . '\', host=' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        $s = 'set var \'' . $adr . '\' to value \'' . $fval . '\' on ' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
        IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, $s);
        return $r;
    }

    private function getVal(string $adr, string $type)
    {
        $args = [
                'XC_FNC' => 'GetStates',
            ];
        $ret = $this->do_HttpRequest('/cmd', $args);
        $r = false;
        if ($ret != '' && isset($ret['XC_SUC'])) {
            $devices = $ret['XC_SUC'];
            $this->SendDebug(__FUNCTION__, 'devices=' . print_r($devices, true), 0);
            foreach ($devices as $device) {
                $this->SendDebug(__FUNCTION__, 'device=' . print_r($device, true), 0);
                if (!(isset($device['adr']) && isset($device['type']))) {
                    continue;
                }
                if ($device['adr'] == $adr && $device['type'] == $type) {
                    $r = $device['state'];
                    break;
                }
            }
        }
        return $r;
    }

    public function GetValueString(string $adr)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $value = $this->getVal($adr, 'STRING');
        $sval = $value ? rawurldecode($value) : '';
        $s = 'get var adr=' . $adr . ', value=\'' . $value . '\', sval=\'' . $sval . '\', host=' . $hostname . ' => ' . ($value ? 'ok' : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        return $sval;
    }

    public function GetValueBoolean(string $adr)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $value = $this->getVal($adr, 'ONOFF');
        $bval = $value == 'ON' ? true : false;
        $s = 'get var adr=' . $adr . ', value=\'' . $value . '\', bval=\'' . ($bval ? 'true' : 'false') . '\', host=' . $hostname . ' => ' . ($value ? 'ok' : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        return $bval;
    }

    public function GetValueInteger(string $adr)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $value = $this->getVal($adr, 'INT');
        $ival = $value ? hexdec($value) : '';
        $s = 'get var adr=' . $adr . ', value=\'' . $value . '\', ival=\'' . $ival . '\', host=' . $hostname . ' => ' . ($value ? 'ok' : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        return $ival;
    }

    public function GetValueFloat(string $adr)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $value = $this->getVal($adr, 'FLOAT');
        $fval = $value ? hexdec($value) / 100.0 : '';
        $s = 'get var adr=' . $adr . ', value=\'' . $value . '\', fval=\'' . $fval . '\', host=' . $hostname . ' => ' . ($value ? 'ok' : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        return $fval;
    }

    public function RunAction(string $data)
    {
        $max_age = $this->ReadPropertyInteger('max_age');

        $time_start = microtime(true);
        $new_id = -1;
        if (IPS_SemaphoreEnter($this->semaphoreID, $this->semaphoreTM)) {
            $sdata = $this->GetValue('Queue');
            $new_actions = [];
            $n_unfinished = 0;
            if ($sdata != '') {
                $actions = json_decode($sdata, true);
                foreach ($actions as $action) {
                    if ($action['creation'] < time() - $max_age) {
                        continue;
                    }
                    if ($action['id'] > $new_id) {
                        $new_id = $action['id'];
                    }
                    $new_actions[] = $action;
                    if (in_array($action['status'], ['pending', 'waiting'])) {
                        $n_unfinished++;
                    }
                }
            }
            $new_id = $new_id == -1 ? 1 : $new_id + 1;
            $s = json_decode($data, true);
            $s['id'] = $new_id;
            if (!isset($s['wait4reply'])) {
                $s['wait4reply'] = false;
            }
            $action = [
                    'id'       => $new_id,
                    'creation' => time(),
                    'data'     => $s,
                    'status'   => 'pending',
                ];
            $new_actions[] = $action;
            $n_unfinished++;
            $sdata = json_encode($new_actions);
            $this->SetValue('Queue', $sdata);
            $this->SetValue('UnfinishedActions', $n_unfinished);
            IPS_SemaphoreLeave($this->semaphoreID);
        } else {
            $this->SendDebug(__FUNCTION__, 'sempahore ' . $this->semaphoreID . ' is not accessable', 0);
        }
        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, 'duration=' . $duration . 's' . ($new_id != -1 ? ' => id=' . $new_id : ' => failed'), 0);
        if ($new_id != -1) {
            $this->Cycle();
        }
        return $new_id;
    }

    public function ExecuteCommand(string $room, string $device, string $action, string $value, bool $wait4reply)
    {
        $data = [
                'mode'       => 'executeCommand',
                'room'       => $room,
                'device'     => $device,
                'action'     => $action,
                'wait4reply' => $wait4reply
            ];
        if ($value != '') {
            $data['value'] = $value;
        }
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($data, true), 0);
        return $this->RunAction(json_encode($data));
    }

    public function ExecuteMakro(string $group, string $macro, bool $wait4reply)
    {
        $data = [
                'mode'       => 'executeMacro',
                'group'      => $group,
                'macro'      => $macro,
                'wait4reply' => $wait4reply
            ];
        return $this->RunAction(json_encode($data));
    }

    public function GetStatus(string $room, string $device, string $variable, int $objID, bool $wait4reply)
    {
        $data = [
                'mode'       => 'getStatus',
                'room'       => $room,
                'device'     => $device,
                'variable'   => $variable,
                'objID'      => $objID,
                'wait4reply' => $wait4reply
            ];
        return $this->RunAction(json_encode($data));
    }

    public function CheckAction()
    {
        $hostname = $this->ReadPropertyString('hostname');
        $task_ident = $this->ReadPropertyString('task_ident');
        $max_wait = $this->ReadPropertyInteger('max_wait');

        $r = true;
        $total_duration = 0;
        while (true) {
            $ac = '';
            $id = '';
            $waiting = false;
            $time_start = microtime(true);
            if (IPS_SemaphoreEnter($this->semaphoreID, $this->semaphoreTM)) {
                $sdata = $this->GetValue('Queue');
                if ($sdata == '') {
                    break;
                }
                $actions = json_decode($sdata, true);
                foreach ($actions as $action) {
                    if ($action['status'] == 'waiting' && isset($action['microtime'])) {
                        $mt = (microtime(true) - $action['microtime']);
                        if ($mt > $max_wait) {
                            continue;
                        }
                        $waiting = true;
                        $id = $action['id'];
                    }
                }
                $new_actions = [];
                $n_unfinished = 0;
                foreach ($actions as $action) {
                    if ($action['creation'] < time() - 60 * 60) {
                        continue;
                    }
                    if (isset($action['microtime'])) {
                        $mt = (microtime(true) - $action['microtime']);
                        if ($mt > $max_wait) {
                            $action['status'] = 'overdue';
                            $action['duration'] = $mt;
                            unset($action['microtime']);
                        }
                    }
                    if ($action['status'] == 'pending' && !$waiting && $id == '') {
                        $ac = $action;
                        $action['status'] = 'waiting';
                        $action['microtime'] = microtime(true);
                        $id = $action['id'];
                    }
                    if (in_array($action['status'], ['pending', 'waiting'])) {
                        $n_unfinished++;
                    }
                    $new_actions[] = $action;
                }
                $sdata = $new_actions != [] ? json_encode($new_actions) : '';
                $this->SetValue('Queue', $sdata);
                $this->SetValue('UnfinishedActions', $n_unfinished);
                IPS_SemaphoreLeave($this->semaphoreID);
                $ok = true;
            } else {
                $this->SendDebug(__FUNCTION__, 'sempahore ' . $this->semaphoreID . ' is not accessable', 0);
                $ok = false;
            }
            $total_duration += round(microtime(true) - $time_start, 2);
            if ($total_duration > 10 && $waiting) {
                $this->SendDebug(__FUNCTION__, 'total_duration=' . $total_duration . ' => abort', 0);
                $ok = false;
            }
            if (!$ok) {
                break;
            }
            if ($id == '') {
                $this->SendDebug(__FUNCTION__, 'no more pending id\'s => abort', 0);
                break;
            }
            if ($waiting) {
                IPS_Sleep(100);
            } else {
                $s = '';
                $keys = ['mode', 'room', 'device', 'action', 'value', 'group', 'macro', 'variable'];
                foreach ($keys as $key) {
                    if (isset($ac['data'][$key])) {
                        $s .= ($s != '' ? ', ' : '') . $key . '=' . $ac['data'][$key];
                    }
                }
                IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, 'trigger action: id=' . $id . ', ' . $s);
                $r = $this->CallTask($task_ident);
                $this->SendDebug(__FUNCTION__, 'call task' . ': id=' . $id . ', action=' . print_r($ac, true) . ', r=' . $r, 0);
                break;
            }
        }
        return $r;
    }

    private function Callback($args)
    {
        $max_age = $this->ReadPropertyInteger('max_age');

        $mode = isset($args['mode']) ? $args['mode'] : '';
        switch ($mode) {
            case 'query':
                $data = '';
                $ret = '';
                if (IPS_SemaphoreEnter($this->semaphoreID, $this->semaphoreTM)) {
                    $ac = '';
                    $id = '';
                    $sdata = $this->GetValue('Queue');
                    if ($sdata != '') {
                        $actions = json_decode($sdata, true);
                        foreach ($actions as $action) {
                            if ($action['status'] == 'waiting') {
                                $ac = $action;
                                $id = $action['id'];
                                $data = $action['data'];
                                break;
                            }
                        }
                    }
                    if ($id != '' && !$data['wait4reply']) {
                        $new_actions = [];
                        $n_unfinished = 0;
                        foreach ($actions as $action) {
                            if (!isset($action['id'])) {
                                continue;
                            }
                            if ($action['creation'] < time() - $max_age) {
                                continue;
                            }
                            if ($action['id'] == $id) {
                                $action['status'] = 'done';
                                if (isset($action['microtime'])) {
                                    $action['duration'] = round(microtime(true) - $action['microtime'], 2);
                                    unset($action['microtime']);
                                }
                                $ac = $action;
                            }
                            $new_actions[] = $action;
                            if (in_array($action['status'], ['pending', 'waiting'])) {
                                $n_unfinished++;
                            }
                        }
                        $sdata = $new_actions != [] ? json_encode($new_actions) : '';
                        $this->SetValue('Queue', $sdata);
                        $this->SetValue('UnfinishedActions', $n_unfinished);
                    }
                    IPS_SemaphoreLeave($this->semaphoreID);
                    $ret = $data != '' ? json_encode($data) : '';
                    $this->SendDebug(__FUNCTION__, 'mode=' . $mode . ', id=' . $id . ', action=' . print_r($ac, true), 0);
                    IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, 'reply ' . $mode . ': id=' . $id . ', data=' . $ret);
                } else {
                    $this->SendDebug(__FUNCTION__, 'mode=' . $mode . ': sempahore ' . $this->semaphoreID . ' is not accessable', 0);
                }
                echo $ret;
                break;
            case 'status':
                $id = isset($args['id']) ? $args['id'] : '';
                $status = isset($args['status']) ? $args['status'] : '';
                $err = isset($args['err']) ? $args['err'] : '';
                if (IPS_SemaphoreEnter($this->semaphoreID, $this->semaphoreTM)) {
                    $ac = '';
                    $sdata = $this->GetValue('Queue');
                    $new_actions = [];
                    $n_unfinished = 0;
                    $actions = json_decode($sdata, true);
                    foreach ($actions as $action) {
                        if ($action['creation'] < time() - $max_age) {
                            continue;
                        }
                        if ($action['id'] == $id) {
                            $action['status'] = $status;
                            if ($err != '') {
                                $action['error'] = $err;
                            }
                            if (isset($action['microtime'])) {
                                $action['duration'] = round(microtime(true) - $action['microtime'], 2);
                                unset($action['microtime']);
                            }
                            $ac = $action;
                        }
                        $new_actions[] = $action;
                        if (in_array($action['status'], ['pending', 'waiting'])) {
                            $n_unfinished++;
                        }
                    }
                    $sdata = $new_actions != [] ? json_encode($new_actions) : '';
                    $this->SetValue('Queue', $sdata);
                    $this->SetValue('UnfinishedActions', $n_unfinished);
                    IPS_SemaphoreLeave($this->semaphoreID);
                    $this->SendDebug(__FUNCTION__, 'mode=' . $mode . ', id=' . $id . ', status=' . $status . ', err=' . $err . ', action=' . print_r($ac, true), 0);
                    if ($err != '') {
                        $this->LogMessage('task failed: err=' . $ac['err'] . ', action=' . print_r($ac, true), KL_WARNING);
                    }
                    IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, 'reply ' . $mode . ': id=' . $id . ', status=' . $status . ($err != '' ? ', err=' . $err : ''));
                } else {
                    $this->SendDebug(__FUNCTION__, 'mode=' . $mode . ', id=' . $id . ': sempahore ' . $this->semaphoreID . ' is not accessable', 0);
                }
                break;
            case 'value':
                $id = isset($args['id']) ? $args['id'] : '';
                $status = isset($args['status']) ? $args['status'] : '';
                $value = isset($args['value']) ? $args['value'] : '';
                if (IPS_SemaphoreEnter($this->semaphoreID, $this->semaphoreTM)) {
                    $ac = '';
                    $sdata = $this->GetValue('Queue');
                    $actions = json_decode($sdata, true);
                    $new_actions = [];
                    $n_unfinished = 0;
                    foreach ($actions as $action) {
                        if ($action['creation'] < time() - $max_age) {
                            continue;
                        }
                        if ($action['id'] == $id) {
                            $action['status'] = $status;
                            $action['value'] = $value;
                            if (isset($action['microtime'])) {
                                $action['duration'] = round(microtime(true) - $action['microtime'], 2);
                                unset($action['microtime']);
                            }
                            $ac = $action;
                        }
                        $new_actions[] = $action;
                        if (in_array($action['status'], ['pending', 'waiting'])) {
                            $n_unfinished++;
                        }
                    }
                    $sdata = $new_actions != [] ? json_encode($new_actions) : '';
                    $this->SetValue('Queue', $sdata);
                    $this->SetValue('UnfinishedActions', $n_unfinished);
                    IPS_SemaphoreLeave($this->semaphoreID);
                    $this->SendDebug(__FUNCTION__, 'mode=' . $mode . ', id=' . $id . ', status=' . $status . ', value=' . $value . ', action=' . print_r($ac, true), 0);
                    IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, 'reply ' . $mode . ': id=' . $id . ', status=' . $status . ', value=' . $value);

                    if (isset($ac['data']['objID'])) {
                        $objID = $ac['data']['objID'];
                        $obj = IPS_GetObject($objID);
                        if ($obj['ObjectType'] == otVariable) {
                            $var = IPS_GetVariable($objID);
                            switch ($var['VariableType']) {
                                case vtBoolean:
                                    switch (strtolower($value)) {
                                        case 'on':
                                            $v = true;
                                            break;
                                        case 'off':
                                            $v = false;
                                            break;
                                        default:
                                            $v = boolval($value);
                                            break;
                                    }
                                    SetValueBoolean($objID, $v);
                                    break;
                                case vtInteger:
                                    SetValueInteger($objID, $value);
                                    break;
                                case vtFloat:
                                    SetValueFloat($objID, $value);
                                    break;
                                case vtString:
                                    SetValueString($objID, $value);
                                    break;
                                default:
                                    $this->SendDebug(__FUNCTION__, 'unsupported type of varіable ' . print_r($var, true), 0);
                                    break;
                            }
                        } elseif ($obj['ObjectType'] == otScript) {
                            IPS_RunScriptEx($objID, ['status' => $ac['status'], 'value' => $ac['value']]);
                        } else {
                            $this->SendDebug(__FUNCTION__, 'unsupported type of object ' . print_r($obj, true), 0);
                        }
                    }
                } else {
                    $this->SendDebug(__FUNCTION__, 'mode=' . $mode . ', id=' . $id . ': sempahore ' . $this->semaphoreID . ' is not accessable', 0);
                }
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'unknown mode \'' . $mode . '\'', 0);
                break;
        }
    }

    public function GetQueue()
    {
        return $this->GetValue('Queue');
    }

    public function GetAction(int $id)
    {
        $ret = '';

        $sdata = $this->GetValue('Queue');
        if ($sdata != '') {
            $actions = json_decode($sdata, true);
            foreach ($actions as $action) {
                if ($action['id'] == $id) {
                    $ret = $action;
                    break;
                }
            }
        }

        return $ret;
    }

    public function GetActionStatus(int $id, int $max_wait)
    {
        $ret = '';
        $time_start = microtime(true);
        while (true) {
            $sdata = $this->GetValue('Queue');
            if ($sdata != '') {
                $actions = json_decode($sdata, true);
                foreach ($actions as $action) {
                    if ($action['id'] == $id) {
                        if (!in_array($action['status'], ['pending', 'waiting'])) {
                            $ret = $action['status'];
                        }
                        break;
                    }
                }
            }
            if ((microtime(true) - $time_start) >= $max_wait) {
                break;
            }
            if ($ret != '') {
                break;
            }
            IPS_Sleep(250);
        }

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, 'id=' . $id . ', status=' . $ret . ', duration=' . $duration, 0);
        return $ret;
    }
}
