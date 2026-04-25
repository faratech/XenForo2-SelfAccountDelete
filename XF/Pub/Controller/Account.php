<?php

namespace LiamW\AccountDelete\XF\Pub\Controller;


use XF;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class Account extends XFCP_Account
{
	public function actionDelete()
	{
		/** @var \LiamW\AccountDelete\XF\Entity\User $visitor */
		$visitor = XF::visitor();
		if ($visitor->PendingAccountDeletion)
		{
			return $this->view('LiamW\AccountDelete:AccountDelete\Pending', 'liamw_accountdelete_pending');
		}

		if (!$visitor->canDeleteSelf($error))
		{
			return $this->noPermission($error);
		}

		$this->assertAccountDeletePasswordVerified();

		if ($this->isPost())
		{
			$confirmation = $this->filter('confirmation', 'bool');

			if (!$confirmation)
			{
				return $this->error(XF::phrase('liamw_accountdelete_please_confirm_deletion_by_checking_the_checkbox'));
			}

			if (XF::options()->liamw_accountdelete_reason['request'])
			{
				if (!$this->filter('reason_requested', 'bool'))
				{
					return $this->view('LiamW\AccountDelete:AccountDelete\Reason', 'liamw_accountdelete_reason_form');
				}

				if (XF::options()->liamw_accountdelete_reason['require'] && !$this->filter('reason', '?str'))
				{
					return $this->error(XF::phrase('liamw_accountdelete_please_enter_reason_for_deleting_your_account'));
				}
			}

			$scheduleService = $this->setupAccountDeleteSchedule($visitor);
			if (!$scheduleService->validate($errors))
			{
				return $this->error($errors);
			}

			$scheduleService->save();
			$this->finalizeAccountDeleteSchedule();

			/** @var XF\ControllerPlugin\Login $loginPlugin */
			$loginPlugin = $this->plugin('XF:Login');
			$loginPlugin->logoutVisitor();

			return $this->redirect($this->buildLink('index'), XF::phrase('liamw_accountdelete_account_deletion_scheduled'));
		}
		else
		{
			return $this->addAccountWrapperParams($this->view('LiamW\AccountDelete:AccountDelete\Confirm', 'liamw_accountdelete_form'), 'liamw_accountdelete_delete_account');
		}
	}

	protected function setupAccountDeleteSchedule(\XF\Entity\User $user)
	{
		/** @var \LiamW\AccountDelete\Service\Schedule $scheduleService */
		$scheduleService = $this->service('LiamW\AccountDelete:Schedule', $user);

		$reason = $this->filter('reason', 'str');
		$scheduleService->setReason($reason);

		$forumId = $this->options()->liamw_accountdelete_thread_forum;
		if ($forumId)
		{
			/** @var \XF\Entity\Forum $forum */
			$forum = $this->em()->find('XF:Forum', $forumId);
			$scheduleService->sendReportIntoForum($forum);
		}

		return $scheduleService;
	}

	protected function finalizeAccountDeleteSchedule()
	{
	}

	public function actionDeleteCancel()
	{
		/** @var \LiamW\AccountDelete\XF\Entity\User $visitor */
		$visitor = XF::visitor();
		if (!$visitor->PendingAccountDeletion)
		{
			return $this->error(XF::phrase('liamw_accountdelete_account_deletion_not_scheduled'));
		}

		if ($visitor->is_banned)
		{
			return $this->noPermission();
		}

		$cancelService = $this->setupAccountDeleteCancel($visitor->PendingAccountDeletion);
		if (!$cancelService->validate($errors))
		{
			return $this->error($errors);
		}

		$cancelService->save();

		return $this->redirect($this->buildLink('index'), XF::phrase('liamw_accountdelete_account_deletion_cancelled'));
	}

	/**
	 * @param \LiamW\AccountDelete\Entity\AccountDelete $accountDeletion
	 * @return XF\Service\AbstractService|\LiamW\AccountDelete\Service\Cancel
	 */
	protected function setupAccountDeleteCancel(\LiamW\AccountDelete\Entity\AccountDelete $accountDeletion)
	{
		return $this->service('LiamW\AccountDelete:Cancel', $accountDeletion);
	}

	protected function canUpdateSessionActivity($action, ParameterBag $params, AbstractReply &$reply, &$viewState)
	{
		/** @var \LiamW\AccountDelete\XF\Entity\User $visitor */
		$visitor = XF::visitor();
		if ($visitor->PendingAccountDeletion)
		{
			return false;
		}

		return parent::canUpdateSessionActivity($action, $params, $reply, $viewState);
	}

	protected function assertAccountDeletePasswordVerified()
	{
		$this->assertPasswordVerified(300, null, function ($view)
		{
			return $this->addAccountWrapperParams($view, 'liamw_accountdelete_delete_account');
		});
	}
}