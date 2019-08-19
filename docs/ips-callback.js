/*
 * Script für Mediola Gateway/ NEO Server zu Modul https://github.com/demel42/IPSymconMediolaServer
 *
 */

// Hostname/IP des IPS-Servers
var ips_hostname = "";

// Portnummer
var ips_port     = "3777";

// Bezeichnung des WebHook des Moduls 'MediolaServer'
var ips_webhook  = "/hook/MediolaServer";

/****************************************************************************/

var ips_url = "http://" + ips_hostname + ":" + ips_port + ips_webhook;

var http = require('http');

const sendReply = function(jdata, mode, status, err, value) {
    var id = jdata.id;
    var url = ips_url + "?id=" + id + "&mode=" + mode;
    if (status !== '')
        url += "&status=" + status;
    if (err !== '')
        url += "&err=" + decodeURI(err);
    if (value !== '')
        url += "&value=" + decodeURI(value);
    console.log("reply: id=" + id + ", status=" + status + ", err=" + err + ", value=" + value + ", url=", url);
    var req = http.get(url);
}
    
const run_executeCommand = function(jdata) {
    var mode = jdata.mode;
    var room = jdata.room ? jdata.room : '';
    var device = jdata.device ? jdata.device : '';
    var action = jdata.action ? jdata.action : '';
    var value = jdata.value !== undefined ? jdata.value : '';
    console.log("mode=" + mode + ": room=" + room + ", device=" + device + ", action=" + action + ", value=" + value);
    if (room === '' || device === '' || action === '') {
        err = "malformed request: " + "room='" + room + "', device='" + device + "', action='" + action + "'";
        sendReply(jdata, "status", "fail", err, '');
        return;
    }
    
    var cmd = {"value":action};
    if (jdata.value !== undefined) {
        cmd.ext = value;
    }
    executeDeviceCommand(
        room,
        device,
        cmd,
        function(err) {
            if (err) {
                console.error(err.message);
                sendReply(jdata, "status", "fail", err.message, '');
            } else {
                sendReply(jdata, "status", "ok", '', '');
            }
        }
    );
};

const run_executeMacro = function(jdata) {
    var mode = jdata.mode;
    var group = jdata.group ? jdata.group : '';
    var macro = jdata.macro ? jdata.macro : '';
    console.log("mode=" + mode + ": group=" + group + ", macro=" + macro);
    if (group === '' || macro === '') {
        err = "malformed request: " + "group='" + group + "', macro='" + macro + "'";
        sendReply(jdata, "status", "fail", err, '');
        return;
    }
    
    executeMacro(
        group,
        macro,
        function(err) {
            if (err) {
                console.error(err.message);
                sendReply(jdata, "status", "fail", err.message, '');
            } else {
                sendReply(jdata, "status", "ok", '', '');
            }
        }
    );
};

const run_getStatus = function(jdata) {
    var mode = jdata.mode;
    var room = jdata.room ? jdata.room : '';
    var device = jdata.device ? jdata.device : '';
    var variable = jdata.variable ? jdata.variable : '';
    console.log("mode=" + mode + ": room=" + room + ", device=" + device + ", variable=" + variable);
    if (room === '' || device === '' || variable === '') {
        err = "malformed request: " + "room='" + room + "', device='" + device + "', variable='" + variable + "'";
        sendReply(jdata, "status", "fail", err, '');
        return;
    }
    
    var cmd = {"value":variable};

    getDeviceStatus(
        room,
        device,
        cmd,
        function(err, value) {
            if (err) {
                console.error(err.message);
                sendReply(jdata, "status", "fail", err.message, '');
            } else {
                sendReply(jdata, "value", "ok", '', value);
            }
        }
    );
};

var url = ips_url + "?mode=query";
console.log("url=" + url);
var req = http.get(url, function(res) {
    var data = '';
    res.setEncoding('utf8');
    res.on('data', function(chunk) {
        data += String(chunk);
    });
    res.on('end', function() {
        console.log("data=", data);
        var jdata = JSON.parse(data);
        var mode = jdata.mode;
        switch (mode) {
            case 'executeCommand':
                run_executeCommand(jdata);
                break;
            case 'executeMacro':
                run_executeMacro(jdata);
                break;
            case 'getStatus':
                run_getStatus(jdata);
                break;
            default:
                err = "unknown mode '" + mode + "'";
                sendReply(jdata, "status", "fail", err, '');
                break;
        }

    });
});
