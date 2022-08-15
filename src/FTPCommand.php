<?php
namespace FTPServer;

use Exception;
use FTPServer\Alist\AlistClient;
use FTPServer\Util\Os;
use FTPServer\Command\Input;
use FTPServer\Command\Output;
use FTPServer\Config\Server as ServerConfig;
use FTPServer\Config\User as UserConfig;
use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;
use DirectoryIterator;

class FTPCommand
{
	/**
	 * @var ServerConfig $serverConfig server config
	 */
	private $serverConfig;

	/**
	 * FTPCommand constructor.
	 */
	public function __construct()
	{
		$this->serverConfig = new ServerConfig;
	}

	/**
	 * run command
	 *
	 * @param ConnectionInterface $connection
	 * @param Input               $input
	 *
	 * @return Output Output
	 */
	public function command(ConnectionInterface $connection, Input $input)
	{
		$method = '_' . strtolower($input->getCommand());
		if(method_exists($this, $method)) {
			$param = $input->getParameter();
			if(Os::isWindows()) {
				$param = mb_convert_encoding($param, 'utf-8', 'gb2312');
			}
			return $this->$method($connection, $param);
		}

		return new Output(502, 'command not support!');
	}

	/**
	 * get directory list
	 *
	 * @param string $path directory path
	 *
	 * @return array directory list
	 */
	private function getDirectoryLists($path)
	{
		$lists = [];
		if(!is_dir($path))
		{
			return $lists;
		}
		foreach(new DirectoryIterator($path) as $iterator)
		{
			if(!$iterator->isDot())
			{
				if(Os::isWindows())
				{
					$ownerName = get_current_user();
					$groupName = $ownerName;
				}
				else
				{
					$owner = posix_getpwuid($iterator->getOwner());
					$group = posix_getgrgid($iterator->getGroup());

					$ownerName = $owner['name'];
					$groupName = $group['name'];
				}

				$isDir = $iterator->isDir() ? 'd' : '-';
				//$octal_perms = substr(sprintf('%o', $iterator->getPerms()), -4);//权限
				$lists[] = [
					'permission' => $isDir . 'rw-r--r--',        //权限
					'hard_link'  => 0,                        //文件硬链接数
					'owner'      => $ownerName,                //用户
					'group'      => $groupName,                //组
					'size'       => $iterator->getSize(),        //大小
					'time'       => $iterator->getMTime(),    //修改时间
					'name'       => $iterator->getFilename(),    //文件名
				];
			}
		}

		return $lists;
	}

	/**
	 * get formatted directory list
	 *
	 * @param string $path   directory path
	 * @param string $format directory format
	 *
	 * @return array directory list
	 */
	private function _dirFormattedList($path, $format = '')
	{
		$lists = [];
		foreach($this->getDirectoryLists($path) as $iterator)
		{
			list($permission, $hard_link, $owner, $group, $size, $time, $name) = array_values($iterator);

			//http://cr.yp.to/ftp/list/binls.html
			switch($format)
			{
				case '-l':
					//remove dot file
					if(strncmp($name, '.', 1) === 0)
					{
						break 2;
					}

					$year = date('Y', $time);
					//< 6 month, show 'hms'
					if(time() - $time <= 6 * 30 * 86400)
					{
						$year = date('H:i', $time);
					}
					$ls = [
						$permission,
						$hard_link,
						$owner,
						$group,
						$size,
						date('d', $time),
						date('M', $time),
						$year,
						$name,
					];
					break;
				case '-al':
					$year = date('Y', $time);
					//< 6 month, show 'hms'
					if(time() - $time <= 6 * 30 * 86400)
					{
						$year = date('H:i', $time);
					}
					$ls = [
						$permission,
						$hard_link,
						$owner,
						$group,
						$size,
						date('d', $time),
						date('M', $time),
						$year,
						$name,
					];
					break;
				case 'nlst':
					$ls = [
						$name,
					];
					break;
				default:
					$ls = [
						$permission,
						$hard_link,
						$owner,
						$group,
						$size,
						date('Y', $time),
						date('M', $time),
						date('d', $time),
						$name,
					];
					break;
			}
			$lists[] = implode(' ', $ls);
		}

		return $lists;
	}

	//command methods

