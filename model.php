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
 
uses('model', 'uuid');

class MQAlreadyExistsException extends Exception
{
	public function __construct($queueName)
	{
		parent::__construct('Message queue "' . $queueName . '" already exists');
	}
}

class MQModel extends Model
{
	protected $cluster;
	
	public static function getInstance($args = null, $className = null)
	{
		if(null === $args) $args = array();
		if(!isset($args['db'])) $args['db'] = MQ_IRI;
		if(null === $className)
		{
			$className = 'MQModel';
			if(!strncmp($args['db'], 'http:', 5) || !strncmp($args['db'], 'https:', 6))
			{
				$className = 'MQHTTPClientModel';
			}
		}
		return Model::getInstance($args, $className);
	}
	
	protected function queueDataFromName($name)
	{
		return $this->db->row('SELECT * FROM {mq_queues} WHERE "queue_name" = ?', $name);
	}

	protected function queueDataFromId($id)
	{
		return $this->db->row('SELECT * FROM {mq_queues} WHERE "queue_id" = ?', $id);
	}
	
	public function __construct($args)
	{
		parent::__construct($args);
		if(defined('CLUSTER_IRI'))
		{
			require_once(APPS_ROOT . 'cluster/model.php');
			$this->cluster = ClusterModel::getInstance();
		}
	}
	
	public function queueFromName($name)
	{
		if(!($data = $this->queueDataFromName($name)))
		{
			return null;
		}
		return MQueue::queueFromData($this, $data);
	}
	
	public function createQueue($name, $request)
	{
		$cluster = null;
		$instance = null;
		$scheme = 'null';
		$uuid = null;
		if(isset($request->session->user))
		{
			$scheme = $request->session->user['scheme'];
			$uuid = $request->session->user['uuid'];
		}
		if($this->cluster)
		{
			$cluster = $this->cluster->clusterName;
			$instance = $this->cluster->instanceName;
		}
		do
		{
			$id = null;
			$this->db->begin();
			if(($info = $this->queueDataFromName($name)))
			{
				$this->db->rollback();
				throw new MQAlreadyExistsException($name);
			}
			$this->db->insert('mq_queues', array(
				'queue_name' => $name,
				'creator_cluster' => $cluster,
				'creator_instance' => $instance,
				'creator_scheme' => $scheme,
				'creator_uuid' => $uuid,
				'@created' => $this->db->now(),
			));
			$id = $this->db->insertId();
		}
		while(!$this->db->commit());
		if($id)
		{
			$this->createQueueTable($id);
			return $this->queueDataFromId($id);
		}
		return null;
	}
	
	public function submit($queueId, $jobRequest, $request, $objectUuid = null, $scheduleDate = null, $restrictInstance = null, $restrictCluster = null)
	{
		$userScheme = null;
		$userUuid = null;
		if(isset($request->session->user))
		{
			$userScheme = $request->session->user['scheme'];
			$userUuid = $request->session->user['uuid'];
		}
		if($this->cluster)
		{
			if($restrictInstance)
			{
				$restrictCluster = $this->cluster->clusterNameOfInstance($restrictInstance);
			}
		}
		if(is_array($jobRequest) || is_object($jobRequest))
		{
			$jobRequest = json_encode($jobRequest);
		}
		$uuid = UUID::generate();
		$this->db->insert('mq_q_' . $queueId, array(
			'msg_uuid' => $uuid,
			'msg_object_uuid' => $objectUuid,
			'msg_state' => 'wait',
			'msg_request' => $jobRequest,
			'msg_process_at' => $scheduleDate,
			'msg_submitter_scheme' => $userScheme,
			'msg_submitter_uuid' => $userUuid,
			'msg_restrict_cluster' => $restrictCluster,
			'msg_restrict_instance' => $restrictInstance,
		));
		return $uuid;
	}
	
