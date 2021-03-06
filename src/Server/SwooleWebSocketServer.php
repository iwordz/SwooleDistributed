<?php
/**
 * 包含http服务器
 * Created by PhpStorm.
 * User: zhangjincheng
 * Date: 16-7-29
 * Time: 上午9:42
 */

namespace Server;


use Server\CoreBase\ControllerFactory;
use Server\Coroutine\Coroutine;

abstract class SwooleWebSocketServer extends SwooleHttpServer
{
    public function __construct()
    {
        parent::__construct();

    }

    /**
     * 启动
     */
    public function start()
    {
        if (!$this->portManager->websocket_enable) {
            parent::start();
            return;
        }
        $first_config = $this->portManager->getFirstTypePort();
        //开启一个websocket服务器
        $this->server = new \swoole_websocket_server($first_config['socket_name'], $first_config['socket_port']);
        $this->server->on('Start', [$this, 'onSwooleStart']);
        $this->server->on('WorkerStart', [$this, 'onSwooleWorkerStart']);
        $this->server->on('WorkerStop', [$this, 'onSwooleWorkerStop']);
        $this->server->on('Task', [$this, 'onSwooleTask']);
        $this->server->on('Finish', [$this, 'onSwooleFinish']);
        $this->server->on('PipeMessage', [$this, 'onSwoolePipeMessage']);
        $this->server->on('WorkerError', [$this, 'onSwooleWorkerError']);
        $this->server->on('ManagerStart', [$this, 'onSwooleManagerStart']);
        $this->server->on('ManagerStop', [$this, 'onSwooleManagerStop']);
        $this->server->on('request', [$this, 'onSwooleRequest']);
        $this->server->on('open', [$this, 'onSwooleWSOpen']);
        $this->server->on('message', [$this, 'onSwooleWSMessage']);
        $this->server->on('close', [$this, 'onSwooleWSClose']);
        $this->setServerSet($first_config['probuf_set'] ?? null);
        $this->portManager->buildPort($this, $first_config['socket_port']);
        $this->beforeSwooleStart();
        $this->server->start();
    }

    /**
     * 判断这个fd是不是一个WebSocket连接，用于区分tcp和websocket
     * 握手后才识别为websocket
     * @param $fdinfo
     * @return bool
     * @throws \Exception
     * @internal param $fd
     */
    public function isWebSocket($fdinfo)
    {
        if (empty($fdinfo)) {
            throw new \Exception('fd not exist');
        }
        if (array_key_exists('websocket_status', $fdinfo) && $fdinfo['websocket_status'] == WEBSOCKET_STATUS_FRAME) {
            return $fdinfo['server_port'];
        }
        return false;
    }

    /**
     * 判断是tcp还是websocket进行发送
     * @param $fd
     * @param $data
     * @param bool $ifPack
     */
    public function send($fd, $data, $ifPack = false)
    {
        if (!$this->portManager->websocket_enable) {
            parent::send($fd, $data, $ifPack);
            return;
        }
        if (!$this->server->exist($fd)) {
            return;
        }
        $fdinfo = $this->server->connection_info($fd);
        $server_port = $fdinfo['server_port'];
        if ($ifPack) {
            $pack = $this->portManager->getPack($server_port);
            $data = $pack->pack($data);
        }
        if ($this->isWebSocket($fdinfo)) {
            $this->server->push($fd, $data, $this->portManager->getOpCode($server_port));
        } else {
            $this->server->send($fd, $data);
        }
    }

    /**
     * websocket连接上时
     * @param $server
     * @param $request
     */
    public function onSwooleWSOpen($server, $request)
    {

    }

    /**
     * websocket收到消息时
     * @param $server
     * @param $frame
     */
    public function onSwooleWSMessage($server, $frame)
    {
        $this->onSwooleWSAllMessage($server, $frame->fd, $frame->data);
    }

    /**
     * websocket合并后完整的消息
     * @param $serv
     * @param $fd
     * @param $data
     */
    public function onSwooleWSAllMessage($serv, $fd, $data)
    {
        $fdinfo = $serv->connection_info($fd);
        $server_port = $fdinfo['server_port'];
        $route = $this->portManager->getRoute($server_port);
        $pack = $this->portManager->getPack($server_port);
        //反序列化，出现异常断开连接
        try {
            $client_data = $pack->unPack($data);
        } catch (\Exception $e) {
            $serv->close($fd);
            return;
        }
        //client_data进行处理
        $client_data = $route->handleClientData($client_data);
        $controller_name = $route->getControllerName();
        $controller_instance = ControllerFactory::getInstance()->getController($controller_name);
        if ($controller_instance != null) {
            $uid = $serv->connection_info($fd)['uid']??0;
            $method_name = $this->config->get('websocket.method_prefix', '') . $route->getMethodName();
            if (!method_exists($controller_instance, $method_name)) {
                $method_name = 'defaultMethod';
            }
            $controller_instance->setClientData($uid, $fd, $client_data, $controller_name, $method_name);
            try {
                Coroutine::startCoroutine([$controller_instance, $method_name], $route->getParams());
            } catch (\Exception $e) {
                call_user_func([$controller_instance, 'onExceptionHandle'], $e);
            }
        }
    }

    /**
     * websocket断开连接
     * @param $serv
     * @param $fd
     */
    public function onSwooleWSClose($serv, $fd)
    {
        $this->onSwooleClose($serv, $fd);
    }
}