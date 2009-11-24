<?php

/* Eregansu Message Queues
 *
 * Copyright 2009 Mo McRoberts.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. The names of the author(s) of this software may not be used to endorse
 *    or promote products derived from this software without specific prior
 *    written permission.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES, 
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY 
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.  IN NO EVENT SHALL
 * AUTHORS OF THIS SOFTWARE BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED
 * TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR
 * PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF 
 * LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING 
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS 
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

uses('error');

require_once(dirname(__FILE__) . '/model.php');

class MQRequest extends Request
{
	public $job;
	public $queueName;
	
	public function __construct($queueName)
	{
		parent::__construct();
		$this->sapi = 'mq';
		$this->method = '__MQ__';
		$this->queueName = $queueName;
		$this->params = array($queueName);
		$this->hostname = php_uname('n');
		$this->types = array('text/plain');
	}

	protected function determineTypes($acceptHeader = null)
	{
	}
	
	public function redirect()
	{
		exit();
	}
	
}

class MQCLI extends App
{
	public function __construct()
	{
		parent::__construct();

		$this->sapi['cli']['despatch'] = array('class' => 'MQDespatch');
	}
}

class MQDespatch extends CommandLine
{
	protected $modelClass = 'MQModel';
	
	protected function getObject()
	{
		if(!isset($this->request->params[0]))
		{
			return $this->error(Error::NO_OBJECT, null, null, "Usage: despatch QUEUE\n");
		}
		if(!($this->object = $this->model->queueFromName($this->request->params[0])))
		{
			return $this->error(Error::OBJECT_NOT_FOUND, null, $this->request->params[0]);
		}
		return true;
	}
	
	protected function perform___CLI__()
	{
		global $INITIAL_APP;
		
		$router = $INITIAL_APP;
		$success = false;
		Error::$throw = true;
		$req = new MQRequest($this->object->name);
		if(!($route = $router->locateRoute($req)))
		{
			return $router->unmatched($req);
		}
		if(!($target = $router->routeInstance($req, $route))) return false;
		if(($job = $this->object->nextJob()))
		{
			echo "Starting job " . $job->uuid . "\n";
			$job->begin();
			$req->job = $job;
			try
			{
				if($target->process($req))
				{
					$success = true;
				}
			}
			catch(Exception $e)
			{
				if(!($e instanceof TerminalErrorException))
				{
					echo str_repeat('=', 72) . "\n";
					echo $e . "\n";
					echo str_repeat('=', 72) . "\n";
				}
			}
			$req->job = null;
			if($success)
			{
				$job->complete();
			}
			else
			{
				$job->abort();
			}
		}
		if(!$success)
		{
			exit(1);
		}
	}
}