	public function nextJob($queueId, $userScheme = null, $userUuid = null, $pid = null, $timeout = 30)
	{
		if(empty($pid)) $pid = getmypid();
		if($timeout !== null)
		{
			$timeout += time();
		}
		$cluster = null;
		$instance = null;
		if($this->cluster)
		{
			$cluster = $this->cluster->clusterName;
			$instance = $this->cluster->instanceName;
		}
		$qterm = '"msg_state" = ? AND ("msg_process_at" IS NULL OR "msg_process_at" <= NOW())';
		$qargs[] = 'wait';
		if($cluster)
		{
			$qterm .= ' AND ("msg_restrict_cluster" = ? OR "msg_restrict_cluster" IS NULL)';
			$qargs[] = $cluster;
		}
		if($cluster)
		{
			$qterm .= ' AND ("msg_restrict_instance" = ? OR "msg_restrict_instance" IS NULL)';
			$qargs[] = $instance;
		}
		do
		{
			if($this->db->valueArray('SELECT "msg_id" FROM ' . $this->db->quoteTable('mq_q_' . $queueId) . ' WHERE ' . $qterm, $qargs))
			{
				$args = array_merge(array('pending-process', $userScheme, $userUuid, $cluster, $instance, $pid), $qargs);
				$this->db->vexec('UPDATE ' . $this->db->quoteTable('mq_q_' . $queueId) . ' SET "msg_state" = ?, "msg_started" = ' . $this->db->now() . ', "msg_processor_scheme" = ?, "msg_processor_uuid" = ?, "msg_processor_cluster" = ?, "msg_processor_instance" = ?, "msg_processor_pid" = ? WHERE ' . $qterm . ' LIMIT 1', $args);
				if($instance)
				{
					if(($row = $this->db->row('SELECT "msg_uuid" AS "uuid", "msg_object_uuid" AS "object", "msg_request" AS "request" FROM ' . $this->db->quoteTable('mq_q_' . $queueId) . ' WHERE "msg_state" = ? AND "msg_processor_instance" = ? AND "msg_processor_pid" = ?', 'pending-process', $instance, $pid)))
					{
						return $row;
					}
				}
				else
				{
					if(($row = $this->db->row('SELECT "msg_uuid" AS "uuid", "msg_object_uuid" AS "object", "msg_request" AS "request" FROM ' . $this->db->quoteTable('mq_q_' . $queueId) . ' WHERE "msg_state" = ? AND "msg_processor_pid" = ?', 'pending-process', $pid)))
					{
						return $row;
					}				
				}
			}
			$now = time();			
			if($timeout !== null && $now >= $timeout) break;
			sleep(1);
			$now++;
		}
		while($timeout === null || $now < $timeout);
		return null;
	}
	
	public function beginJob($queueId, $msgUuid)
	{
		$this->db->exec('UPDATE ' . $this->db->quoteTable('mq_q_' . $queueId) . ' SET "msg_state" = ? WHERE "msg_uuid" = ?', 'processing', $msgUuid);
		return true;
	}

	public function abortJob($queueId, $msgUuid)
	{
		$this->db->exec('UPDATE ' . $this->db->quoteTable('mq_q_' . $queueId) . ' SET "msg_state" = ?, "msg_completed" = ' . $this->db->now() . ' WHERE "msg_uuid" = ?', 'abort', $msgUuid);
		return true;
	}

	public function completeJob($queueId, $msgUuid)
	{
		$this->db->exec('UPDATE ' . $this->db->quoteTable('mq_q_' . $queueId) . ' SET "msg_state" = ?, "msg_completed" = ' . $this->db->now() . ' WHERE "msg_uuid" = ?', 'complete', $msgUuid);
		return true;
	}
	
	protected function createQueueTable($id)
	{
		switch($this->db->dbms)
		{
			case 'mysql':
				$this->db->exec('CREATE TABLE ' . $this->db->quoteTable('mq_q_' . $id) . ' ( ' .
					' "msg_id" BIGINT UNSIGNED NOT NULL auto_increment, ' .
					' "msg_uuid" VARCHAR(36) NOT NULL, ' .
					' "msg_object_uuid" VARCHAR(36) DEFAULT NULL, ' .
					' "msg_state" ENUM(\'wait\',\'pending-process\',\'processing\',\'abort\',\'complete\') NOT NULL default \'wait\', ' .
					' "msg_request" TEXT DEFAULT NULL, ' .
					' "msg_response" TEXT DEFAULT NULL, ' .
					' "msg_process_at" DATETIME DEFAULT NULL, ' .
					' "msg_started" DATETIME DEFAULT NULL, ' .
					' "msg_completed" DATETIME DEFAULT NULL, ' .
					' "msg_submitter_scheme" VARCHAR(16) DEFAULT NULL, ' .
					' "msg_submitter_uuid" VARCHAR(36) DEFAULT NULL, ' .
					' "msg_restrict_cluster" VARCHAR(64) DEFAULT NULL, ' . 
					' "msg_restrict_instance" VARCHAR(255) DEFAULT NULL, ' . 
					' "msg_processor_scheme" VARCHAR(16) DEFAULT NULL, ' . 
					' "msg_processor_uuid" VARCHAR(36) DEFAULT NULL, ' . 
					' "msg_processor_cluster" VARCHAR(64) DEFAULT NULL, ' . 
					' "msg_processor_instance" VARCHAR(255) DEFAULT NULL, ' . 
					' "msg_processor_pid" BIGINT UNSIGNED DEFAULT NULL, ' . 
					' PRIMARY KEY ("msg_id"), ' .
					' UNIQUE ("msg_uuid"), ' .
					' INDEX ("msg_object_uuid"), ' .
					' INDEX ("msg_state"), ' .
					' INDEX ("msg_process_at"), ' .
					' INDEX ("msg_submitter_scheme"), ' .
					' INDEX ("msg_submitter_uuid"), ' .
					' INDEX ("msg_restrict_cluster"), ' .
					' INDEX ("msg_restrict_instance"), ' .
					' INDEX ("msg_processor_scheme"), ' .
					' INDEX ("msg_processor_uuid"), ' .
					' INDEX ("msg_processor_cluster"), ' .
					' INDEX ("msg_processor_instance") ' .
					')');
				return true;
			default:
				trigger_error('Cannot create message queue for a database of kind ' . $this->db->dbms, E_USER_NOTICE);
		}
		return false;
	}	
}

