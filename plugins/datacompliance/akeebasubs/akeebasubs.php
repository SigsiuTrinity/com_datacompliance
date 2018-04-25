<?php
/**
 * @package   Akeeba Data Compliance
 * @copyright Copyright (c)2018 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

use Akeeba\DataCompliance\Admin\Helper\Export;
use Akeeba\Subscriptions\Admin\Model\Subscriptions;
use FOF30\Container\Container;

defined('_JEXEC') or die;

/**
 * Data Compliance plugin for Akeeba Release System User Data
 */
class plgDatacomplianceAkeebasubs extends Joomla\CMS\Plugin\CMSPlugin
{
	protected $container;

	/**
	 * Constructor. Intializes the object:
	 * - Load the plugin's language strings
	 * - Get the com_datacompliance container
	 *
	 * @param   object  $subject  Passed by Joomla
	 * @param   array   $config   Passed by Joomla
	 */
	public function __construct($subject, array $config = array())
	{
		parent::__construct($subject, $config);

		$this->loadLanguage('plg_datacompliance_' . $this->_name);
		$this->container = \FOF30\Container\Container::getInstance('com_datacompliance');
	}

	/**
	 * Checks whether a user is safe to be deleted. This plugin prevents deletion on the following conditions:
	 * - The user has an active subscription created within the last X days (which means it's likely not yet reported for tax purposes)
	 *
	 * @param   int  $userID  The user ID we are asked for permission to delete
	 *
	 * @return  void  No return value is expected. Throw exceptions when there is a problem.
	 *
	 * @throws  RuntimeException  The error which prevents us from deleting a user
	 */
	public function onDataComplianceCanDelete($userID)
	{
		// TODO Configurable. I am hardcoding it to PayPal's maximum dispute resolution period.
		$period = 90;

		if ($period < 1)
		{
			return;
		}

		$container = Container::getInstance('com_akeebasubs', [], 'admin');

		$now = $container->platform->getDate();
		$interval = new DateInterval('P' . (int)$period . 'D');
		$since = $now->sub($interval);

		/** @var Subscriptions $subs */
		$subs = $container->factory->model('Subscriptions')->tmpInstance();
		$numLatestSubs = $subs->user_id($userID)->paystate(['C'])->since($since->toSql())->get()->count();
		
		if ($numLatestSubs > 0)
		{
			throw new RuntimeException(JText::sprintf('PLG_DATACOMPLIANCE_AKEEBASUBS_ERR_HASSUBS', $numLatestSubs, $period));
		}
	}

	/**
	 * Performs the necessary actions for deleting a user. Returns an array of the information categories and any
	 * applicable IDs which were deleted in the process. This information is stored in the audit log. DO NOT include
	 * any personally identifiable information.
	 *
	 * This plugin takes the following actions:
	 * - Remove all subscriptions with paystate "N" (failed transactions).
	 * - Modify subscriptions with a paystate "C" or "X" with payment processor "DATA_COMPLIANCE_WIPED" and a random
	 *   unique ID prefixed by the deletion date/time stamp e.g. "20180420-103200-dfawey2h24t2tnlwhfwngym0024245. Remove
	 *   the IP, country and user agent information from these records. Replace the notes with "This record has been
	 *   pseudonymized per GDPR requirements".
	 * - Remove all user information.
	 * - Remove all invoice and credit note information.
	 *
	 * @param   int    $userID The user ID we are asked to delete
	 * @param   string $type   The export type (user, admin, lifecycle)
	 *
	 * @return  array
	 */
	public function onDataComplianceDeleteUser(int $userID, string $type): array
	{
		$ret = [
			'akeebasubs' => [
				'subscriptions_deleted' => [],
				'subscriptions_anonymized' => [],
				'invoices'      => [],
				'creditnotes'   => [],
				'users'         => [],
			],
		];

		/**
		 * Remove invoices and credit notes.
		 *
		 * IMPORTANT! DO NOT CHANGE THE ORDER OF OPERATIONS.
		 *
		 * Credit notes are keyed to invoices. Invoices are keyed to subscriptions. Therefore we need to remove CN
		 * before invoices and only then can we remove subscriptions.
		 *
		 */
		$container = Container::getInstance('com_akeebasubs', [], 'admin');

		/** @var Subscriptions $subs */
		$subs = $container->factory->model('Subscriptions')->tmpInstance();
		$subs->user_id($userID);

		/** @var Subscriptions $sub */
		foreach ($subs->getGenerator(0, 0, true) as $sub)
		{
			if (empty($sub))
			{
				continue;
			}

			// Delete credit notes and invoices
			$invoice = $sub->invoice;

			if (!empty($invoice))
			{
				$creditNote = $invoice->creditNote;

				if (!empty($creditNote))
				{
					$ret['akeebasubs']['creditnotes'][] = $creditNote->display_number;
					$creditNote->delete();
				}

				$ret['akeebasubs']['invoices'][] = $invoice->display_number;
				$invoice->delete();

			}

			// Remove all subscriptions with paystate "N" (failed transactions).
			if ($sub->paystate == 'N')
			{
				$ret['akeebasubs']['subscriptions_deleted'][] = $sub->getId();
				$sub->delete();

				continue;
			}

			// Anonymize the subscription if its payment state is other than "N".
			$ret['akeebasubs']['subscriptions_anonymized'][] = $sub->getId();

			$sub->save([
				'processor'     => 'DATA_COMPLIANCE_WIPED',
				'processor_key' => gmdate('Ymd-His') . '-' . \Joomla\CMS\User\UserHelper::genRandomPassword('24'),
				'ip'            => '',
				'ua'            => '',
				'notes'         => 'This record has been pseudonymized per GDPR requirements',
			]);
		}

		// Remove user information
		$ret['akeebasubs']['users'] = $this->anonymizeUser($userID);


		return $ret;
	}


