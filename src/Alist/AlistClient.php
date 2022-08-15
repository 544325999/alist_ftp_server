<?php

namespace FTPServer\Alist;

use Carbon\Carbon;
use FTPServer\Command\Output;
use FTPServer\Config\Server as ServerConfig;
use GuzzleHttp\Client;

class AlistClient
{

    protected $serverConfig;

    protected $baseUri;

    protected $guzzleClient;

    public function __construct()
    {
        $this->serverConfig = new ServerConfig;
        $this->baseUri = $this->serverConfig->getConfig('server.base_uri');
        $this->guzzleClient = new Client([
            'base_uri' => $this->baseUri,
            'timeout'  => 5,
            'connect_timeout' => 5
        ]);
    }

    public function getFiles($path = '/', $args)
    {
        echo 'post_path:'.$path.PHP_EOL;
        $client = new Client([
            'base_uri' => $this->baseUri,
            'timeout'  => 5,
            'connect_timeout' => 5
        ]);
        $params = [
            'json' => [
                'path' => $path,
            ]
        ];
        try {
            $result = $client->request('POST', 'api/public/path', $params);
            $response = (string)$result->getBody();
            echo $response.PHP_EOL;
            $response = json_decode($response, true);
        } catch (\Exception $e) {
            return  [];
        }

        if ($response['code'] == 200 && $response['message'] == 'success') {
            return self::formatData($response['data']['files']);
        }
        return [];
    }

    protected static function formatData($data)
    {
        // -al 格式
        $lists = [];
        foreach ($data as $v) {
            $time = explode('.', $v['updated_at']);
            $carbon = (new Carbon($time[0]))->addHours(8);
            $isDir = $v['size'] == 0 ? 'd' : '-';
            $tmpData = [
                $isDir . 'rw-r--r--',        //权限
                0,                        //文件硬链接数
                $v['driver'],                //用户
                $v['driver'],                //组
                self::getSize($v['size']),        //大小
                $carbon->format('Y-m-d'),
                $carbon->format('H:i:s'),
                $v['name'],    //文件名
            ];
            $lists[] = implode(' ', $tmpData);
        }
        return $lists;
    }


    protected static function getSize($filesize)
    {
        if ($filesize >= 1073741824) {
            //转成GB
            $filesize = round($filesize / 1073741824 * 100) / 100 . ' GB';
        } elseif ($filesize >= 1048576) {
            //转成MB
            $filesize = round($filesize / 1048576 * 100) / 100 . ' MB';
        } elseif ($filesize >= 1024) {
            //转成KB
            $filesize = round($filesize / 1024 * 100) / 100 . ' KB';
        }
        return $filesize;
    }

    public function downloadFile($path, $fileName)
    {
        $client = new Client([
            'base_uri' => $this->baseUri,
            'timeout'  => 5,
            'connect_timeout' => 5
        ]);
        try {
            $uri = 'p/'.$path.'/'.urlencode($fileName);
            $result = $client->request('get', $uri);
            $response = $result->getBody()->getContents();;
            $response = json_decode($response, true);
            if (is_null($response)) {
                return $this->baseUri.$uri;
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    public function uploadFile($file, $path)
    {
        $result = $this->guzzleClient->post('api/public/upload', [
            'multipart' => [[
                    'name' => 'path',
                    'contents' => $path
                ], [
                    'name' => 'files',
                    'contents' => fopen($file, 'r')
                ],
            ],
            'headers' => ['Authorization' => 'e022e77f9582c70a66b16bc4ee249fcf']
        ]);
        $response = (string)$result->getBody();
        $res = json_decode($response, true);
    }

}