class MQHTTPClientModel extends MQModel
{
	protected $endpoint;
	
	public function __construct()
	{
		$this->endpoint = $args['db'];
		if(substr($this->endpoint, -1) != '/') $this->endpoint .= '/';
	}
}

class MQueue
{
	protected $model;
	public $id;
	public $name;
	public $jobClass = 'MQJob';
	
	public static function queueFromData($model, $data)
	{
		return new MQueue($model, $data);
	}

	protected function __construct($model, $data)
	{
		$this->model = $model;
		$this->id = $data['queue_id'];
		$this->name = $data['queue_name'];
	}

	public function submit($jobRequest, $request, $objectUuid = null, $scheduleDate = null, $restrictInstance = null, $restrictCluster = null)
	{
		return $this->model->submit($this->id, $jobRequest, $request, $objectUuid, $scheduleDate, $restrictInstance, $restrictCluster);
	}
	
	public function nextJob($userScheme = null, $userUuid = null, $hostname = null, $pid = null, $timeout = 30, $jobClass = null)
	{
		if(($job = $this->model->nextJob($this->id, $userScheme, $userUuid, $hostname, $pid, $timeout)))
		{
			return call_user_func(array($this->jobClass, 'jobFromData'), $this->model, $this, $job);
		}
		return null;
	}
}

class MQJob
{
	protected $model;
	protected $queueId;
	public $queueName;
	public $uuid;
	public $objectUuid;
	public $request;
	public $response;
	
	public static function jobFromData($model, $queue, $jobData)
	{
		return new MQJob($model, $queue->id, $queue->name, $jobData);
	}
	
	protected function __construct($model, $queueId, $queueName, $jobData)
	{
		$this->model = $model;
		$this->queueId = $queueId;
		$this->queueName = $queueName;
		$this->uuid = $jobData['uuid'];
		$this->objectUuid = $jobData['object'];
		if(strlen($jobData['request']))
		{
			$this->request = json_decode($jobData['request'], true);
		}
	}
	
	public function begin()
	{
		$warned = false;
		do
		{
			if($this->model->beginJob($this->queueId, $this->uuid))
			{
				return true;
			}
			if(!$warned)
			{
				trigger_error('Failed to begin job ' . $this->uuid . '; retrying', E_USER_WARNING);
				$warned = true;
			}
			sleep(1);
		}
		while(true);
	}

	public function abort()
	{
		$warned = false;
		do
		{
			if($this->model->abortJob($this->queueId, $this->uuid))
			{
				return true;
			}
			if(!$warned)
			{
				trigger_error('Failed to abort job ' . $this->uuid . '; retrying', E_USER_WARNING);
				$warned = true;
			}
			sleep(1);
		}
		while(true);
	}

	public function complete()
	{
		$warned = false;
		do
		{
			if($this->model->completeJob($this->queueId, $this->uuid))
			{
				return true;
			}
			if(!$warned)
			{
				trigger_error('Failed to complete job ' . $this->uuid . '; retrying', E_USER_WARNING);
				$warned = true;
			}
			sleep(1);
		}
		while(true);
	}
}

class MQProcessor extends Proxy
{
	protected $supportedMethods = array('__MQ__');
	protected $supportedTypes = array('text/plain');	
}
