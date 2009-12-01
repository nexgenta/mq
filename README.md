Eregansu Message Queues
=======================

This is a simple, lightweight, message-queueing system built upon SQL
databases. At present, only MySQL is supported but others will be added
in due course.

To use EMQ, clone a copy of the repository into your “app” directory:

	$ cd app
	$ git clone git://github.com/nexgenta/mq.git
	
(If your project is version-controlled with git, you might wish to add it
as a submodule instead).

Next, create a database using your preferred MySQL administrative interface.

Import the content of mq.mysql into your new database:

	$ mysql -uyouruser -p -hdbhost databasename < mq/mq.mysql
	Password: <enter your password here>

Add the IRI to the database to your host’s config/config.php:

	define('MQ_IRI', 'mysql://webuser:webpass@dbhost/databasename');

Add the command-line interface to $CLI_ROUTES:

	'mq' => array('name' => 'mq', 'file' => 'cli.php', 'class' => 'MQCLI')