	/**
	 * Used for exporting the user information in XML format. The returned data is a SimpleXMLElement document with a
	 * data dump following the structure root > domain > item[...] > column[...].
	 *
	 * This plugin exports the following tables / models:
	 * - Tickets
	 * - Posts
	 * - Attachments
	 *
	 * @param $userID
	 *
	 * @return SimpleXMLElement
	 */
	public function onDataComplianceExportUser(int $userID): SimpleXMLElement
	{
		$export    = new SimpleXMLElement("<root></root>");
		$container = Container::getInstance('com_akeebasubs');

		// Subscriptions
		$domainSubs = $export->addChild('domain');
		$domainSubs->addAttribute('name', 'akeebasubs_subscriptions');
		$domainSubs->addAttribute('description', 'Akeeba Subscriptions transactions (subscriptions)');

		// Invoices
		$domainInvoices = $export->addChild('domain');
		$domainInvoices->addAttribute('name', 'akeebasubs_invoices');
		$domainInvoices->addAttribute('description', 'Akeeba Subscriptions invoices');

		// Credit Notes
		$domainCreditNotes = $export->addChild('domain');
		$domainCreditNotes->addAttribute('name', 'akeebasubs_creditnotes');
		$domainCreditNotes->addAttribute('description', 'Akeeba Subscriptions credit notes');

		// User Information
		$domainUserInfo = $export->addChild('domain');
		$domainUserInfo->addAttribute('name', 'akeebasubs_users');
		$domainUserInfo->addAttribute('description', 'Akeeba Subscriptions invoicing information');

		/** @var Subscriptions $subsModel */
		$subsModel = $container->factory->model('Subscriptions')->tmpInstance();

		/** @var Subscriptions $sub */
		foreach ($subsModel->user_id($userID)->get(true) as $sub)
		{
			Export::adoptChild($domainSubs, Export::exportItemFromDataModel($sub));

			if (!empty($sub->invoice))
			{
				Export::adoptChild($domainInvoices, Export::exportItemFromDataModel($sub->invoice));
			}

			if (!empty($sub->invoice->creditNote))
			{
				Export::adoptChild($domainCreditNotes, Export::exportItemFromDataModel($sub->invoice->creditNote));
			}
		}

		/** @var \Akeeba\Subscriptions\Admin\Model\Users $user */
		$user = $container->factory->model('Users')->tmpInstance();

		try
		{
			$user->findOrFail(['user_id' => $userID]);

			Export::adoptChild($domainUserInfo, Export::exportItemFromDataModel($user));
		}
		catch (Exception $e)
		{
			// Sometimes we just don't have a record with invoicing information
		}

		return $export;
	}

	/**
	 * Replace the user's personal information with dummy data
	 *
	 * @param   int  $user_id  The user ID we are pseudonymizing
	 *
	 * @return  array  The user ID we pseudonymized
	 */
	private function anonymizeUser($user_id)
	{
		$container = Container::getInstance('com_akeebasubs', [], 'admin');

		/** @var \Akeeba\Subscriptions\Admin\Model\Users $user */
		$user = $container->factory->model('Users')->tmpInstance();

		try
		{
			$user->findOrFail(['user_id' => $user_id]);

			$user->save([
				'isbusiness'     => 0,
				'businessname'   => '',
				'occupation'     => '',
				'vatnumber'      => '',
				'viesregistered' => 0,
				'taxauthority'   => '',
				'address1'       => 'Address Redacted',
				'address2'       => '',
				'city'           => 'City Redacted',
				'state'          => '',
				'zip'            => 'REMOVED',
				'country'        => 'XX',
				'params'         => [],
				'notes'          => 'This record has been pseudonymized per GDPR requirements',
				'needs_logout'   => 0,
			]);
		}
		catch (Exception $e)
		{
			return [];
		}

		return [$user_id];
	}
}