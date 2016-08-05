<?php
/**
 * Created by lixuan868686@163.com
 * User: lane
 * Date: 16/7/9
 * Time: 下午6:19
 * E-mail: lixuan868686@163.com
 * WebSite: http://www.lanecn.com
 */
namespace MeepoPS\Core\Trident;

use MeepoPS\Api\Trident;
use MeepoPS\Core\Log;
use MeepoPS\Core\Timer;
use MeepoPS\Library\TcpClient;

class BusinessAndTransferService{

    public static $transferList;
    private $_connectingTransferList = array();

    /**
     * 批量更新Transfer的链接
     * @param $data
     */
    public function resetTransferList($data){
        if(empty($data['msg_content']['transfer_list'])){
            Log::write('Business: Transfer sent by the Confluence is empty ', 'INFO');
        }
        $transferList = self::$transferList;
        self::$transferList = array();
        foreach($data['msg_content']['transfer_list'] as $transfer){
            if(empty($transfer['ip']) || empty($transfer['port'])){
                continue;
            }
            //之前是否已经链接好了
            $transferKey = Tool::encodeTransferAddress($transfer['ip'], $transfer['port']);
            if(isset($transferList[$transferKey])){
                self::$transferList[$transferKey] = $transferList[$transferKey];
                continue;
            }
            //新链接到Transfer
            $this->_connectTransfer($transfer['ip'], $transfer['port']);
        }
    }

    /**
     * 作为客户端, 链接到Transfer
     *
     */
    private function _connectTransfer($ip, $port){
        $transfer = new TcpClient(Trident::INNER_PROTOCOL, $ip, $port, false);
        //实例化一个空类
        $transfer->instance = new \stdClass();
        $transfer->instance->callbackNewData = array($this, 'callbackTransferNewData');
        $transfer->instance->callbackConnectClose = array($this, 'callbackTransferConnectClose');
        $transfer->transfer = array();
        $transfer->connect();
        $result = $transfer->send(array('token'=>'', 'msg_type'=>MsgTypeConst::MSG_TYPE_ADD_BUSINESS_TO_TRANSFER));
        if($result === false){
            Log::write('Business: Link transfer failed.' . $ip . ':' . $port , 'WARNING');
            $this->_close($transfer);
            return false;
        }
        $transferKey = Tool::encodeTransferAddress($ip, $port);
        $this->_connectingTransferList[$transferKey] = array('ip' => $ip, 'port' => $port);
        return $result;
    }

    public function callbackTransferConnectClose($connect){
        Timer::add(array($this, 'reConnectTransfer'), array($connect->host, $connect->port), 1, false);
    }

    /**
     * 回调函数 - 收到Transfer发来新消息时
     * 只接受新增Business、PING两种消息
     * @param $connect
     * @param $data
     */
    public function callbackTransferNewData($connect, $data){
        switch($data['msg_type']){
            case MsgTypeConst::MSG_TYPE_ADD_BUSINESS_TO_TRANSFER:
                $this->_addTransferResponse($connect, $data);
                break;
            case MsgTypeConst::MSG_TYPE_PING:
                $this->_receiveTransferPing($connect, $data);
                break;
            case MsgTypeConst::MSG_TYPE_APP_MSG:
                $this->_appMessage($connect, $data);
                break;
            default:
                Log::write('Business: Transfer message type is not supported, meg_type=' . $data['msg_type'], 'ERROR');
                return;
        }
    }

    private function _addTransferResponse($connect, $data){
        //链接失败
        if($data['msg_content'] !== 'OK' || empty($data['msg_attachment']['ip']) || empty($data['msg_attachment']['port'])){
            $this->_close($connect);
            return;
        }
        $transferKey = Tool::encodeTransferAddress($data['msg_attachment']['ip'], $data['msg_attachment']['port']);
        if(!isset($this->_connectingTransferList[$transferKey])){
            Log::write('Business: Rejected an unknown Transfer that would like to join.', 'WARNING');
            $this->_close($connect);
            return;
        }
        //链接成功
        $connect->business['transfer_no_ping_limit'] = 0;
        //添加计时器, 如果一定时间内没有收到Transfer发来的PING, 则断开本次链接并重新链接到Transfer
        $connect->business['waiter_transfer_ping_timer_id'] = Timer::add(function()use($connect){
            $connect->business['transfer_no_ping_limit']++;
            if( $connect->business['transfer_no_ping_limit'] >= MEEPO_PS_THREE_LAYER_MOULD_SYS_PING_NO_RESPONSE_LIMIT){
                //断开连接
                $this->_close($connect);
            }
        }, array(), MEEPO_PS_THREE_LAYER_MOULD_SYS_PING_INTERVAL);
        self::$transferList[$transferKey] = $connect;
        Log::write('Business: link Transfer success. ' . $connect->host . ':' . $connect->port);
    }

    private function _receiveTransferPing($connect, $data){
        if($data['msg_content'] !== 'PING'){
            return;
        }
        if($connect->business['transfer_no_ping_limit'] >= 1){
            $connect->business['transfer_no_ping_limit']--;
        }
        $connect->send(array('msg_type'=>MsgTypeConst::MSG_TYPE_PONG, 'msg_content'=>'PONG'));
    }

    public function reConnectTransfer($connect){
        $this->_close($connect);
        $this->_connectTransfer($connect->host, $connect->port);
    }

    private function _close($connect){
        if(isset($connect->business['waiter_transfer_ping_timer_id'])){
            Timer::delOne($connect->business['waiter_transfer_ping_timer_id']);
        }
        $connect->close();
    }

    /**
     * 业务逻辑
     * @param $connect
     * @param $data
     */
    private function _appMessage($connect, $data){
        if(empty(Trident::$callbackList['callbackNewData']) || !is_callable(Trident::$callbackList['callbackNewData'])){
            return;
        }
        $_SERVER['MEEPO_PS_MSG_TYPE'] = $data['msg_type'];
        $_SERVER['MEEPO_PS_TRANSFER_IP'] = $data['transfer_ip'];
        $_SERVER['MEEPO_PS_TRANSFER_PORT'] = $data['transfer_port'];
        $_SERVER['MEEPO_PS_CLIENT_IP'] = $data['client_ip'];
        $_SERVER['MEEPO_PS_CLIENT_PORT'] = $data['client_port'];
        $_SERVER['MEEPO_PS_CLIENT_CONNECT_ID'] = $data['client_connect_id'];
        $_SERVER['MEEPO_PS_CLIENT_UNIQUE_ID'] = $data['client_unique_id'];
        call_user_func_array(Trident::$callbackList['callbackNewData'], array($connect, $data['msg_content']));
    }

    /**
     * 发送给Business的消息格式
     * @param $data mixed
     * @return array
     */
    public static function formatMessageToTransfer($data){
        return array(
            'msg_type' => $_SERVER['MEEPO_PS_MSG_TYPE'],
            'msg_content' => $data,
            'transfer_ip' => $_SERVER['MEEPO_PS_TRANSFER_IP'],
            'transfer_port' => $_SERVER['MEEPO_PS_TRANSFER_PORT'],
            'client_ip' => $_SERVER['MEEPO_PS_CLIENT_IP'],
            'client_port' => $_SERVER['MEEPO_PS_CLIENT_PORT'],
            'client_connect_id' => $_SERVER['MEEPO_PS_CLIENT_CONNECT_ID'],
            'client_unique_id' => $_SERVER['MEEPO_PS_CLIENT_UNIQUE_ID'],
        );
    }
}