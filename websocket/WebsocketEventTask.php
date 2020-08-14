<?php
/**
 * This file is part of zba.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    jinhanjiang<jinhanjiang@foxmail.com>
 * @copyright jinhanjiang<jinhanjiang@foxmail.com>
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Task;

use Zba\Process;
use Zba\Task;

class WebsocketEventTask extends Task
{
	private $ws;
	
	public function __construct() {
		$this->count = 1;
		$this->name = 'WebsocketEventTask';
		if(extension_loaded('event')) {
			$this->closure = $this->run();
        }
		parent::__construct();
	}

	/**
	 * $this->envConfig come from .env , You can changed it 
	 *
	 * [WebsocketEventTask]
	 * host=0.0.0.0:1223
	 * maxRead=4096
	 */
	public function onWorkerStart() 
	{
		return function(Process $worker) 
		{
			if(1 == $worker->id) {
				$config = isset($this->envConfig) && is_array($this->envConfig) ? $this->envConfig : array();
				$this->ws = new WebsocketServer($config);
			}
		};
	}

	public function onWorkerStop() {
		return function(Process $worker) {
			$this->ws->closeAll();
		};
	}

	public function run()
	{
		return function(Process $worker) {
			$this->ws->start();	
		};
	}
}