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

        $this->MaintainVariable('Data', $this->Translate('Data for CallBack'), vtString, '', $vpos++, true);

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
        $this->SendDebug('WebHook SERVER', print_r($_SERVER, true), 0);

        $root = realpath(__DIR__);
        $uri = $_SERVER['REQUEST_URI'];
        if (substr($uri, -1) == '/') {
            http_response_code(404);
            die('File not found!');
        }
        if ($uri == '/hook/MediolaServer') {
            $data = file_get_contents('php://input');
            $jdata = json_decode($data, true);
            if ($jdata == '') {
                echo 'malformed data: ' . $data;
                $this->SendDebug(__FUNCTION__, 'malformed data: ' . $data, 0);
                return;
            }
            // DOIT
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
				if ($value != '')
					$url .= '=' . rawurlencode($value);
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
			$n = strpos($args, "=");
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

		$value = $bval ? "on" : "off";
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
			$value = "0" . $value;
		}
		$r = $this->setVal($id, 'INT', $value);
		$s = 'set var ' . $id . ' to value ' . $value . ' on ' . $hostname . ' => ' . ($r ? 'succeed' : 'failed');
		$this->SendDebug(__FUNCTION__, $s, 0);
		IPS_LogMessage(__CLASS__ . '::' . __FUNCTION__, $s);
		return $r;
	}

	function SetValueFloat(string $id, float $fval)
	{
        $hostname = $this->ReadPropertyString('hostname');

		$value = strtoupper(dechex(floor($fval * 100)));
		while (strlen($value) < 4) {
			$value = "0" . $value;
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
				if (!(isset($device['adr']) && isset($device['type'])))
					continue;
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
		$value = $r == "ON" ? true : false;
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
}
