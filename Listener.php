<?php

namespace LiamW\AccountDelete;

use XF;
use XF\Mvc\Controller;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Manager;
use XF\Mvc\Entity\Structure;
use XF\Mvc\ParameterBag;

class Listener
{
	public static function optionControllerPreDispatch(Controller $controller, $action, ParameterBag $params)
	{
		if ($controller->isPost() && $action == 'Update'
			&& in_array('liamw_accountdelete_user_criteria', $controller->filter('options_listed', 'array-str')))
		{
			$request = $controller->request();

			$request->set('options.liamw_accountdelete_user_criteria', $request->filter('user_criteria', 'array'));
			$request->set('user_criteria', null);
		}
	}

	/**
	 * Called at the end of the preDispatch() method of the main Controller object.
	 *
	 * Event hint: Fully qualified name of the root class that was called.
	 *
	 * @param \XF\Mvc\Controller $controller Main controller object.
	 * @param $action Current controller action.
	 * @param \XF\Mvc\ParameterBag $params ParameterBag object containing router related params.
	 *
	 * @noinspection PhpUnusedParameterInspection
	 */
	public static function xf22OptionControllerPreDispatch(Controller $controller, $action, ParameterBag $params)
	{
		if ($controller->isPost() && $action == 'Update'
			&& in_array('liamw_accountdelete_user_criteria', $controller->filter('options_listed', 'array-str')))
		{
			$request = $controller->request();

			$request->set('options.liamw_accountdelete_user_criteria', $request->filter('user_criteria', 'array'));
			$request->set('user_criteria', null);
		}
	}

	public static function optionFormBlockMacroPreRender(
		XF\Template\Templater $templater,
		&$type,
		&$template,
		&$name,
		array &$arguments,
		array &$globalVars
	) {
		if ($arguments['group'] && $arguments['group']->group_id == 'liamw_memberselfdelete')
		{
			$template = 'liamw_accountdelete_option_macros';
			$userCriteria = XF::app()
				->criteria('XF:User', $arguments['options']['liamw_accountdelete_user_criteria']->option_value);
			$arguments['userCriteria'] = $userCriteria;
		}
	}

	public static function controllerPreDispatch(Controller $controller, $action, ParameterBag $params)
	{
		if ($controller->app() instanceof XF\Pub\App)
		{
			/** @var \LiamW\AccountDelete\XF\Entity\User $visitor */
			$visitor = XF::visitor();
			if ($visitor->PendingAccountDeletion
				&& !($controller instanceof XF\Pub\Controller\Logout)
				&& !($controller instanceof XF\Pub\Controller\Account && ($action == 'Delete' || $action == 'DeleteCancel'))
				&& !$controller->isPost()
				&& !$controller->request()->isXhr())
			{
				if ($controller->request()->getRoutePath() != '')
				{
					$reply = $controller->redirect($controller->buildLink('index'));
				}
				else
				{
					$reply = $controller->rerouteController('XF\Pub\Controller\Account', 'Delete');
				}

				throw $controller->exception($reply);
			}
		}
	}


	public static function optionEntityPostSave(Entity $entity)
	{
		$valueChanged = $entity->isChanged('option_value');

		XF::runLater(function () use ($valueChanged, $entity) {
			/** @var XF\Entity\Option $entity */
			if ($valueChanged)
			{
				$jobManager = XF::app()->jobManager();

				/** @var \LiamW\AccountDelete\Repository\AccountDelete $accountDeleteRepo */
				$accountDeleteRepo = XF::repository('LiamW\AccountDelete:AccountDelete');

				if ($entity->option_id == 'liamw_accountdelete_deletion_delay')
				{
					$nextDeletionTime = $accountDeleteRepo->getNextDeletionTime($entity->option_value);
					if ($nextDeletionTime)
					{
						$jobManager->enqueueLater(
							'lwAccountDeleteRunner',
							$nextDeletionTime,
							'LiamW\AccountDelete:DeleteAccounts'
						);
					}

					$nextRemindTime = $accountDeleteRepo->getNextRemindTime($entity->option_value);
					if ($nextRemindTime)
					{
						$jobManager->enqueueLater(
							'lwAccountDeleteReminder',
							$nextRemindTime,
							'LiamW\AccountDelete:SendDeleteReminders'
						);
					}
				}
				else if ($entity->option_id == 'liamw_accountdelete_reminder_threshold')
				{
					if ($entity->option_value)
					{
						$nextRemindTime = $accountDeleteRepo->getNextRemindTime(null, $entity->option_value);
						if ($nextRemindTime)
						{
							$jobManager->enqueueLater(
								'lwAccountDeleteReminder',
								$nextRemindTime,
								'LiamW\AccountDelete:SendDeleteReminders'
							);
						}
					}
					else
					{
						$jobManager->cancelUniqueJob('lwAccountDeleteReminder');
					}
				}
			}
		});
	}


	public static function userEntityPostDelete(Entity $entity)
	{
		/** @var \LiamW\AccountDelete\XF\Entity\User $entity */
		if ($entity->getOption('liamw_accountdelete_log_manual') === true && $entity->PendingAccountDeletion)
		{
			$entity->PendingAccountDeletion->status = 'complete_manual';
			$entity->PendingAccountDeletion->completion_date = XF::$time;
			$entity->PendingAccountDeletion->save();
		}
	}


	public static function userEntityPostSave(Entity $entity)
	{
		/** @var \LiamW\AccountDelete\XF\Entity\User $entity */
		if ($entity->getOption('liamw_accountdelete_log_manual') === true
			&& $entity->PendingAccountDeletion
			&& $entity->isStateChanged('user_state', 'disabled') == 'enter')
		{
			$entity->PendingAccountDeletion->status = 'complete_manual';
			$entity->PendingAccountDeletion->completion_date = XF::$time;
			$entity->PendingAccountDeletion->save();
		}
	}


	public static function userEntityStructure(Manager $em, Structure &$structure)
	{
		$structure->relations['AccountDeletionLogs'] = [
			'entity' => 'LiamW\AccountDelete:AccountDelete',
			'type' => Entity::TO_MANY,
			'conditions' => 'user_id'
		];

		$structure->relations['PendingAccountDeletion'] = [
			'entity' => 'LiamW\AccountDelete:AccountDelete',
			'type' => Entity::TO_ONE,
			'conditions' => ['user_id', ['status', '=', 'pending']]
		];

		$structure->options['liamw_accountdelete_log_manual'] = true;
	}


	public static function visitorExtraWith(array &$with)
	{
		if (XF::app() instanceof XF\Pub\App)
		{
			$with[] = 'PendingAccountDeletion';
		}
	}


}
