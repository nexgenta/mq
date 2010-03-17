<?php

/* Copyright 2009, 2010 Mo McRoberts
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

uses('module');

class MQModule extends Module
{
	public $latestVersion = 1;
	public $moduleId = 'com.nexgenta.mq';
	
	public static function getInstance($args = null, $className = null, $defaultDbIri = null)
	{
		return Model::getInstance($args, ($className ? $className : 'MQModule'), ($defaultDbIri ? $defaultDbIri : MQ_IRI));
	}

	public function updateSchema($targetVersion)
	{
		if($targetVersion == 1)
		{
			$t = $this->db->schema->tableWithOptions('mq_queues', DBTable::CREATE_ALWAYS);
			$t->columnWithSpec('queue_uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'The UUID of the message queue');
			$t->columnWithSpec('queue_name', DBType::VARCHAR, 64, DBCol::NOT_NULL, null, 'The reverse-DNS identifier of the queue (e.g., com.example.queue)');
			$t->columnWithSpec('creator_scheme', DBType::VARCHAR, 32, DBCol::NOT_NULL, null, 'The scheme of the creator of the queue');
			$t->columnWithSpec('creator_uuid', DBType::UUID, null, DBCol::NOT_NULL, null, 'The UUID of the creator of the queue');
			$t->columnWithSpec('creator_cluster', DBType::VARCHAR, 64, DBCol::NULLS, null, 'The name of the cluster the queue was created on');
			$t->columnWithSpec('creator_instance', DBType::VARCHAR, 255, DBCol::NULLS, null, 'The name of the instance the queue was created on');
			$t->indexWithSpec(null, DBIndex::PRIMARY, 'queue_uuid');
			$t->indexWithSpec('queue_name', DBIndex::UNIQUE, 'queue_name');
			$t->indexWithSpec('creator_scheme', DBIndex::INDEX, 'creator_scheme');
			$t->indexWithSpec('creator_uuid', DBIndex::INDEX, 'creator_uuid');
			$t->indexWithSpec('creator_cluster', DBIndex::INDEX, 'creator_cluster');
			$t->indexWithSpec('creator_instance', DBIndex::INDEX, 'creator_instance');
			return $t->apply();
		}
	}
}
