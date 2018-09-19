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

class MediolaServer extends IPSModule
{
    use MediolaServerCommon;

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
        $this->RegisterPropertyInteger('max_wait', 3);

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

        $this->SetStatus(102);

        // Inspired by module SymconTest/HookServe
        // Only call this in READY state. On startup the WebHook instance might not be available yet
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RegisterHook('/hook/MediolaServer');
        }
    }

    public function GetConfigurationForm()
    {
        $formElements = [];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'hostname', 'caption' => 'Hostname'];
        $formElements[] = ['type' => 'Label', 'label' => 'Port is 80 for the Gateway and typically 8088 for the NEO Server'];
        $formElements[] = ['type' => 'NumberSpinner', 'name' => 'port', 'caption' => 'Port'];

        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'accesstoken', 'caption' => 'Accesstoken'];
        $formElements[] = ['type' => 'ValidationTextBox', 'name' => 'password', 'caption' => 'Password'];

        $formActions = [];
        $formActions[] = ['type' => 'Button', 'label' => 'Verify Configuration', 'onClick' => 'MediolaServer_VerifyConfiguration($id);'];
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
        $formStatus[] = ['code' => '201', 'icon' => 'inactive (invalid configuration)', 'caption' => 'Instance is inactive (invalid configuration)'];

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

    // Inspired from module SymconTest/HookServe
    protected function ProcessHookData()
    {
        $this->SendDebug(__FUNCTION__, '_SERVER=' . print_r($_SERVER, true), 0);
        $this->SendDebug(__FUNCTION__, '_GET=' . print_r($_GET, true), 0);

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

        $duration = floor((microtime(true) - $time_start) * 100) / 100;
        $this->SendDebug(__FUNCTION__, ' => httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);

        $statuscode = 0;
        $err = '';
        $jdata = '';
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

    private function setVal(string $id, string $type, string $value)
    {
        $args = [
                'XC_FNC' => 'setVar',
                'id'     => $id,
                'type'   => $type,
                'value'  => $value,
            ];
        $ret = $this->do_HttpRequest('/cmd', $args);
        $r = $ret != '' && isset($ret['XC_SUC']);
        return $r;
    }

    public function SetValueString(string $id, string $sval)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $value = rawurlencode($sval);
        $r = $this->setVal($id, 'STRING', $value);
        $s = 'set var ' . $id . ' to value ' . $value . ' on ' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, $s);
        return $r;
    }

    public function SetValueBoolean(string $id, boolean $bval)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $value = $bval ? 'on' : 'off';
        $r = $this->setVal($id, 'ONOFF', $value);
        $s = 'set var ' . $id . ' to value ' . $value . ' on ' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, $s);
        return $r;
    }

    public function SetValueInteger(string $id, integer $ival)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $value = strtoupper(dechex($ival));
        while (strlen($value) < 4) {
            $value = '0' . $value;
        }
        $r = $this->setVal($id, 'INT', $value);
        $s = 'set var ' . $id . ' to value ' . $value . ' on ' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, $s);
        return $r;
    }

    public function SetValueFloat(string $id, float $fval)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $value = strtoupper(dechex(floor($fval * 100)));
        while (strlen($value) < 4) {
            $value = '0' . $value;
        }
        $r = $this->setVal($id, 'FLOAT', $value);
        $s = 'set var ' . $id . ' to value ' . $value . ' on ' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, $s);
        return $r;
    }

    private function getVal(string $id, string $type)
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
                if ($device['adr'] == $id && $device['type'] == $type) {
                    $r = $device['state'];
                    break;
                }
            }
        }
        return $r;
    }

    public function GetValueString(string $id)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $r = $this->getVal($id, 'STRING');
        $value = $r ? rawurldecode($r) : '';
        $s = 'get var ' . $id . ' from ' . $hostname . ' => ' . ($r ? $r : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        return $value;
    }

    public function GetValueBoolean(string $id)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $r = $this->getVal($id, 'ONOFF');
        $value = $r == 'ON' ? true : false;
        $s = 'get var ' . $id . ' from ' . $hostname . ' => ' . ($r ? $r : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        return $value;
    }

    public function GetValueInteger(string $id)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $r = $this->getVal($id, 'INT');
        $value = $r ? hexdec($r) : '';
        $s = 'get var ' . $id . ' from ' . $hostname . ' => ' . ($r ? $r : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        return $value;
    }

    public function GetValueFloat(string $id)
    {
        $hostname = $this->ReadPropertyString('hostname');

        $r = $this->getVal($id, 'FLOAT');
        $value = $r ? hexdec($r) / 100.0 : '';
        $s = 'get var ' . $id . ' from ' . $hostname . ' => ' . ($r ? $r : 'failed');
        $this->SendDebug(__FUNCTION__, $s, 0);
        return $value;
    }

	public function RunAction(string $data)
	{
        $max_age = $this->ReadPropertyInteger('max_age');

		$semaphoreID = __CLASS__;

		$time_start = microtime(true);
		$new_id = -1;
		if (IPS_SemaphoreEnter($semaphoreID, 250)) {
			$sdata = $this->GetValue('Queue');
			$new_actions = [];
			if ($sdata != '') {
				$actions = json_decode($sdata, true);
				foreach ($actions as $action) {
					if (!isset($action['id'])) {
						continue;
					}
					if ($action['creation'] < time() - $max_age) {
						continue;
					}
					$new_actions[] = $action;
					if ($action['id'] > $new_id) {
						$new_id = $action['id'];
					}
				}
			}
			$new_id = $new_id == -1 ? 1 : $new_id + 1;
			$s = json_decode($data, true);
			$s['id'] = $new_id;
			if (!isset($s['async'])) {
				$s['async'] = true;
			}
			$action = [
					'id'       => $new_id,
					'creation' => time(),
					'data'     => $s,
					'status'   => 'pending',
				];
			$new_actions[] = $action;
			$sdata = json_encode($new_actions);
			$this->SetValue('Queue', $sdata);
			IPS_SemaphoreLeave($semaphoreID);
		} else {
			$this->SendDebug(__FUNCTION__, 'sempahore ' . $semaphoreID . ' is not accessable', 0);
		}
		$duration = floor((microtime(true) - $time_start) * 100) / 100;
		$this->SendDebug(__FUNCTION__, 'duration=' . $duration . 's' . ($new_id != -1 ? ' => id=' . $new_id : ' => failed'), 0);
		return $new_id;
	}

	public function ExecuteCommand(string $room, string $device, string $action, string $value, bool $async)
	{
		$data = [ 'mode' => 'executeCommand', 'room' => $room, 'device' => $device, 'action' => $action, 'async' => $async ];
		if ($value != '')
			$data['value'] = $value;
		$this->SendDebug(__FUNCTION__, 'data=' . print_r($data, true), 0);
		return $this->RunAction(json_encode($data));
	}

	public function ExecuteMakro(string $group, string $macro, bool $async)
	{
		$data = [ 'mode' => 'executeMacro', 'group' => $group, 'macro' => $macro, 'action' => $action, 'async' => $async ];
		return $this->RunAction(json_encode($data));
	}

	public function GetStatus(string $room, string $device, string $variable, bool $async)
	{
		$data = [ 'mode' => 'macro', 'room' => $room, 'device' => $device, 'variable' => $variable, 'async' => $async ];
		return $this->RunAction(json_encode($data));
	}

	public function CheckAction()
	{
        $hostname = $this->ReadPropertyString('hostname');
        $task_ident = $this->ReadPropertyString('task_ident');
        $max_wait = $this->ReadPropertyInteger('max_wait');

		$semaphoreID = __CLASS__;

		$r = true;
		$total_duration = 0;
		while (true) {
			$ac = '';
			$id = '';
			$waiting = false;
			$time_start = microtime(true);
			if (IPS_SemaphoreEnter($semaphoreID, 250)) {
				$sdata = $this->GetValue('Queue');
				if ($sdata == '') {
					break;
				}
				$new_actions = [];
				$actions = json_decode($sdata, true);
				foreach ($actions as $action) {
					if (!isset($action['id'])) {
						continue;
					}
					if ($action['status'] == 'wait') {
						if (isset($action['microtime']) && (microtime(true) - $action['microtime']) > $max_wait) {
							continue;
						}
						$waiting = true;
						$id = $action['id'];
					}
				}
				foreach ($actions as $action) {
					if (!isset($action['id'])) {
						continue;
					}
					if ($action['creation'] < time() - 60 * 60) {
						continue;
					}
					if (isset($action['microtime'])) {
						$mt = microtime(true) - $action['microtime'];
						if ($mt > $max_wait) {
							$action['status'] = 'overdue';
							$action['duration'] = $mt;
							unset($action['microtime']);
						}
					}
					if ($action['status'] == 'pending' && ! $waiting && $id == '') {
						$ac = $action;
						$action['status'] = 'wait';
						$action['microtime'] = microtime(true);
						$id = $action['id'];
					}
					$new_actions[] = $action;
				}
				$sdata = json_encode($new_actions);
				$this->SetValue('Queue', $sdata);
				IPS_SemaphoreLeave($semaphoreID);
				$ok = true;
			} else {
				$this->SendDebug(__FUNCTION__, 'sempahore ' . $semaphoreID . ' is not accessable', 0);
				$ok = false;
			}
			$duration = floor((microtime(true) - $time_start) * 100) / 100;
			$this->SendDebug(__FUNCTION__, 'duration=' . $duration . 's, ok=' . ($ok ? 'true' : 'false') . ', waiting=' . ($waiting ? 'true' : 'false') . ', id=' . $id, 0);
			$total_duration += $duration;
			if ($total_duration > 10 && $waiting) {
				$this->SendDebug(__FUNCTION__, 'total_duration=' . $total_duration . ' => abort', 0);
				$ok = false;
			}
			if (!$ok)
				break;
			if ($id == '') {
				$this->SendDebug(__FUNCTION__, 'no more pending id\'s => abort', 0);
				break;
			}
			if ($waiting)
				IPS_Sleep(100);
			else {
				$s = '';
				$keys =  ['mode', 'room', 'device', 'action', 'variable'];
				foreach ($keys as $key) {
					if (isset($ac['data'][$key]))
						$s .= ($s != '' ? ', ' : '') . $key . '=' . $ac['data'][$key];
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

		$semaphoreID = __CLASS__;

		$mode = isset($args['mode']) ? $args['mode'] : '';
		switch ($mode) {
			case 'query':
				$data = '';
				if (IPS_SemaphoreEnter($semaphoreID, 250)) {
					$ac = '';
					$id = '';
					$sdata = $this->GetValue('Queue');
					$actions = json_decode($sdata, true);
					foreach ($actions as $action) {
						if (!isset($action['id'])) {
							continue;
						}
						if ($action['status'] == 'wait') {
							$ac = $action;
							$id = $action['id'];
							$data = $action['data'];
							break;
						}
					}
					
					if ($id != '' && isset($data['async']) && $data['async']) {
						$new_actions = [];
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
									$action['duration'] = floor((microtime(true) - $action['microtime']) * 100) / 100;
									unset($action['microtime']);
								}
							}
							$new_actions[] = $action;
						}
						$sdata = json_encode($new_actions);
						$this->SetValue('Queue', $sdata);
					}
					IPS_SemaphoreLeave($semaphoreID);
					$this->SendDebug(__FUNCTION__, 'mode=' . $mode . ', id=' . $id . ', action=' . print_r($ac, true), 0);
				} else {
					$this->SendDebug(__FUNCTION__,  'mode=' . $mode . ': sempahore ' . $semaphoreID . ' is not accessable', 0);
				}
				echo $data != '' ? json_encode($data) : '';
				break;
			case 'status':
				$id = isset($args['id']) ? $args['id'] : '';
				$status = isset($args['status']) ? $args['status'] : '';
				$err = isset($args['err']) ? $args['err'] : '';
				if (IPS_SemaphoreEnter($semaphoreID, 250)) {
					$ac = '';
					$sdata = $this->GetValue('Queue');
					$new_actions = [];
					$actions = json_decode($sdata, true);
					foreach ($actions as $action) {
						if (!isset($action['id'])) {
							continue;
						}
						if ($action['creation'] < time() - $max_age) {
							continue;
						}
						if ($action['id'] == $id) {
							$ac = $action;
							$action['status'] = $status;
							if ($err != '')
								$action['error'] = $err;
							if (isset($action['microtime'])) {
								$action['duration'] = floor((microtime(true) - $action['microtime']) * 100) / 100;
								unset($action['microtime']);
							}
						}
						$new_actions[] = $action;
					}
					$sdata = json_encode($new_actions);
					$this->SetValue('Queue', $sdata);
					IPS_SemaphoreLeave($semaphoreID);
					$this->SendDebug(__FUNCTION__, 'mode=' . $mode . ', id=' . $id . ', status=' . $status . ', err=' . $err . ', action=' . print_r($ac, true), 0);
				} else {
					$this->SendDebug(__FUNCTION__, 'mode=' . $mode . ', id=' . $id . ': sempahore ' . $semaphoreID . ' is not accessable', 0);
				}
				break;
			case 'value':
				$id = isset($args['id']) ? $args['id'] : '';
				$status = isset($args['status']) ? $args['status'] : '';
				$value = isset($args['value']) ? $args['value'] : '';
				if (IPS_SemaphoreEnter($semaphoreID, 250)) {
					$ac = '';
					$sdata = $this->GetValue('Queue');
					$new_actions = [];
					$actions = json_decode($sdata, true);
					foreach ($actions as $action) {
						if (!isset($action['id'])) {
							continue;
						}
						if ($action['creation'] < time() - $max_age) {
							continue;
						}
						if ($action['id'] == $id) {
							$ac = $action;
							$action['status'] = $status;
							$action['value'] = $value;
							if (isset($action['microtime'])) {
								$action['duration'] = floor((microtime(true) - $action['microtime']) * 100) / 100;
								unset($action['microtime']);
							}
						}
						$new_actions[] = $action;
					}
					$sdata = json_encode($new_actions);
					$this->SetValue('Queue', $sdata);
					IPS_SemaphoreLeave($semaphoreID);
					$this->SendDebug(__FUNCTION__, 'mode=' . $mode . ', id=' . $id . ', status=' . $status . ', value=' . $value . ', action=' . print_r($ac, true), 0);
				} else {
					$this->SendDebug(__FUNCTION__, 'mode=' . $mode . ', id=' . $id . ': sempahore ' . $semaphoreID . ' is not accessable', 0);
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
}
