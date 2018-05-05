<?php
/**
 * @package   Akeeba Data Compliance
 * @copyright Copyright (c)2018 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\DataCompliance\Admin\Controller;

defined('_JEXEC') or die;

use FOF30\Container\Container;
use FOF30\Controller\DataController;

class Consenttrails extends DataController
{
	public function __construct(Container $container, array $config = array())
	{
		parent::__construct($container, $config);

		// Map all ACLs to false to prevent modifying the audit trail
		$this->taskPrivileges = [
			// Special privileges
			'*editown' => 'false',
			// Standard tasks
			'add' => 'false',
			'apply' => 'false',
			'archive' => 'false',
			'cancel' => 'false',
			'copy' => 'false',
			'edit' => 'false',
			'loadhistory' => 'false',
			'orderup' => 'false',
			'orderdown' => 'false',
			'publish' => 'false',
			'remove' => 'false',
			'forceRemove' => 'false',
			'save' => 'false',
			'savenew' => 'false',
			'saveorder' => 'false',
			'trash' => 'false',
			'unpublish' => 'false',
		];
	}

	protected function onBeforeExecute(&$task)
	{
		// Require the com_datawipe.view_trail privilege to display this view
		if (!$this->container->platform->getUser()->authorise('com_datawipe.view_trail'))
		{
			throw new \RuntimeException(\JText::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
		}
	}

}