	/**
	 * username
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _user($connection, $args)
	{
		if($args === 'anonymous' || $args === '') {
			if($this->serverConfig->getConfig('server.allow_anonymous', false)) {
				$root = $this->serverConfig->getConfig('server.root_path', '/');
//echo 'setRoot:'. $root.PHP_EOL;
				$connection->user = new User([]);
//				$connection->user->setRoot($root);
//				$connection->user->setRoot($connection->user->getRootPath());
				$connection->user->setPath('/');

				if(!file_exists($connection->user->getRootPath())) {
					mkdir($connection->user->getRootPath(), 644);
				}

				return new Output(230, 'anonymous successfully logged in');
			}

			return new Output(530, 'server not allow anonymous');
		}

		$userConfig = new UserConfig;
		$info       = $userConfig->getInfoByUserName($args);
		if($info === null || !$info->status) {
			return new Output(530, 'login error');
		}

		$connection->user = $info;

		return new Output(331, 'password required for' . $args);
	}

	/**
	 * password
	 * 登录
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _pass($connection, $args)
	{
		$user = $connection->user;
		if($user === null || $user->password !== $args)
		{
			return new Output(530, 'not logged in, user or password incorrect');
		}

		$root = $this->serverConfig->getConfig('server.root_path', '/');

		$connection->user->setRoot($root);
		$connection->user->setPath('/');

        echo 'login_path'.$connection->user->getPath().PHP_EOL;
        echo 'login_root_path'.$connection->user->getRootPath().PHP_EOL;
//        $lists = (new AlistClient())->getFiles('/', $args);

//		if(!file_exists($connection->user->getRootPath()))
//		{
//			mkdir($connection->user->getRootPath(), 644);
//		}
//		$connection->user->isBinary = false;

		return new Output(202, 'user successfully logged in');
	}

	/**
	 * directory details list
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _list($connection, $args)
	{
		$connection->send(new Output(125, 'Opening data connection for directory list.'));

        if ($connection->user->getPath()) {
            $path = $connection->user->getPath();
        } else {
            $path = '/';
        }
        $lists = (new AlistClient())->getFiles($path, $args);
		$msg   = implode(PHP_EOL, $lists) . PHP_EOL;

		if($connection->user->mode === 'port') {
			$connection->user->port_connection->send($msg);
			$connection->user->port_connection->close();
			$connection->user->port_connection = null;
		} else {
			if(($pasv_connection = current($connection->user->pasv_worker->connections)) !== false) {
				$pasv_connection->send($msg);
				$pasv_connection->close();
			}
			$connection->user->pasv_worker->unlisten();
			$connection->user->pasv_worker = null;
		}

		return new Output(226, 'Transfer complete');
	}

	/**
	 * directory list
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _nlst($connection, $args)
	{
		return $this->_list($connection, empty($args) ? 'nlst' : $args);
	}

	/**
	 * port mode
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _port($connection, $args)
	{
		$ip_port = explode(',', $args);
		$ip      = implode('.', array_slice($ip_port, 0, 4));
		$port    = $ip_port[4] << 8 | $ip_port[5];

		$connection->user->mode = 'port';

		if(($socket = @stream_socket_client('tcp://' . $ip . ':' . $port)) !== false)
		{
			$tcpConnection = new TcpConnection($socket);
			$tcpConnection->connect();

			$connection->user->port_connection = $tcpConnection;

			return new Output(200, 'PORT command successful.');
		}

		return new Output(502, 'unable to connect client!');
	}

	/**
	 * pasv mode
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 * @throws Exception
	 */
	private function _pasv($connection, $args)
	{
		$ip              = $this->serverConfig->getConfig('server.ip', 'localhost');
		$pasv_port_range = $this->serverConfig->getConfig('server.pasv_port_range', '50000');

		$_port_range = explode('-', $pasv_port_range);
		$random_port = random_int($_port_range[0], count($_port_range) >= 2 ? $_port_range[1] : $_port_range[0]);

		$port1 = floor($random_port / 256);
		$port2 = $random_port % 256;

		$msg = implode(',', [str_replace('.', ',', $ip), $port1, $port2]);

		$connection->user->mode = 'pasv';

		$inner_worker        = new Worker('tcp://' . $ip . ':' . $random_port);
		$inner_worker->name  = 'pasv Worker';
		$inner_worker->count = 1;
		$inner_worker->listen();
		$connection->user->pasv_worker = $inner_worker;

		return new Output(227, $msg);
	}

