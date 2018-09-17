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
		$r = $this->do_HttpRequest('info', []);
		echo print_r($r, true);
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
		if ($port > 0)
			$url .= ':' . $port;
		$url .= '/' . $cmd;
		$n_arg = 0;
		if ($accesstoken != '')
			$url .= ($n_arg++ ? '&' : '?') . 'at=' . $accesstoken;
		elseif ($password != '')
			$url .= ($n_arg++ ? '&' : '?') . 'auth=' . $password;
		if ($args != '') {
		foreach ($args as $arg => $value) {
			$url .= ($n_arg++ ? '&' : '?') . $arg . '=' . rawurlencode($value);
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
				$ret = $jdata['XC_SUC'];
			} else {
				if (isset($jdata['XC_ERR']))
					$err = $jdata['XC_ERR']['msg'];
				else
					$err = "unknown result ". $cdata;
				$ret = false;
			}
			}
        }

        if ($err != '') {
            echo "url=$url => err=$err";
            $this->SendDebug(__FUNCTION__, ' => err=' . $err, 0);
        }

        return $ret;
    }
}

/*
jdata=Array
	(
	    [XC_SUC] => Array
	        (
	            [name] => HomeServer
	            [mhv] => XH I-PI2
	            [msv] => 2.2.1
	            [hwv] => A1
	            [vid] => FFFF
	            [start] => 1533381561
	            [time] => 1537203977
	            [loc] => 8C141A02CC
	            [cfg] => BF
	            [server] => v5ws.mediola.com:80
	            [sid] => D0FA53280CA055ED41AF6EB0498E7A9C
	            [mem] => 30470
	            [enocean] => Array
	                (
	                    [baseID] => 
	                    [usb_connected] => 
	                    [usb_baseID] => 
	                    [num_free_senderID] => 128
	                )
	
	        )
	
	)
	

jdata=Array
	(
	    [XC_SUC] => Array
	        (
	            [name] => Zuhause
	            [mhv] => XH I-A20
	            [msv] => 1.1.1
	            [hwv] => EA
	            [vid] => FFFF
	            [start] => 1537110796
	            [time] => 1537204009
	            [loc] => 8C141A02CC
	            [cfg] => 3F
	            [server] => v5ws.mediola.com:80
	            [sid] => A09F8D4DEB63BE5C014E74007361C104
	            [neoserver] => true
	            [mem] => 781258
	            [enocean] => Array
	                (
	                    [baseID] => 
	                    [usb_connected] => 
	                    [usb_baseID] => 
	                    [num_free_senderID] => 128
	                )
	
	            [SAFE_MODE] => 
	            [ip] => 192.168.178.36
	            [sn] => 255.255.255.0
	            [gw] => 192.168.178.1
	            [dhcp] => 1
	            [dns] => 192.168.178.1
	            [mac] => 40-66-7A-00-51-53
	            [ntp] => 0.pool.ntp.org
	            [primary_net] => eth0
	        )
	
	)
	
*/