	/**
	 * change work dir
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _cwd($connection, $args)
	{
		if($args === '.')
		{
			return new Output(250, 'CWD command successful.');
		}
        echo '--- cwd_args :'. $args.PHP_EOL;

        $res = (new AlistClient())->getFiles($args, '');
		if (empty($res)) {
            return new Output(550, $args . ':No such file or directory222.');
        }
        $connection->user->setPath($args);
        echo '--- cwd_path :'. $connection->user->getPath().PHP_EOL;

        $connection->user->setRoot($args);
        echo '--- cwd_root_path :'. $connection->user->getRootPath().PHP_EOL;

        return new Output(250, 'CWD command successful.');
	}

	/**
	 * print work dir
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _pwd($connection, $args)
	{
		return new Output(257, '"' . DIRECTORY_SEPARATOR . $connection->user->getPath() . '" is current directory.');
	}

	/**
	 * windows ftp's print work dir
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _xpwd($connection, $args)
	{
		return $this->_pwd($connection, $args);
	}

	/**
	 * change to upper dir
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _cdup($connection, $args)
	{
		$connection->user->setPath(dirname($connection->user->getPath()));

		return new Output(250, 'CWD command successful.');
	}

	/**
	 * download
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _retr($connection, $args)
	{
		$user = $connection->user;

		$path = $user->getRootPath($args);
        $res = (new AlistClient())->downloadFile($user->getPath(), $args);


        if(!$res) {
			return new Output(550, $args . ':No such file or directory333.');
		}

		$connection->send(new Output(115, 'Opening data connection'));

		$fmode = $user->isBinary ? 'rb' : 'r';

		$handle = fopen($res, $fmode);

//		$offset = isset($user->download_size) ? $user->download_size : 0;
		fseek($handle);

		if($user->mode === 'port') {
			$port_connection                    = $user->port_connection;
			$port_connection->maxSendBufferSize = filesize($path);

			$buffer = 1024 * 1024;
			while(!feof($handle))
			{
				$msg = fread($handle, $buffer);
				$port_connection->send($msg);
			}
			$port_connection->close();
			$connection->user->port_connection = null;
		} else {
			$pasv_connection                    = current($user->pasv_worker->connections);
			$pasv_connection->maxSendBufferSize = filesize($path);

			$buffer = 1024 * 1024;
			while(!feof($handle))
			{
				$msg = fread($handle, $buffer);
				$pasv_connection->send($msg);
			}
			$pasv_connection->close();
			$user->pasv_worker->unlisten();
			$connection->user->pasv_worker = null;
		}
		fclose($handle);

		return new Output(226, 'Transfer complete');
	}

	/**
	 * server info
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _syst($connection, $args)
	{
		return new Output(215, PHP_OS);
	}

	/**
	 * server status
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _stat($connection, $args)
	{
		$status = 'FTP server status:' . PHP_EOL . 'Version 1.0.0';

		return new Output(211, $status);
	}

	/**
	 * quit ftp
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _quit($connection, $args)
	{
		return new Output(221, 'Bye.');
	}

	/**
	 * make dir
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _mkd($connection, $args)
	{
		$path = $connection->user->getRootPath($args);
		mkdir($path);

		return new Output(257, '"' . $path . '" create successful');
	}

	/**
	 * delete file
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _dele($connection, $args)
	{
		unlink($connection->user->getRootPath($args));

		return new Output(250, 'delete file successful');
	}

	/**
	 * remove dir
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _rmd($connection, $args)
	{
		rmdir($connection->user->getRootPath($args));

		return new Output(250, 'delete folder successful');
	}

	/**
	 * begin renaming a file
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _rnfr($connection, $args)
	{
		$connection->user->need_rename = $connection->user->getRootPath($args);

		return new Output(350, 'ok');
	}

	/**
	 * finish renaming a file
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _rnto($connection, $args)
	{
		rename($connection->user->need_rename, $connection->user->getRootPath($args));
		$connection->user->need_rename = null;

		return new Output(250, 'ok');
	}

	/**
	 * upload
	 * 上传
	 * @param      $connection
	 * @param      $args
	 * @param bool $upload_append
	 *
	 * @return Output
	 */
	private function _stor($connection, $args, $upload_append = false)
	{
		$user = $connection->user;

		$connection->send(new Output(115, 'Opening data connection'));

		$filePath = $user->getRootPath($args);

		if(!$upload_append && is_file($filePath)) {
			unlink($filePath);
		}

		if($user->mode === 'port') {
			$port_connection = $user->port_connection;

			$port_connection->onClose   = static function($port_connection) use ($connection) {
				$port_connection->close();
				$connection->user->port_connection = null;
			};
			$port_connection->onMessage = static function($port_connection, $data) use ($filePath) {
				file_put_contents($filePath, $data, FILE_APPEND);
			};
		} else {
			$pasv_connection = current($user->pasv_worker->connections);

			$pasv_connection->onClose   = static function($pasv_connection) use ($connection) {
				$pasv_connection->close();
				$connection->user->pasv_worker->unlisten();
				$connection->user->pasv_worker = null;
			};
			$pasv_connection->onMessage = static function($pasv_connection, $data) use ($filePath, $args) {
                file_put_contents($args, $data, FILE_APPEND);
            };

        }
        (new AlistClient())->uploadFile($args, 'GoogleAlist/alist');

		return new Output(226, 'Transfer complete');
	}

	/**
	 * broken point append upload
	 *
	 * @param $connection
	 * @param $args
	 */
	private function _appe($connection, $args)
	{
		$this->_stor($connection, $args, true);
	}

	/**
	 * transfer type
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _type($connection, $args)
	{
		if($args === 'I')
		{
			$connection->user->isBinary = true;

			return new Output(200, 'Type set to I(Binary)');
		}

		$connection->user->isBinary = false;

		return new Output(200, 'Type set to A(ASCII)');
	}

	/**
	 * file size
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _size($connection, $args)
	{
		$path = $connection->user->getRootPath() . $args;
		$size = file_exists($path) ? @filesize($path) : 0;

		return new Output(213, $size);
	}

	/**
	 * file modification time
	 */
	private function _mdtm($connection, $args)
	{
		$time = @filemtime($connection->user->getRootPath() . $args);

		return new Output(213, $time);
	}

	/**
	 * transfer start position
	 *
	 * @param $connection
	 * @param $args
	 *
	 * @return Output
	 */
	private function _rest($connection, $args)
	{
		if($args !== 0 && $args !== 100)
		{
			$connection->user->download_size = $args;
		}

		return new Output(213, 'ok');
	}
}
